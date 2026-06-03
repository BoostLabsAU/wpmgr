package perf

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
)

// httpDoer is the subset of the SSRF-hardened client the CDN purger needs.
// *httpclient.Client satisfies it; tests substitute a fake.
type httpDoer interface {
	Do(req *http.Request) (*http.Response, error)
}

// CloudflareBunnyKeyCDNPurger purges a CDN edge cache over the SSRF-hardened
// outbound client. It supports:
//   - Cloudflare: purge by Cache-Tag when urls are empty (whole-zone via the
//     site host tag), or purge_everything when scope=all, or by files when urls
//     are listed (zone API).
//   - Bunny / KeyCDN: purge by absolute URL path.
//
// All purges are best-effort: a non-2xx is returned as an error which the
// service logs and swallows. The CDN host comes from the provider's well-known
// API endpoint, NOT from attacker input — but the client is SSRF-hardened anyway.
type CloudflareBunnyKeyCDNPurger struct {
	http httpDoer
}

// NewCDNPurger builds a purger around the SSRF-hardened client.
func NewCDNPurger(client httpDoer) *CloudflareBunnyKeyCDNPurger {
	return &CloudflareBunnyKeyCDNPurger{http: client}
}

// maxCDNRespBody bounds the API response body we read.
const maxCDNRespBody = 256 << 10 // 256 KiB

// Purge dispatches to the provider-specific purge. urls empty ⇒ purge
// everything; urls non-empty ⇒ purge those paths only.
func (p *CloudflareBunnyKeyCDNPurger) Purge(ctx context.Context, creds CDNCredentials, siteURL string, urls []string) error {
	switch strings.ToLower(creds.Provider) {
	case "cloudflare":
		return p.purgeCloudflare(ctx, creds, siteURL, urls)
	case "bunny":
		return p.purgeBunny(ctx, creds, siteURL, urls)
	case "keycdn":
		return p.purgeKeyCDN(ctx, creds, urls)
	default:
		return fmt.Errorf("unsupported cdn provider %q", creds.Provider)
	}
}

// purgeCloudflare calls the zone purge_cache API. With urls it purges by files;
// without urls it purges_everything for the zone (cheapest correct whole-cache).
func (p *CloudflareBunnyKeyCDNPurger) purgeCloudflare(ctx context.Context, creds CDNCredentials, siteURL string, urls []string) error {
	if creds.ZoneID == "" || creds.APIToken == "" {
		return fmt.Errorf("cloudflare purge: missing zone_id or api_token")
	}
	endpoint := fmt.Sprintf("https://api.cloudflare.com/client/v4/zones/%s/purge_cache", url.PathEscape(creds.ZoneID))
	var payload map[string]any
	if len(urls) == 0 {
		payload = map[string]any{"purge_everything": true}
	} else {
		payload = map[string]any{"files": absolutize(siteURL, urls)}
	}
	body, _ := json.Marshal(payload)
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+creds.APIToken)
	req.Header.Set("Content-Type", "application/json")
	return p.do(req, "cloudflare")
}

// purgeBunny calls the Bunny pull-zone purge API. Bunny purges by absolute URL;
// with no urls it purges the whole zone via the per-zone purgeCache endpoint.
func (p *CloudflareBunnyKeyCDNPurger) purgeBunny(ctx context.Context, creds CDNCredentials, siteURL string, urls []string) error {
	if creds.APIToken == "" {
		return fmt.Errorf("bunny purge: missing api_token (AccessKey)")
	}
	if len(urls) == 0 {
		if creds.Zone == "" {
			return fmt.Errorf("bunny purge: missing zone for whole-zone purge")
		}
		endpoint := fmt.Sprintf("https://api.bunny.net/pullzone/%s/purgeCache", url.PathEscape(creds.Zone))
		req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, nil)
		if err != nil {
			return err
		}
		req.Header.Set("AccessKey", creds.APIToken)
		return p.do(req, "bunny")
	}
	for _, u := range absolutize(siteURL, urls) {
		endpoint := "https://api.bunny.net/purge?url=" + url.QueryEscape(u)
		req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, nil)
		if err != nil {
			return err
		}
		req.Header.Set("AccessKey", creds.APIToken)
		if derr := p.do(req, "bunny"); derr != nil {
			return derr
		}
	}
	return nil
}

// purgeKeyCDN calls the KeyCDN zone purge API by URL.
func (p *CloudflareBunnyKeyCDNPurger) purgeKeyCDN(ctx context.Context, creds CDNCredentials, urls []string) error {
	if creds.APIToken == "" || creds.Zone == "" {
		return fmt.Errorf("keycdn purge: missing api_token or zone")
	}
	var endpoint string
	var body io.Reader
	if len(urls) == 0 {
		endpoint = fmt.Sprintf("https://api.keycdn.com/zones/purge/%s.json", url.PathEscape(creds.Zone))
	} else {
		endpoint = fmt.Sprintf("https://api.keycdn.com/zones/purgeurl/%s.json", url.PathEscape(creds.Zone))
		payload, _ := json.Marshal(map[string]any{"urls": urls})
		body = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, endpoint, body)
	if err != nil {
		return err
	}
	// KeyCDN uses HTTP basic auth with the API key as the username.
	req.SetBasicAuth(creds.APIToken, "")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	return p.do(req, "keycdn")
}

func (p *CloudflareBunnyKeyCDNPurger) do(req *http.Request, provider string) error {
	resp, err := p.http.Do(req)
	if err != nil {
		return fmt.Errorf("%s purge transport: %w", provider, err)
	}
	defer func() { _, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, maxCDNRespBody)); _ = resp.Body.Close() }()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		snippet, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("%s purge rejected: status %d body=%s", provider, resp.StatusCode, string(snippet))
	}
	return nil
}

// absolutize turns relative paths into absolute URLs against siteURL; absolute
// http(s) urls pass through unchanged.
func absolutize(siteURL string, urls []string) []string {
	base, err := url.Parse(siteURL)
	out := make([]string, 0, len(urls))
	for _, u := range urls {
		if strings.HasPrefix(u, "http://") || strings.HasPrefix(u, "https://") {
			out = append(out, u)
			continue
		}
		if err != nil || base == nil {
			out = append(out, u)
			continue
		}
		ref, perr := url.Parse(u)
		if perr != nil {
			out = append(out, u)
			continue
		}
		out = append(out, base.ResolveReference(ref).String())
	}
	return out
}
