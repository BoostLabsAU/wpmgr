package agentcmd

// email_contract.go — CP->agent command contract for per-site email management
// (m59, Phase 1 foundation). The wp-agent-engineer mirrors these shapes in the
// agent's command handlers when implementing Phase 2.
//
// Wire commands (POST {site_url}/wp-json/wpmgr/v1/command/{cmd},
// Authorization: Bearer <minted EdDSA JWT>, aud=<siteId>):
//
//   sync_email_config  — push the full per-site email config (provider, from
//                        address, connection details) including the DECRYPTED
//                        provider secret so the agent can store it in its own
//                        keystore. The CP decrypts the age ciphertext in-memory;
//                        the plaintext secret travels in the signed JWT-protected
//                        body (HTTPS + Ed25519 signature). The agent stores the
//                        secret in its local WP options table encrypted with its
//                        own key. Response: {ok, detail}.
//
//   send_test_email    — ask the agent to send a test email using its current
//                        email config (sync_email_config MUST be called first).
//                        Response: {ok, detail, message_id?}.
//
// Phase 2 (wp-agent-engineer) MUST implement both command handlers.

// EmailConfigRequest is the POST body for `sync_email_config`.
// It carries the full per-site email config including the DECRYPTED provider
// secret — the signing + HTTPS transport is the security boundary.
type EmailConfigRequest struct {
	// Provider is the provider slug: smtp | ses | sendgrid | mailgun | postmark.
	Provider string `json:"provider"`

	// FromAddress is the From: email address.
	FromAddress string `json:"from_address"`
	// FromName is the From: display name.
	FromName string `json:"from_name"`
	// ForceFromEmail when true overrides WP's generated From address.
	ForceFromEmail bool `json:"force_from_email"`
	// ForceFromName when true overrides WP's generated display name.
	ForceFromName bool `json:"force_from_name"`
	// ReturnPath when true sets the Return-Path / bounce address.
	ReturnPath bool `json:"return_path"`

	// Config holds non-secret provider settings. The shape depends on the
	// provider (see catalog.go for field definitions):
	//   smtp:      host, port, encryption, auth, username, auto_tls
	//   ses:       access_key, region, return_path
	//   sendgrid:  (none — secret is the sole configuration)
	//   mailgun:   domain_name, region
	//   postmark:  message_stream, track_opens, track_links
	Config map[string]any `json:"config"`

	// Secret is the DECRYPTED provider secret (SMTP password / API key / AWS
	// secret access key). Empty string means "no secret configured"; the agent
	// should remove any previously stored secret from its keystore.
	// SECURITY: this field travels in the signed JWT-protected body over HTTPS.
	// The CP decrypts from age ciphertext in-memory and never logs this value.
	Secret string `json:"secret"`

	// Mappings is a JSON object mapping From-email addresses to connection keys
	// for per-sender routing (Phase 2 multi-connection feature).
	Mappings map[string]any `json:"mappings,omitempty"`

	// LogEmails when true the agent buffers each send to its local WP table.
	LogEmails bool `json:"log_emails"`
	// StoreBody when true the agent includes the full message body in the log.
	StoreBody bool `json:"store_body"`
	// RetentionDays is the maximum age (in days) of log entries the agent keeps.
	RetentionDays int `json:"retention_days"`
}

// EmailConfigResult is the response body for `sync_email_config`.
type EmailConfigResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`
}

// SendTestEmailRequest is the POST body for `send_test_email`.
type SendTestEmailRequest struct {
	// To is the recipient address for the test message. Required.
	To string `json:"to"`
	// Subject is the email subject line (defaults to "Test Email from WPMgr" on
	// the agent if empty).
	Subject string `json:"subject,omitempty"`
	// Body is the plain-text email body (defaults to a stock message if empty).
	Body string `json:"body,omitempty"`
}

// SendTestEmailResult is the response body for `send_test_email`.
type SendTestEmailResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`
	// MessageID is the provider-returned Message-ID header value (if available).
	MessageID string `json:"message_id,omitempty"`
}
