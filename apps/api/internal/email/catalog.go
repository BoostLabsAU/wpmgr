package email

// ProviderField describes one configuration field in a provider's schema.
// IsSecret marks fields whose values must be stored encrypted and never returned
// in reads (only a *_set bool is surfaced). IsRequired marks fields that must be
// present for the provider to function.
type ProviderField struct {
	Key        string   `json:"key"`
	Label      string   `json:"label"`
	Type       string   `json:"type"` // text | number | select | bool | password
	IsSecret   bool     `json:"is_secret,omitempty"`
	IsRequired bool     `json:"is_required,omitempty"`
	// Options is non-nil only for type=select fields.
	Options    []string `json:"options,omitempty"`
	Default    any      `json:"default,omitempty"`
	Help       string   `json:"help,omitempty"`
}

// ProviderSpec is the static schema for one email provider.
type ProviderSpec struct {
	Slug        string          `json:"slug"`
	Label       string          `json:"label"`
	Fields      []ProviderField `json:"fields"`
	// DocsURL is a link to the provider's official API / SMTP documentation.
	DocsURL     string          `json:"docs_url,omitempty"`
}

// Catalog is the static v1 provider catalog (SMTP + 4 API providers).
// The wp-agent-engineer mirrors these slugs and field shapes in the agent's
// ProviderRouter. Do NOT rename slugs without a coordinated agent release.
var Catalog = []ProviderSpec{
	{
		Slug:    "smtp",
		Label:   "Generic SMTP",
		DocsURL: "https://en.wikipedia.org/wiki/Simple_Mail_Transfer_Protocol",
		Fields: []ProviderField{
			{Key: "host", Label: "SMTP Host", Type: "text", IsRequired: true,
				Help: "Hostname or IP of the outgoing mail server."},
			{Key: "port", Label: "Port", Type: "number", IsRequired: true, Default: 587,
				Help: "Common values: 25, 465 (SSL), 587 (TLS/STARTTLS)."},
			{Key: "encryption", Label: "Encryption", Type: "select", IsRequired: true,
				Options: []string{"none", "ssl", "tls"}, Default: "tls",
				Help: "none = plain, ssl = SMTPS (port 465), tls = STARTTLS (port 587)."},
			{Key: "auth", Label: "Authentication", Type: "bool", Default: true,
				Help: "Whether the server requires login credentials."},
			{Key: "username", Label: "Username", Type: "text",
				Help: "SMTP login username (often the From address)."},
			// password is the secret field — stored encrypted, never returned.
			{Key: "password", Label: "Password", Type: "password", IsSecret: true,
				Help: "SMTP login password. Stored encrypted; only a password_set indicator is returned."},
			{Key: "auto_tls", Label: "Auto TLS", Type: "bool", Default: false,
				Help: "Attempt STARTTLS automatically even when encryption=none."},
			{Key: "return_path", Label: "Set Return-Path", Type: "bool", Default: false,
				Help: "Override the envelope sender (Return-Path / bounce address)."},
			{Key: "sender_name", Label: "Sender Name", Type: "text",
				Help: "Override the display name in From: (if force_from_name is true)."},
			{Key: "sender_email", Label: "Sender Email", Type: "text",
				Help: "Override the From: address (if force_from_email is true)."},
		},
	},
	{
		Slug:    "ses",
		Label:   "Amazon SES",
		DocsURL: "https://docs.aws.amazon.com/ses/",
		Fields: []ProviderField{
			{Key: "access_key", Label: "Access Key ID", Type: "text", IsRequired: true,
				Help: "IAM user access key with ses:SendRawEmail permission."},
			// secret_key is the secret field.
			{Key: "secret_key", Label: "Secret Access Key", Type: "password", IsSecret: true, IsRequired: true,
				Help: "IAM secret access key. Stored encrypted."},
			{Key: "region", Label: "AWS Region", Type: "text", IsRequired: true, Default: "us-east-1",
				Help: "SES region, e.g. us-east-1, eu-west-1, ap-south-1."},
			{Key: "return_path", Label: "Set Return-Path", Type: "bool", Default: false,
				Help: "Route bounces to a separate address via SES return-path."},
		},
	},
	{
		Slug:    "sendgrid",
		Label:   "SendGrid",
		DocsURL: "https://docs.sendgrid.com/",
		Fields: []ProviderField{
			// api_key is the sole secret field.
			{Key: "api_key", Label: "API Key", Type: "password", IsSecret: true, IsRequired: true,
				Help: "SendGrid API key with Mail Send scope. Stored encrypted."},
		},
	},
	{
		Slug:    "mailgun",
		Label:   "Mailgun",
		DocsURL: "https://documentation.mailgun.com/",
		Fields: []ProviderField{
			// api_key is the secret field.
			{Key: "api_key", Label: "API Key", Type: "password", IsSecret: true, IsRequired: true,
				Help: "Mailgun Private API Key. Stored encrypted."},
			{Key: "domain_name", Label: "Domain Name", Type: "text", IsRequired: true,
				Help: "The sending domain registered in Mailgun, e.g. mg.example.com."},
			{Key: "region", Label: "Region", Type: "select", IsRequired: true,
				Options: []string{"us", "eu"}, Default: "us",
				Help: "us = api.mailgun.net, eu = api.eu.mailgun.net."},
		},
	},
	{
		Slug:    "postmark",
		Label:   "Postmark",
		DocsURL: "https://postmarkapp.com/developer",
		Fields: []ProviderField{
			// server_token is the secret field.
			{Key: "server_token", Label: "Server Token", Type: "password", IsSecret: true, IsRequired: true,
				Help: "Postmark Server API Token. Stored encrypted."},
			{Key: "message_stream", Label: "Message Stream", Type: "text", Default: "outbound",
				Help: "Postmark message stream ID (default: outbound)."},
			{Key: "track_opens", Label: "Track Opens", Type: "bool", Default: false,
				Help: "Enable Postmark open tracking."},
			{Key: "track_links", Label: "Track Links", Type: "select",
				Options: []string{"None", "HtmlAndText", "HtmlOnly", "TextOnly"}, Default: "None",
				Help: "Postmark link-click tracking mode."},
		},
	},
}

// ProviderBySlug returns the ProviderSpec for the given slug, or (ProviderSpec{}, false).
func ProviderBySlug(slug string) (ProviderSpec, bool) {
	for _, p := range Catalog {
		if p.Slug == slug {
			return p, true
		}
	}
	return ProviderSpec{}, false
}

// ValidProviderSlug reports whether slug is a known v1 provider.
func ValidProviderSlug(slug string) bool {
	_, ok := ProviderBySlug(slug)
	return ok
}
