package mailer

import (
	"strings"
	"testing"
)

// sampleData returns representative variables for each template so the render
// test exercises every {{.Field}} the templates reference.
func sampleData(name string) map[string]any {
	common := map[string]any{
		"ProductName":  "WPMgr",
		"BaseURL":      "https://manage.wpmgr.app",
		"SupportEmail": "support@wpmgr.app",
		"Year":         2026,
		"PreviewText":  "preview",
	}
	switch name {
	case "test":
		common["RecipientEmail"] = "ops@example.com"
	case "password_reset":
		common["Name"] = "Sam"
		common["ResetURL"] = "https://manage.wpmgr.app/reset-password?token=abc123"
		common["ExpiresMinutes"] = "30"
	case "password_changed":
		common["Name"] = "Sam"
		common["When"] = "2026-06-02 14:00 UTC"
		common["IP"] = "203.0.113.7"
	case "verify_email":
		common["Name"] = "Sam"
		common["VerifyURL"] = "https://manage.wpmgr.app/verify-email?token=def456"
		common["ExpiresHours"] = "24"
	case "invite":
		common["Name"] = "Sam"
		common["InviterName"] = "Alex"
		common["OrgName"] = "Acme"
		common["Role"] = "admin"
		common["AcceptURL"] = "https://manage.wpmgr.app/accept?token=ghi789"
		common["ExpiresHours"] = "168"
	case "account_exists":
		common["Name"] = "Sam"
		common["LoginURL"] = "https://manage.wpmgr.app/login"
		common["ResetURL"] = "https://manage.wpmgr.app/forgot-password"
	case "site_invite":
		common["Name"] = "there"
		common["InviterName"] = "Alex"
		common["SiteName"] = "example.com"
		common["Role"] = "viewer"
		common["AcceptURL"] = "https://manage.wpmgr.app/accept?token=site123"
		common["ExpiresHours"] = "168"
	case "site_shared":
		common["Name"] = "Sam"
		common["InviterName"] = "Alex"
		common["SiteName"] = "example.com"
		common["Role"] = "viewer"
		common["DashboardURL"] = "https://manage.wpmgr.app/shared-with-me"
	case "email_failure_alert":
		// Mirrors the production shape built in email/notify.go sendAlert:
		// counts are ints, samples carry Subject/To/Error.
		common["SiteName"] = "example.com"
		common["SiteURL"] = "https://example.com"
		common["SiteEmailURL"] = "https://manage.wpmgr.app/sites/abc/email/log"
		common["FailureCount"] = 3
		common["WindowMinutes"] = 60
		common["Samples"] = []map[string]any{
			{"Subject": "Order receipt", "To": "buyer@example.com", "Error": "550 mailbox unavailable"},
			{"Subject": "Password reset", "To": "user@example.com", "Error": "timeout connecting to provider"},
		}
	case "email_digest":
		// Mirrors the production shape built in email/notify.go buildDigestData:
		// aggregate counts are int64, per-site rows carry int64 counts.
		common["PeriodLabel"] = "June 2026"
		common["From"] = "2026-06-01"
		common["To"] = "2026-06-30"
		common["Total"] = int64(120)
		common["SentCount"] = int64(110)
		common["FailedCount"] = int64(7)
		common["BouncedCount"] = int64(3)
		common["SiteCount"] = int64(2)
		common["Sites"] = []map[string]any{
			{"SiteName": "example.com", "SiteURL": "https://example.com", "Sent": int64(80), "Failed": int64(5), "Bounced": int64(2)},
			{"SiteName": "shop.example.com", "SiteURL": "https://shop.example.com", "Sent": int64(30), "Failed": int64(0), "Bounced": int64(1)},
		}
		common["TopFailures"] = []map[string]any{
			{"SiteName": "example.com", "Subject": "Order receipt", "Error": "550 mailbox unavailable"},
		}
		common["DashboardURL"] = "https://manage.wpmgr.app/email"
	}
	return common
}

// TestRenderAllTemplates renders every template and asserts each produces a
// non-empty subject + HTML + plaintext.
func TestRenderAllTemplates(t *testing.T) {
	r, err := NewTemplateRenderer()
	if err != nil {
		t.Fatalf("NewTemplateRenderer: %v", err)
	}
	for name := range subjects {
		em, err := r.Render(name, sampleData(name))
		if err != nil {
			t.Fatalf("render %s: %v", name, err)
		}
		if em.Subject == "" || strings.TrimSpace(em.HTML) == "" || strings.TrimSpace(em.Text) == "" {
			t.Fatalf("render %s: empty subject/html/text", name)
		}
		// HTML must carry the bulletproof <html> boilerplate.
		if !strings.Contains(strings.ToLower(em.HTML), "<html") {
			t.Errorf("render %s: html missing <html> root", name)
		}
	}
}

// TestPlaintextContainsActionURL enforces the security/UX requirement that every
// actionable email's PLAINTEXT alternative embeds the literal link (text-only
// clients must still be able to complete the action).
func TestPlaintextContainsActionURL(t *testing.T) {
	r, err := NewTemplateRenderer()
	if err != nil {
		t.Fatalf("NewTemplateRenderer: %v", err)
	}
	cases := map[string]string{
		"password_reset": "https://manage.wpmgr.app/reset-password?token=abc123",
		"verify_email":   "https://manage.wpmgr.app/verify-email?token=def456",
		"invite":         "https://manage.wpmgr.app/accept?token=ghi789",
	}
	for name, url := range cases {
		em, err := r.Render(name, sampleData(name))
		if err != nil {
			t.Fatalf("render %s: %v", name, err)
		}
		if !strings.Contains(em.Text, url) {
			t.Errorf("plaintext for %s must contain the action URL %q", name, url)
		}
	}
}
