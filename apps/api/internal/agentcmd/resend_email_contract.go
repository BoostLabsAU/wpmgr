package agentcmd

// resend_email_contract.go — CP->agent command contract for resending a stored
// email from the CP-held email log body (Phase 4a definition; Phase 4b implementation).
//
// The CP looks up the site_email_log row identified by log_id (UUID), reads the
// stored body (requires body_stored=true, enforced by the service gate), and
// dispatches the signed `resend_email` command to the agent. The agent re-sends
// using its currently configured email provider (same config as the original send)
// and returns the new provider Message-ID.
//
// The agent MUST implement the `resend_email` command handler in Phase 4b.
// Until then, the CP stub returns ok=false with a descriptive detail.

// ResendEmailRequest is the POST body for the `resend_email` agent command.
type ResendEmailRequest struct {
	// LogID is the CP-side site_email_log.id (UUID string). The agent uses it
	// as a correlation identifier for the resend attempt — it does not need to
	// query the CP to retrieve the body; the full message body is embedded in
	// this request so the agent can re-send without a second round-trip.
	LogID string `json:"log_id"`

	// ToAddresses is the recipient list for the resend. Required.
	// The CP copies the original log entry's to_addresses.
	ToAddresses []string `json:"to_addresses"`

	// FromAddress is the From: header value. Required.
	FromAddress string `json:"from_address"`

	// FromName is the From: display name. May be empty.
	FromName string `json:"from_name,omitempty"`

	// Subject is the email subject line. Required.
	Subject string `json:"subject"`

	// Body is the full email body (plain text or HTML, matching what was captured
	// at the original send time). The CP only sends this when body_stored=true.
	Body string `json:"body"`

	// Provider is the provider slug used for the original send (smtp, ses,
	// sendgrid, mailgun, postmark). The agent uses its currently configured
	// provider; this field is informational for logging.
	Provider string `json:"provider,omitempty"`
}

// ResendEmailResult is the response body for the `resend_email` agent command.
type ResendEmailResult struct {
	OK     bool   `json:"ok"`
	Detail string `json:"detail,omitempty"`
	// MessageID is the provider-returned Message-ID header value for the new send.
	MessageID string `json:"message_id,omitempty"`
}
