package email

// webhook_verify.go — per-provider webhook signature verification.
//
// Security boundary: every incoming webhook must pass provider-specific
// cryptographic verification BEFORE any processing. Failures abort with a
// 401/400; no DB writes occur before a successful verify.
//
// Provider summary:
//   SES (via SNS): X.509 cert-pinned RSA-SHA1/SHA256 message signature +
//                  SNS SubscriptionConfirmation URL-pinned handshake.
//   SendGrid:      ECDSA-P256 (X-Twilio-Email-Event-Webhook-Signature) over
//                  timestamp+rawbody (SHA-256 digest).
//   Mailgun:       HMAC-SHA256 over (timestamp + token) using the dedicated
//                  webhook signing key (NOT the Private API Key).
//   Postmark:      Per-server secret in URL path or HTTP Basic username;
//                  constant-time compare.

import (
	"crypto/ecdsa"
	"crypto/hmac"
	"crypto/sha256"
	"crypto/subtle"
	"crypto/tls"
	"crypto/x509"
	"encoding/asn1"
	"encoding/base64"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

// ---------------------------------------------------------------------------
// Shared replay-window constant
// ---------------------------------------------------------------------------

// webhookReplayWindow is the maximum age of a timestamp-bearing webhook event
// that the CP accepts. Older events are treated as replays/stale.
// 5 minutes is the industry-standard choice (SNS, Mailgun, SendGrid docs).
const webhookReplayWindow = 5 * time.Minute

// ---------------------------------------------------------------------------
// SNS / SES verification
// ---------------------------------------------------------------------------

// snsMessage holds the SNS notification or SubscriptionConfirmation fields
// needed for verification and event parsing.
type snsMessage struct {
	Type             string `json:"Type"`
	MessageID        string `json:"MessageId"`
	TopicArn         string `json:"TopicArn"`
	Subject          string `json:"Subject,omitempty"`
	Message          string `json:"Message"`
	Timestamp        string `json:"Timestamp"`
	SignatureVersion string `json:"SignatureVersion"`
	Signature        string `json:"Signature"`
	SigningCertURL   string `json:"SigningCertURL"`
	// SubscriptionConfirmation fields.
	SubscribeURL string `json:"SubscribeURL,omitempty"`
	Token        string `json:"Token,omitempty"`
	// Notification-only.
	UnsubscribeURL string `json:"UnsubscribeURL,omitempty"`
}

// snsCertCache caches fetched PEM certificates by URL to avoid repeated HTTPS
// fetches for every notification. The mutex serialises concurrent initial fetches.
var snsCertCache struct {
	mu    sync.Mutex
	cache map[string]*x509.Certificate
}

func init() {
	snsCertCache.cache = make(map[string]*x509.Certificate)
}

// snsAllowedCertHost returns true when rawURL is an https:// URL on an
// official amazonaws.com (or .cn variant) host. This pins SigningCertURL to
// real AWS infrastructure and blocks SSRF-style cert-substitution attacks.
func snsAllowedCertHost(rawURL string) bool {
	u, err := url.Parse(rawURL)
	if err != nil {
		return false
	}
	if u.Scheme != "https" {
		return false
	}
	host := strings.ToLower(u.Hostname())
	return strings.HasSuffix(host, ".amazonaws.com") ||
		strings.HasSuffix(host, ".amazonaws.com.cn")
}

// snsAllowedSubscribeURL returns true when rawURL is an https:// URL on an
// official SNS endpoint. This blocks "SNS subscription confirmation SSRF"
// (a documented exploit class where a malicious SNS message contains a
// SubscribeURL pointing at an internal service).
func snsAllowedSubscribeURL(rawURL string) bool {
	u, err := url.Parse(rawURL)
	if err != nil {
		return false
	}
	if u.Scheme != "https" {
		return false
	}
	host := strings.ToLower(u.Hostname())
	return strings.HasSuffix(host, ".amazonaws.com") ||
		strings.HasSuffix(host, ".amazonaws.com.cn")
}

// fetchSNSCert fetches and caches the X.509 certificate at SigningCertURL.
// Returns an error if the URL is not an allowed amazonaws.com URL.
func fetchSNSCert(certURL string, client *http.Client) (*x509.Certificate, error) {
	if !snsAllowedCertHost(certURL) {
		return nil, fmt.Errorf("sns: SigningCertURL host not allowed: %q", certURL)
	}

	snsCertCache.mu.Lock()
	if cert, ok := snsCertCache.cache[certURL]; ok {
		snsCertCache.mu.Unlock()
		return cert, nil
	}
	snsCertCache.mu.Unlock()

	resp, err := client.Get(certURL) //nolint:noctx // cert fetch; short timeout on client
	if err != nil {
		return nil, fmt.Errorf("sns: fetch cert: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("sns: fetch cert HTTP %d", resp.StatusCode)
	}
	pemBytes, err := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
	if err != nil {
		return nil, fmt.Errorf("sns: read cert body: %w", err)
	}
	block, _ := pem.Decode(pemBytes)
	if block == nil {
		return nil, errors.New("sns: cert PEM decode failed")
	}
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return nil, fmt.Errorf("sns: parse cert: %w", err)
	}

	snsCertCache.mu.Lock()
	snsCertCache.cache[certURL] = cert
	snsCertCache.mu.Unlock()

	return cert, nil
}

// snsSignatureMessage builds the canonical signing string for an SNS message.
// The field order and inclusion rules follow the AWS SNS documentation
// "Verify message signatures" section exactly.
func snsSignatureMessage(msg snsMessage) string {
	var b strings.Builder
	switch msg.Type {
	case "Notification":
		b.WriteString("Message\n")
		b.WriteString(msg.Message)
		b.WriteString("\n")
		b.WriteString("MessageId\n")
		b.WriteString(msg.MessageID)
		b.WriteString("\n")
		if msg.Subject != "" {
			b.WriteString("Subject\n")
			b.WriteString(msg.Subject)
			b.WriteString("\n")
		}
		b.WriteString("Timestamp\n")
		b.WriteString(msg.Timestamp)
		b.WriteString("\n")
		b.WriteString("TopicArn\n")
		b.WriteString(msg.TopicArn)
		b.WriteString("\n")
		b.WriteString("Type\n")
		b.WriteString(msg.Type)
		b.WriteString("\n")
	case "SubscriptionConfirmation", "UnsubscribeConfirmation":
		b.WriteString("Message\n")
		b.WriteString(msg.Message)
		b.WriteString("\n")
		b.WriteString("MessageId\n")
		b.WriteString(msg.MessageID)
		b.WriteString("\n")
		b.WriteString("SubscribeURL\n")
		b.WriteString(msg.SubscribeURL)
		b.WriteString("\n")
		b.WriteString("Timestamp\n")
		b.WriteString(msg.Timestamp)
		b.WriteString("\n")
		b.WriteString("Token\n")
		b.WriteString(msg.Token)
		b.WriteString("\n")
		b.WriteString("TopicArn\n")
		b.WriteString(msg.TopicArn)
		b.WriteString("\n")
		b.WriteString("Type\n")
		b.WriteString(msg.Type)
		b.WriteString("\n")
	}
	return b.String()
}

// verifySNSMessage parses the raw SNS body, fetches + pins the signing cert,
// and verifies the RSA signature. Returns the parsed snsMessage on success.
// httpClient must be a plain net/http.Client; all URLs are validated before use.
func verifySNSMessage(body []byte, httpClient *http.Client) (snsMessage, error) {
	var msg snsMessage
	if err := json.Unmarshal(body, &msg); err != nil {
		return snsMessage{}, fmt.Errorf("sns: unmarshal: %w", err)
	}

	cert, err := fetchSNSCert(msg.SigningCertURL, httpClient)
	if err != nil {
		return snsMessage{}, err
	}

	sigBytes, err := base64.StdEncoding.DecodeString(msg.Signature)
	if err != nil {
		return snsMessage{}, fmt.Errorf("sns: base64 decode signature: %w", err)
	}

	canonical := snsSignatureMessage(msg)

	switch msg.SignatureVersion {
	case "1": // RSA-SHA1
		if err := cert.CheckSignature(x509.SHA1WithRSA, []byte(canonical), sigBytes); err != nil {
			return snsMessage{}, fmt.Errorf("sns: signature verification failed (SHA1): %w", err)
		}
	case "2": // RSA-SHA256
		if err := cert.CheckSignature(x509.SHA256WithRSA, []byte(canonical), sigBytes); err != nil {
			return snsMessage{}, fmt.Errorf("sns: signature verification failed (SHA256): %w", err)
		}
	default:
		return snsMessage{}, fmt.Errorf("sns: unknown SignatureVersion %q", msg.SignatureVersion)
	}

	return msg, nil
}

// snsConfirmSubscription GETs the SubscribeURL to complete the SNS handshake.
// The URL must pass snsAllowedSubscribeURL before the request is issued.
func snsConfirmSubscription(subscribeURL string, httpClient *http.Client) error {
	if !snsAllowedSubscribeURL(subscribeURL) {
		return fmt.Errorf("sns: SubscribeURL host not allowed: %q", subscribeURL)
	}
	resp, err := httpClient.Get(subscribeURL) //nolint:noctx
	if err != nil {
		return fmt.Errorf("sns: SubscriptionConfirmation GET: %w", err)
	}
	defer resp.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4<<10))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("sns: SubscriptionConfirmation GET returned HTTP %d", resp.StatusCode)
	}
	return nil
}

// defaultSNSHTTPClient is used ONLY for sns cert-fetch and subscription-confirm
// calls to amazonaws.com. TLS is enabled; hosts are pinned at application layer.
var defaultSNSHTTPClient = &http.Client{
	Timeout: 15 * time.Second,
	Transport: &http.Transport{
		TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12},
	},
}

// ---------------------------------------------------------------------------
// SendGrid ECDSA verification
// ---------------------------------------------------------------------------

// ecdsaSig is used to unmarshal the DER-encoded ECDSA signature from SendGrid.
type ecdsaSig struct {
	R, S *big.Int
}

// verifySendGridSignature verifies the X-Twilio-Email-Event-Webhook-Signature
// header against the raw request body using ECDSA-P256 + SHA-256.
//
// The signed payload is: timestampStr (raw bytes) + rawBody.
// SendGrid timestamps are Unix milliseconds as a string.
// Replay window: webhookReplayWindow (5 min).
//
// publicKeyPEM is the ECDSA public key from the SendGrid dashboard (full PEM).
func verifySendGridSignature(rawBody []byte, signatureB64, timestampStr, publicKeyPEM string) error {
	// Parse timestamp and apply replay window.
	var epochMs int64
	if _, err := fmt.Sscanf(timestampStr, "%d", &epochMs); err != nil {
		return fmt.Errorf("sendgrid: invalid timestamp %q: %w", timestampStr, err)
	}
	ts := time.UnixMilli(epochMs)
	if time.Since(ts) > webhookReplayWindow {
		return fmt.Errorf("sendgrid: timestamp too old (%s ago)", time.Since(ts).Round(time.Second))
	}

	// Decode the public key from PEM.
	block, _ := pem.Decode([]byte(publicKeyPEM))
	if block == nil {
		return errors.New("sendgrid: public key PEM decode failed")
	}
	pub, err := x509.ParsePKIXPublicKey(block.Bytes)
	if err != nil {
		return fmt.Errorf("sendgrid: parse public key: %w", err)
	}
	ecPub, ok := pub.(*ecdsa.PublicKey)
	if !ok {
		return errors.New("sendgrid: public key is not ECDSA")
	}

	// Decode the DER-encoded ECDSA signature.
	sigDER, err := base64.StdEncoding.DecodeString(signatureB64)
	if err != nil {
		return fmt.Errorf("sendgrid: base64 decode signature: %w", err)
	}
	var sig ecdsaSig
	if rest, aerr := asn1.Unmarshal(sigDER, &sig); aerr != nil || len(rest) != 0 {
		return fmt.Errorf("sendgrid: DER decode signature: %w", aerr)
	}

	// Hash the payload: timestamp (bytes) + rawBody.
	payload := make([]byte, 0, len(timestampStr)+len(rawBody))
	payload = append(payload, []byte(timestampStr)...)
	payload = append(payload, rawBody...)
	digest := sha256.Sum256(payload)

	if !ecdsa.Verify(ecPub, digest[:], sig.R, sig.S) {
		return errors.New("sendgrid: ECDSA signature verification failed")
	}
	return nil
}

// ---------------------------------------------------------------------------
// Mailgun HMAC-SHA256 verification
// ---------------------------------------------------------------------------

// verifyMailgunSignature verifies Mailgun's webhook HMAC-SHA256 signature.
//
// The MAC input is: timestamp + token (both as raw byte strings, concatenated).
// The key is the WEBHOOK SIGNING KEY from the Mailgun dashboard (Webhooks tab),
// NOT the Private API Key — confusing these is the most common Mailgun webhook bug.
//
// timestampStr is a Unix epoch (seconds) as a string.
// Replay check: reject events older than webhookReplayWindow.
// The caller must also deduplicate on token (each Mailgun token is single-use;
// the dedup table catches replay on that dimension).
func verifyMailgunSignature(timestampStr, token, signature, webhookSigningKey string) error {
	var epochSec int64
	if _, err := fmt.Sscanf(timestampStr, "%d", &epochSec); err != nil {
		return fmt.Errorf("mailgun: invalid timestamp %q: %w", timestampStr, err)
	}
	ts := time.Unix(epochSec, 0)
	if time.Since(ts) > webhookReplayWindow {
		return fmt.Errorf("mailgun: timestamp too old (%s ago)", time.Since(ts).Round(time.Second))
	}

	mac := hmac.New(sha256.New, []byte(webhookSigningKey))
	mac.Write([]byte(timestampStr))
	mac.Write([]byte(token))
	expected := fmt.Sprintf("%x", mac.Sum(nil))

	// Constant-time compare to prevent timing side-channels.
	if subtle.ConstantTimeCompare([]byte(expected), []byte(signature)) != 1 {
		return errors.New("mailgun: HMAC-SHA256 signature mismatch")
	}
	return nil
}

// ---------------------------------------------------------------------------
// Postmark verification
// ---------------------------------------------------------------------------

// verifyPostmarkSecret performs a constant-time compare of a Postmark webhook
// secret. Postmark has no HMAC mechanism; the credential is either embedded in
// the URL path segment (/webhooks/email/postmark/<secret>) or supplied as the
// HTTP Basic auth username. The secret is age-encrypted at rest in
// site_email_config.config["postmark_webhook_secret"].
func verifyPostmarkSecret(provided, expected string) error {
	if subtle.ConstantTimeCompare([]byte(provided), []byte(expected)) != 1 {
		return errors.New("postmark: webhook secret mismatch")
	}
	return nil
}
