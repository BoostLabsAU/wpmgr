# ADR-045 Appendix A — Brand HTML Email Templates

> Companion to [ADR-045](./ADR-045-email-auth-alerts.md). Production-ready, Outlook-safe, dark-mode-aware HTML + plaintext templates for the WPMgr mailer.

# WPMgr Transactional Email Templates — Fleet Hub Identity

Production-ready, bulletproof HTML email templates for the WPMgr control plane. Every template is table-based, fully inlined, 600px, MSO-safe, dark-mode aware, and ships with a Go `html/template` integration plan and plaintext alternatives.

---

## 1. Shared Layout & Approach

### Visual layout (every email)

```
┌────────────────────────────────────────────┐  ← page bg #F4F6F6 (light) / #0F1716 (dark)
│                  (24px gap)                  │
│   ┌──────────────────────────────────────┐  │  ← 600px card, #FFFFFE / #15201F
│   │  HEADER                                │  │
│   │  [Fleet Hub mark]  wp·mgr  wordmark   │  │  ← inline SVG node + spokes, "wp" ink / "mgr" teal
│   ├──────────────────────────────────────┤  │  ← 1px hairline #D8E2E1
│   │  BODY                                  │  │
│   │   H1 title (deep teal-ink)             │  │
│   │   body paragraph(s), muted secondary   │  │
│   │   ┌─ teal CTA button (bulletproof) ─┐  │  │  ← single #0E7C8B fill, VML for Outlook
│   │   detail table / security notice      │  │
│   │   fallback "copy this link" mono row   │  │
│   ├──────────────────────────────────────┤  │  ← 1px hairline
│   │  FOOTER                                │  │
│   │   WPMgr · product line                 │  │
│   │   "you received this because…" (muted) │  │
│   │   unsubscribe / support contact        │  │
│   └──────────────────────────────────────┘  │
│                  (24px gap)                  │
└────────────────────────────────────────────┘
```

### Approach decisions

- **Table-based, fully inlined CSS.** All structure is nested `<table role="presentation">`; every presentational style is inlined on the element. The `<head><style>` block carries **only** progressive enhancement — responsive `@media` and `@media (prefers-color-scheme: dark)` — because Gmail strips `<style>` on forward and many clients ignore media queries.
- **600px max width** via a centered 100% outer table + inner fixed 600px table, with an Outlook ghost table (`<!--[if mso]><table width="600">…<![endif]-->`) because classic Outlook ignores `max-width`.
- **MSO-safe button.** The CTA is a styled `<a>` for modern clients, wrapped in a `<!--[if mso]><v:roundrect>…<![endif]-->` VML rounded-rect for classic Outlook (Word engine), with the `<a>` hidden from MSO via `<!--[if !mso]><!-->…<!--<![endif]-->`. This gives rounded corners + correct teal fill everywhere.
- **Dark mode.** `<meta name="color-scheme" content="light dark">`, `<meta name="supported-color-schemes" content="light dark">`, `:root { color-scheme: light dark; }`, plus a `@media (prefers-color-scheme: dark)` block with explicit dark hex values. **No pure `#000000`/`#FFFFFF`** — near-white `#FFFFFE` and near-black `#0F1716` resist force-inversion. CTA brightens to `#2BB7AE` on dark so it stays ≥4.5:1.
- **System font stack only** — no webfonts (`@font-face` works only in Apple Mail / Outlook-Mac). Monospace stack for the wordmark.
- **The mark is an inline SVG** (teal center node + 4 hollow satellite rounded-rects + thin spokes). Apple Mail and most modern clients render inline SVG; for **Gmail and classic Outlook (which strip/ignore inline SVG)** the SVG sits inside an MSO-conditional fallback that swaps to a hosted **PNG logo** (`{{.LogoPNGURL}}`). See the PNG-fallback note in each template header. Host the PNG at 2× (e.g. 96×96 displayed at 48×48) over HTTPS.
- **Plaintext alternative** is provided for reset + activation (Section 3) and should be generated from the same source for the rest via `go-premailer` `TransformText()`.
- **Links/logo come from `{{.BaseURL}}`** (wired as `WPMGR_PUBLIC_BASE_URL`), never the inbound Host header — this is both the self-host correctness fix and the Host-header-injection defense for activation/reset links.

### MJML source vs. checked-in HTML — recommendation

**Recommendation: checked-in HTML `html/template` files embedded via `embed.FS`, not an MJML runtime.** Rationale for this repo:

- The Go API binary must stay pure-Go with the smallest runtime supply-chain surface; embedding an MJML WASM renderer (mjml-go) or shelling to Node at send time adds risk for zero authoring benefit once the templates exist.
- These six templates are stable and few. The "MJML → compile → commit HTML" build-step value (Outlook table boilerplate) is real but one-time; I've already written the bulletproof tables below, so you get the MJML output quality without the build step or the stale-artifact CI risk.
- `html/template` gives **context-aware auto-escaping** for the user-controlled fields (org name, inviter name, site URL, event details) — critical, since the old code concatenated raw strings.

If email authors later strongly prefer MJML ergonomics, keep these committed HTML files as the source of truth and treat MJML as an optional authoring aid; do **not** introduce an MJML runtime dependency in the API.

### Go `html/template` integration plan

**Layout composition (shared header/footer in one place):**

```go
// internal/email/templates.go
package email

import (
	"bytes"
	"embed"
	"html/template"
)

//go:embed templates/*.tmpl
var fs embed.FS

// One parsed set; layout.tmpl defines {{define "header"}}, {{define "footer"}},
// {{define "head"}}, {{define "button"}}. Each email file defines {{define "subject"}}
// and {{define "body"}} and {{template "layout" .}} to assemble.
var tpls = template.Must(template.New("").
	Funcs(template.FuncMap{
		"safeURL": func(s string) template.URL { return template.URL(s) },
	}).
	ParseFS(fs, "templates/*.tmpl"))

type Rendered struct {
	Subject string
	HTML    string
	Text    string // from go-premailer TransformText(), or a hand file for reset/activation
}

func Render(name string, data any) (Rendered, error) {
	var subj, body bytes.Buffer
	if err := tpls.ExecuteTemplate(&subj, name+".subject", data); err != nil {
		return Rendered{}, err
	}
	if err := tpls.ExecuteTemplate(&body, name+".html", data); err != nil {
		return Rendered{}, err
	}
	return Rendered{Subject: subj.String(), HTML: body.String()}, nil
}
```

- **Named templates.** Each file registers `"<name>.subject"` and `"<name>.html"`. The shared header/footer/head/button live in `layout.tmpl` as `{{define …}}` blocks that every email `{{template …}}`-includes — so the Fleet Hub mark, wordmark, footer, and the VML button exist exactly once.
- **`{{ . }}` variables.** Each email takes a typed struct (e.g. `ActivationData{Name, ActivateURL, BaseURL, LogoPNGURL, ExpiryMinutes}`). Auto-escaping handles HTML/attr/URL contexts; URLs that are already absolute and trusted (built from `BaseURL`) can pass through `safeURL`.
- **Mailer seam.** A single helper `func (m *Mailer) SendTemplate(ctx, to, name, data)` calls `Render`, then go-mail `SetBodyString(TypeTextHTML, r.HTML)` + `AddAlternativeString(TypeTextPlain, r.Text)`. This replaces the raw string concatenation in `uptime/notify.go` and `invitation/service.go` so all senders share one layout, one inliner, one plaintext path. Wrap the actual send in the recommended `send_email` River job for durable retries.
- **Delimiters.** Because templates are authored as Go HTML directly, there's no MJML `{{ }}` collision to worry about.

In the templates below the shared boilerplate is written out **in full inside each file** (self-contained, as requested). In-repo you would factor the `<head>`, header, footer, and button into the `layout.tmpl` `{{define}}` blocks shown above.

---

## 2. Full HTML Templates

> Each is self-contained with all bulletproof boilerplate and `{{.Variable}}` Go placeholders. **PNG-logo fallback note:** the inline SVG mark is wrapped so non-SVG clients (Gmail, classic Outlook) get the hosted PNG at `{{.LogoPNGURL}}` (host a transparent 96×96 PNG, displayed 48×48). If you prefer PNG-everywhere for maximum reach, delete the `<!--[if !mso]><!-->…SVG…<!--<![endif]-->` wrapper and keep only the `<img>`.

### (a) Account activation / "Set your password"

```html
{{define "activation.subject"}}Activate your WPMgr account{{end}}
{{define "activation.html"}}<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>Activate your WPMgr account</title>
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
  <style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body,table,td,a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    body { margin:0; padding:0; width:100% !important; height:100% !important; }
    a { color:#0E7C8B; }
    @media only screen and (max-width:600px){
      .wrap{ width:100% !important; }
      .px{ padding-left:24px !important; padding-right:24px !important; }
      .btn-a{ display:block !important; width:auto !important; }
    }
    @media (prefers-color-scheme: dark){
      .bg{ background:#0F1716 !important; }
      .card{ background:#15201F !important; }
      .hair{ border-color:#28403D !important; }
      .ink{ color:#EAF4F2 !important; }
      .body{ color:#C4D2D0 !important; }
      .muted{ color:#8FA29F !important; }
      .wm-wp{ color:#EAF4F2 !important; }
      .mono-box{ background:#0F1716 !important; border-color:#28403D !important; }
      .mono-box td{ color:#C4D2D0 !important; }
      .btn-a{ background:#2BB7AE !important; color:#0F1716 !important; }
    }
  </style>
</head>
<body class="bg" style="margin:0;padding:0;background:#F4F6F6;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Set your password and activate your WPMgr account. This link expires in {{.ExpiryMinutes}} minutes.</div>
  <table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;">
    <tr><td align="center" style="padding:24px 12px;">
      <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrap card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#FFFFFE;border-radius:14px;border:1px solid #D8E2E1;">

        <!-- HEADER -->
        <tr><td class="px hair" style="padding:28px 40px 22px 40px;border-bottom:1px solid #D8E2E1;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle" style="padding-right:12px;">
              <!--[if !mso]><!-->
              <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Fleet Hub mark" style="display:block;">
                <line x1="9" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <line x1="27" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <line x1="9" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <line x1="27" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="13" y="13" width="10" height="10" rx="3" fill="#0E7C8B"/>
                <rect x="3.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="23.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="3.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="23.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
              </svg>
              <!--<![endif]-->
              <!--[if mso]><img src="{{.LogoPNGURL}}" width="36" height="36" alt="Fleet Hub" style="display:block;border:0;"><![endif]-->
            </td>
            <td valign="middle" class="wm-wp" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,Monaco,'Courier New',monospace;font-size:22px;font-weight:700;letter-spacing:-0.5px;color:#0A0A0A;">wp<span style="color:#0E7C8B;">mgr</span></td>
          </tr></table>
        </td></tr>

        <!-- BODY -->
        <tr><td class="px" style="padding:36px 40px 8px 40px;">
          <h1 class="ink" style="margin:0 0 14px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:24px;line-height:1.3;font-weight:700;color:#16302E;">Activate your account</h1>
          <p class="body" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#3A4A48;">Hi {{.Name}}, welcome to WPMgr. To finish setting up your account, choose a password and activate it below.</p>
        </td></tr>

        <!-- CTA -->
        <tr><td class="px" align="left" style="padding:14px 40px 8px 40px;">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{.ActivateURL}}" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="25%" stroke="f" fillcolor="#0E7C8B">
            <w:anchorlock/>
            <center style="color:#FFFFFE;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Activate account</center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-->
          <a class="btn-a" href="{{.ActivateURL}}" role="button" style="background:#0E7C8B;border-radius:10px;color:#FFFFFE;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:48px;text-align:center;text-decoration:none;width:220px;mso-padding-alt:0;-webkit-text-size-adjust:none;">Activate account</a>
          <!--<![endif]-->
        </td></tr>

        <!-- FALLBACK LINK -->
        <tr><td class="px" style="padding:18px 40px 6px 40px;">
          <p class="muted" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.5;color:#6B7280;">If the button doesn't work, copy and paste this link into your browser:</p>
          <table role="presentation" class="mono-box" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;border:1px solid #D8E2E1;border-radius:8px;"><tr><td style="padding:12px 14px;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:13px;line-height:1.5;color:#3A4A48;word-break:break-all;">{{.ActivateURL}}</td></tr></table>
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 32px 40px;">
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#6B7280;">This activation link expires in {{.ExpiryMinutes}} minutes. If you didn't expect this email, you can safely ignore it.</p>
        </td></tr>

        <!-- FOOTER -->
        <tr><td class="px hair" style="padding:24px 40px 28px 40px;border-top:1px solid #D8E2E1;">
          <p class="ink" style="margin:0 0 6px 0;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:14px;font-weight:700;color:#16302E;">WPMgr <span class="muted" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-weight:400;color:#6B7280;">— fleet control for WordPress</span></p>
          <p class="muted" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">You received this because an account was created for {{.Email}} on WPMgr.</p>
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">Need help? Contact <a href="mailto:{{.SupportEmail}}" style="color:#0E7C8B;text-decoration:underline;">{{.SupportEmail}}</a>.</p>
        </td></tr>

      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body>
</html>{{end}}
```

### (b) Team invite

```html
{{define "invite.subject"}}{{.InviterName}} invited you to {{.OrgName}} on WPMgr{{end}}
{{define "invite.html"}}<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>You've been invited to WPMgr</title>
  <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
  <style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body,table,td,a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    body { margin:0; padding:0; width:100% !important; }
    a { color:#0E7C8B; }
    @media only screen and (max-width:600px){ .wrap{width:100% !important;} .px{padding-left:24px !important;padding-right:24px !important;} .btn-a{display:block !important;width:auto !important;} }
    @media (prefers-color-scheme: dark){
      .bg{background:#0F1716 !important;} .card{background:#15201F !important;} .hair{border-color:#28403D !important;}
      .ink{color:#EAF4F2 !important;} .body{color:#C4D2D0 !important;} .muted{color:#8FA29F !important;} .wm-wp{color:#EAF4F2 !important;}
      .mono-box{background:#0F1716 !important;border-color:#28403D !important;} .mono-box td{color:#C4D2D0 !important;}
      .band{background:#0F1716 !important;border-color:#28403D !important;} .band td{color:#C4D2D0 !important;}
      .btn-a{background:#2BB7AE !important;color:#0F1716 !important;}
    }
  </style>
</head>
<body class="bg" style="margin:0;padding:0;background:#F4F6F6;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{.InviterName}} invited you to join {{.OrgName}} on WPMgr.</div>
  <table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;">
    <tr><td align="center" style="padding:24px 12px;">
      <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrap card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#FFFFFE;border-radius:14px;border:1px solid #D8E2E1;">

        <tr><td class="px hair" style="padding:28px 40px 22px 40px;border-bottom:1px solid #D8E2E1;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle" style="padding-right:12px;">
              <!--[if !mso]><!-->
              <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Fleet Hub mark" style="display:block;">
                <line x1="9" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="9" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="13" y="13" width="10" height="10" rx="3" fill="#0E7C8B"/>
                <rect x="3.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="3.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
              </svg>
              <!--<![endif]-->
              <!--[if mso]><img src="{{.LogoPNGURL}}" width="36" height="36" alt="Fleet Hub" style="display:block;border:0;"><![endif]-->
            </td>
            <td valign="middle" class="wm-wp" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,Monaco,'Courier New',monospace;font-size:22px;font-weight:700;letter-spacing:-0.5px;color:#0A0A0A;">wp<span style="color:#0E7C8B;">mgr</span></td>
          </tr></table>
        </td></tr>

        <tr><td class="px" style="padding:36px 40px 8px 40px;">
          <h1 class="ink" style="margin:0 0 14px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:24px;line-height:1.3;font-weight:700;color:#16302E;">You're invited to {{.OrgName}}</h1>
          <p class="body" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#3A4A48;"><strong style="color:#16302E;">{{.InviterName}}</strong> has invited you to join <strong style="color:#16302E;">{{.OrgName}}</strong> on WPMgr as <strong style="color:#16302E;">{{.RoleLabel}}</strong>. Accept the invite to set your password and get started.</p>
        </td></tr>

        <tr><td class="px" align="left" style="padding:14px 40px 8px 40px;">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{.AcceptURL}}" style="height:48px;v-text-anchor:middle;width:200px;" arcsize="25%" stroke="f" fillcolor="#0E7C8B">
            <w:anchorlock/><center style="color:#FFFFFE;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Accept invite</center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-->
          <a class="btn-a" href="{{.AcceptURL}}" role="button" style="background:#0E7C8B;border-radius:10px;color:#FFFFFE;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:48px;text-align:center;text-decoration:none;width:200px;mso-padding-alt:0;-webkit-text-size-adjust:none;">Accept invite</a>
          <!--<![endif]-->
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 6px 40px;">
          <p class="muted" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.5;color:#6B7280;">Or paste this link into your browser:</p>
          <table role="presentation" class="mono-box" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;border:1px solid #D8E2E1;border-radius:8px;"><tr><td style="padding:12px 14px;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:13px;line-height:1.5;color:#3A4A48;word-break:break-all;">{{.AcceptURL}}</td></tr></table>
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 32px 40px;">
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#6B7280;">This invite expires in {{.ExpiryHours}} hours. If you weren't expecting it, you can ignore this email.</p>
        </td></tr>

        <tr><td class="px hair" style="padding:24px 40px 28px 40px;border-top:1px solid #D8E2E1;">
          <p class="ink" style="margin:0 0 6px 0;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:14px;font-weight:700;color:#16302E;">WPMgr <span class="muted" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-weight:400;color:#6B7280;">— fleet control for WordPress</span></p>
          <p class="muted" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">You received this because {{.InviterName}} invited {{.Email}} to a WPMgr organization.</p>
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">Questions? Contact <a href="mailto:{{.SupportEmail}}" style="color:#0E7C8B;text-decoration:underline;">{{.SupportEmail}}</a>.</p>
        </td></tr>

      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body>
</html>{{end}}
```

### (c) Forgot password / reset

```html
{{define "reset.subject"}}Reset your WPMgr password{{end}}
{{define "reset.html"}}<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>Reset your WPMgr password</title>
  <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
  <style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body,table,td,a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    body { margin:0; padding:0; width:100% !important; }
    a { color:#0E7C8B; }
    @media only screen and (max-width:600px){ .wrap{width:100% !important;} .px{padding-left:24px !important;padding-right:24px !important;} .btn-a{display:block !important;width:auto !important;} }
    @media (prefers-color-scheme: dark){
      .bg{background:#0F1716 !important;} .card{background:#15201F !important;} .hair{border-color:#28403D !important;}
      .ink{color:#EAF4F2 !important;} .body{color:#C4D2D0 !important;} .muted{color:#8FA29F !important;} .wm-wp{color:#EAF4F2 !important;}
      .mono-box{background:#0F1716 !important;border-color:#28403D !important;} .mono-box td{color:#C4D2D0 !important;}
      .btn-a{background:#2BB7AE !important;color:#0F1716 !important;}
    }
  </style>
</head>
<body class="bg" style="margin:0;padding:0;background:#F4F6F6;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Reset your WPMgr password. This link expires in {{.ExpiryMinutes}} minutes.</div>
  <table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;">
    <tr><td align="center" style="padding:24px 12px;">
      <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrap card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#FFFFFE;border-radius:14px;border:1px solid #D8E2E1;">

        <tr><td class="px hair" style="padding:28px 40px 22px 40px;border-bottom:1px solid #D8E2E1;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle" style="padding-right:12px;">
              <!--[if !mso]><!-->
              <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Fleet Hub mark" style="display:block;">
                <line x1="9" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="9" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="13" y="13" width="10" height="10" rx="3" fill="#0E7C8B"/>
                <rect x="3.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="3.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
              </svg>
              <!--<![endif]-->
              <!--[if mso]><img src="{{.LogoPNGURL}}" width="36" height="36" alt="Fleet Hub" style="display:block;border:0;"><![endif]-->
            </td>
            <td valign="middle" class="wm-wp" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,Monaco,'Courier New',monospace;font-size:22px;font-weight:700;letter-spacing:-0.5px;color:#0A0A0A;">wp<span style="color:#0E7C8B;">mgr</span></td>
          </tr></table>
        </td></tr>

        <tr><td class="px" style="padding:36px 40px 8px 40px;">
          <h1 class="ink" style="margin:0 0 14px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:24px;line-height:1.3;font-weight:700;color:#16302E;">Reset your password</h1>
          <p class="body" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#3A4A48;">We received a request to reset the password for your WPMgr account. Click below to choose a new one.</p>
        </td></tr>

        <tr><td class="px" align="left" style="padding:14px 40px 8px 40px;">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{.ResetURL}}" style="height:48px;v-text-anchor:middle;width:210px;" arcsize="25%" stroke="f" fillcolor="#0E7C8B">
            <w:anchorlock/><center style="color:#FFFFFE;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Reset password</center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-->
          <a class="btn-a" href="{{.ResetURL}}" role="button" style="background:#0E7C8B;border-radius:10px;color:#FFFFFE;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:48px;text-align:center;text-decoration:none;width:210px;mso-padding-alt:0;-webkit-text-size-adjust:none;">Reset password</a>
          <!--<![endif]-->
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 6px 40px;">
          <p class="muted" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.5;color:#6B7280;">Or paste this link into your browser:</p>
          <table role="presentation" class="mono-box" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;border:1px solid #D8E2E1;border-radius:8px;"><tr><td style="padding:12px 14px;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:13px;line-height:1.5;color:#3A4A48;word-break:break-all;">{{.ResetURL}}</td></tr></table>
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 32px 40px;">
          <p class="muted" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#6B7280;"><strong style="color:#3A4A48;">This link expires in {{.ExpiryMinutes}} minutes</strong> and can be used once.</p>
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#6B7280;">If you didn't request a password reset, you can safely ignore this email — your password won't change.</p>
        </td></tr>

        <tr><td class="px hair" style="padding:24px 40px 28px 40px;border-top:1px solid #D8E2E1;">
          <p class="ink" style="margin:0 0 6px 0;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:14px;font-weight:700;color:#16302E;">WPMgr <span class="muted" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-weight:400;color:#6B7280;">— fleet control for WordPress</span></p>
          <p class="muted" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">You received this because a password reset was requested for {{.Email}}.</p>
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">If this wasn't you, contact <a href="mailto:{{.SupportEmail}}" style="color:#0E7C8B;text-decoration:underline;">{{.SupportEmail}}</a>.</p>
        </td></tr>

      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body>
</html>{{end}}
```

### (d) Password changed confirmation (no CTA)

```html
{{define "password_changed.subject"}}Your WPMgr password was changed{{end}}
{{define "password_changed.html"}}<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>Your WPMgr password was changed</title>
  <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
  <style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body,table,td,a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    body { margin:0; padding:0; width:100% !important; }
    a { color:#0E7C8B; }
    @media only screen and (max-width:600px){ .wrap{width:100% !important;} .px{padding-left:24px !important;padding-right:24px !important;} }
    @media (prefers-color-scheme: dark){
      .bg{background:#0F1716 !important;} .card{background:#15201F !important;} .hair{border-color:#28403D !important;}
      .ink{color:#EAF4F2 !important;} .body{color:#C4D2D0 !important;} .muted{color:#8FA29F !important;} .wm-wp{color:#EAF4F2 !important;}
      .band{background:#0F1716 !important;border-color:#28403D !important;} .band td,.band th{color:#C4D2D0 !important;}
    }
  </style>
</head>
<body class="bg" style="margin:0;padding:0;background:#F4F6F6;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Your WPMgr password was changed. If this wasn't you, contact support immediately.</div>
  <table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;">
    <tr><td align="center" style="padding:24px 12px;">
      <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrap card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#FFFFFE;border-radius:14px;border:1px solid #D8E2E1;">

        <tr><td class="px hair" style="padding:28px 40px 22px 40px;border-bottom:1px solid #D8E2E1;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle" style="padding-right:12px;">
              <!--[if !mso]><!-->
              <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Fleet Hub mark" style="display:block;">
                <line x1="9" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="9" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="13" y="13" width="10" height="10" rx="3" fill="#0E7C8B"/>
                <rect x="3.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="3.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
              </svg>
              <!--<![endif]-->
              <!--[if mso]><img src="{{.LogoPNGURL}}" width="36" height="36" alt="Fleet Hub" style="display:block;border:0;"><![endif]-->
            </td>
            <td valign="middle" class="wm-wp" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,Monaco,'Courier New',monospace;font-size:22px;font-weight:700;letter-spacing:-0.5px;color:#0A0A0A;">wp<span style="color:#0E7C8B;">mgr</span></td>
          </tr></table>
        </td></tr>

        <tr><td class="px" style="padding:36px 40px 8px 40px;">
          <h1 class="ink" style="margin:0 0 14px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:24px;line-height:1.3;font-weight:700;color:#16302E;">Your password was changed</h1>
          <p class="body" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#3A4A48;">Hi {{.Name}}, the password for your WPMgr account was just changed. Here are the details:</p>
        </td></tr>

        <!-- DETAILS TABLE -->
        <tr><td class="px" style="padding:6px 40px 8px 40px;">
          <table role="presentation" class="band" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#E6F4F3;border:1px solid #D8E2E1;border-radius:10px;">
            <tr>
              <td style="padding:14px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;width:130px;">Account</td>
              <td style="padding:14px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#16302E;font-weight:600;">{{.Email}}</td>
            </tr>
            <tr>
              <td style="padding:14px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;">When</td>
              <td style="padding:14px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#16302E;font-weight:600;">{{.ChangedAt}}</td>
            </tr>
            <tr>
              <td style="padding:14px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;">From</td>
              <td style="padding:14px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#16302E;font-weight:600;">{{.IPAddress}}{{if .Location}} · {{.Location}}{{end}}</td>
            </tr>
          </table>
        </td></tr>

        <tr><td class="px" style="padding:22px 40px 32px 40px;">
          <p class="body" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#3A4A48;"><strong style="color:#16302E;">Wasn't you?</strong> Your account may be at risk. Contact <a href="mailto:{{.SupportEmail}}" style="color:#0E7C8B;text-decoration:underline;font-weight:600;">{{.SupportEmail}}</a> right away to secure it.</p>
        </td></tr>

        <tr><td class="px hair" style="padding:24px 40px 28px 40px;border-top:1px solid #D8E2E1;">
          <p class="ink" style="margin:0 0 6px 0;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:14px;font-weight:700;color:#16302E;">WPMgr <span class="muted" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-weight:400;color:#6B7280;">— fleet control for WordPress</span></p>
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">This is a security notification sent to {{.Email}}. You can't unsubscribe from account security alerts.</p>
        </td></tr>

      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body>
</html>{{end}}
```

### (e) Email verification / address change

```html
{{define "verify_email.subject"}}Confirm your email address for WPMgr{{end}}
{{define "verify_email.html"}}<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>Confirm your email address</title>
  <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
  <style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body,table,td,a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    body { margin:0; padding:0; width:100% !important; }
    a { color:#0E7C8B; }
    @media only screen and (max-width:600px){ .wrap{width:100% !important;} .px{padding-left:24px !important;padding-right:24px !important;} .btn-a{display:block !important;width:auto !important;} }
    @media (prefers-color-scheme: dark){
      .bg{background:#0F1716 !important;} .card{background:#15201F !important;} .hair{border-color:#28403D !important;}
      .ink{color:#EAF4F2 !important;} .body{color:#C4D2D0 !important;} .muted{color:#8FA29F !important;} .wm-wp{color:#EAF4F2 !important;}
      .band{background:#0F1716 !important;border-color:#28403D !important;} .band td{color:#C4D2D0 !important;}
      .mono-box{background:#0F1716 !important;border-color:#28403D !important;} .mono-box td{color:#C4D2D0 !important;}
      .btn-a{background:#2BB7AE !important;color:#0F1716 !important;}
    }
  </style>
</head>
<body class="bg" style="margin:0;padding:0;background:#F4F6F6;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Confirm {{.NewEmail}} as the email address for your WPMgr account.</div>
  <table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;">
    <tr><td align="center" style="padding:24px 12px;">
      <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrap card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#FFFFFE;border-radius:14px;border:1px solid #D8E2E1;">

        <tr><td class="px hair" style="padding:28px 40px 22px 40px;border-bottom:1px solid #D8E2E1;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle" style="padding-right:12px;">
              <!--[if !mso]><!-->
              <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Fleet Hub mark" style="display:block;">
                <line x1="9" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="9" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="13" y="13" width="10" height="10" rx="3" fill="#0E7C8B"/>
                <rect x="3.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="3.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
              </svg>
              <!--<![endif]-->
              <!--[if mso]><img src="{{.LogoPNGURL}}" width="36" height="36" alt="Fleet Hub" style="display:block;border:0;"><![endif]-->
            </td>
            <td valign="middle" class="wm-wp" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,Monaco,'Courier New',monospace;font-size:22px;font-weight:700;letter-spacing:-0.5px;color:#0A0A0A;">wp<span style="color:#0E7C8B;">mgr</span></td>
          </tr></table>
        </td></tr>

        <tr><td class="px" style="padding:36px 40px 8px 40px;">
          <h1 class="ink" style="margin:0 0 14px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:24px;line-height:1.3;font-weight:700;color:#16302E;">Confirm your email address</h1>
          <p class="body" style="margin:0 0 16px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.6;color:#3A4A48;">You asked to use <strong style="color:#16302E;">{{.NewEmail}}</strong> as the email address for your WPMgr account. Confirm it below to make the change.</p>
        </td></tr>

        <tr><td class="px" align="left" style="padding:14px 40px 8px 40px;">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{.ConfirmURL}}" style="height:48px;v-text-anchor:middle;width:200px;" arcsize="25%" stroke="f" fillcolor="#0E7C8B">
            <w:anchorlock/><center style="color:#FFFFFE;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">Confirm email</center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-->
          <a class="btn-a" href="{{.ConfirmURL}}" role="button" style="background:#0E7C8B;border-radius:10px;color:#FFFFFE;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:48px;text-align:center;text-decoration:none;width:200px;mso-padding-alt:0;-webkit-text-size-adjust:none;">Confirm email</a>
          <!--<![endif]-->
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 6px 40px;">
          <p class="muted" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.5;color:#6B7280;">Or paste this link into your browser:</p>
          <table role="presentation" class="mono-box" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;border:1px solid #D8E2E1;border-radius:8px;"><tr><td style="padding:12px 14px;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:13px;line-height:1.5;color:#3A4A48;word-break:break-all;">{{.ConfirmURL}}</td></tr></table>
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 32px 40px;">
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#6B7280;">This link expires in {{.ExpiryMinutes}} minutes. If you didn't request this change, ignore this email and your address will stay the same.</p>
        </td></tr>

        <tr><td class="px hair" style="padding:24px 40px 28px 40px;border-top:1px solid #D8E2E1;">
          <p class="ink" style="margin:0 0 6px 0;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:14px;font-weight:700;color:#16302E;">WPMgr <span class="muted" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-weight:400;color:#6B7280;">— fleet control for WordPress</span></p>
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">This confirmation was sent to {{.NewEmail}} for a WPMgr email-address change. Need help? <a href="mailto:{{.SupportEmail}}" style="color:#0E7C8B;text-decoration:underline;">{{.SupportEmail}}</a>.</p>
        </td></tr>

      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body>
</html>{{end}}
```

### (f) Generic alert notification (severity badge + details table + dashboard CTA)

```html
{{define "alert.subject"}}[{{.SeverityLabel}}] {{.EventTitle}} — {{.SiteName}}{{end}}
{{define "alert.html"}}<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>{{.EventTitle}}</title>
  <!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
  <style>
    :root { color-scheme: light dark; supported-color-schemes: light dark; }
    body,table,td,a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    body { margin:0; padding:0; width:100% !important; }
    a { color:#0E7C8B; }
    @media only screen and (max-width:600px){ .wrap{width:100% !important;} .px{padding-left:24px !important;padding-right:24px !important;} .btn-a{display:block !important;width:auto !important;} }
    @media (prefers-color-scheme: dark){
      .bg{background:#0F1716 !important;} .card{background:#15201F !important;} .hair{border-color:#28403D !important;}
      .ink{color:#EAF4F2 !important;} .body{color:#C4D2D0 !important;} .muted{color:#8FA29F !important;} .wm-wp{color:#EAF4F2 !important;}
      .band{background:#0F1716 !important;border-color:#28403D !important;} .band td{color:#C4D2D0 !important;} .band .k{color:#8FA29F !important;}
      .btn-a{background:#2BB7AE !important;color:#0F1716 !important;}
    }
  </style>
</head>
<body class="bg" style="margin:0;padding:0;background:#F4F6F6;">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{.SeverityLabel}}: {{.EventTitle}} on {{.SiteName}}.</div>
  <table role="presentation" class="bg" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4F6F6;">
    <tr><td align="center" style="padding:24px 12px;">
      <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrap card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#FFFFFE;border-radius:14px;border:1px solid #D8E2E1;">

        <tr><td class="px hair" style="padding:28px 40px 22px 40px;border-bottom:1px solid #D8E2E1;">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle" style="padding-right:12px;">
              <!--[if !mso]><!-->
              <svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Fleet Hub mark" style="display:block;">
                <line x1="9" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="9" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="9" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/><line x1="27" y1="27" x2="18" y2="18" stroke="#0E7C8B" stroke-width="1.5"/>
                <rect x="13" y="13" width="10" height="10" rx="3" fill="#0E7C8B"/>
                <rect x="3.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="3.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="3.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/><rect x="23.5" y="23.5" width="9" height="9" rx="2.5" stroke="#0E7C8B" stroke-width="1.5"/>
              </svg>
              <!--<![endif]-->
              <!--[if mso]><img src="{{.LogoPNGURL}}" width="36" height="36" alt="Fleet Hub" style="display:block;border:0;"><![endif]-->
            </td>
            <td valign="middle" class="wm-wp" style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,Monaco,'Courier New',monospace;font-size:22px;font-weight:700;letter-spacing:-0.5px;color:#0A0A0A;">wp<span style="color:#0E7C8B;">mgr</span></td>
          </tr></table>
        </td></tr>

        <!-- SEVERITY BADGE + TITLE -->
        <tr><td class="px" style="padding:34px 40px 4px 40px;">
          <!-- Badge colors: high #B42318/#FEF3F2, medium #B54708/#FFFAEB, low #0E7C8B/#E6F4F3.
               Pass .SeverityBG and .SeverityFG from Go for the active severity. -->
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="background:{{.SeverityBG}};border-radius:6px;padding:5px 11px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;color:{{.SeverityFG}};">{{.SeverityLabel}}</td>
          </tr></table>
        </td></tr>
        <tr><td class="px" style="padding:14px 40px 6px 40px;">
          <h1 class="ink" style="margin:0 0 8px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:23px;line-height:1.3;font-weight:700;color:#16302E;">{{.EventTitle}}</h1>
          <p class="body" style="margin:0 0 4px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#3A4A48;">{{.EventSummary}}</p>
        </td></tr>

        <!-- DETAILS TABLE -->
        <tr><td class="px" style="padding:18px 40px 8px 40px;">
          <table role="presentation" class="band" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#E6F4F3;border:1px solid #D8E2E1;border-radius:10px;">
            <tr>
              <td class="k" style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;width:130px;">Site</td>
              <td style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#16302E;font-weight:600;">{{.SiteName}}</td>
            </tr>
            <tr>
              <td class="k" style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;">Event</td>
              <td style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:13px;color:#16302E;">{{.EventType}}</td>
            </tr>
            <tr>
              <td class="k" style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;">When</td>
              <td style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#16302E;font-weight:600;">{{.OccurredAt}}</td>
            </tr>
            {{range .Details}}<tr>
              <td class="k" style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;">{{.Key}}</td>
              <td style="padding:13px 16px;border-bottom:1px solid #D8E2E1;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#16302E;font-weight:600;">{{.Value}}</td>
            </tr>{{end}}
            <tr>
              <td class="k" style="padding:13px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#6B7280;">Organization</td>
              <td style="padding:13px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:14px;color:#16302E;font-weight:600;">{{.OrgName}}</td>
            </tr>
          </table>
        </td></tr>

        <!-- CTA -->
        <tr><td class="px" align="left" style="padding:22px 40px 8px 40px;">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{.DashboardURL}}" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="25%" stroke="f" fillcolor="#0E7C8B">
            <w:anchorlock/><center style="color:#FFFFFE;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">View in dashboard</center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-->
          <a class="btn-a" href="{{.DashboardURL}}" role="button" style="background:#0E7C8B;border-radius:10px;color:#FFFFFE;display:inline-block;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:48px;text-align:center;text-decoration:none;width:220px;mso-padding-alt:0;-webkit-text-size-adjust:none;">View in dashboard</a>
          <!--<![endif]-->
        </td></tr>

        <tr><td class="px" style="padding:18px 40px 32px 40px;">
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:13px;line-height:1.6;color:#6B7280;">You're receiving this because alert notifications are enabled for {{.OrgName}}. Manage alerts in <a href="{{.AlertSettingsURL}}" style="color:#0E7C8B;text-decoration:underline;">Alerts</a>.</p>
        </td></tr>

        <tr><td class="px hair" style="padding:24px 40px 28px 40px;border-top:1px solid #D8E2E1;">
          <p class="ink" style="margin:0 0 6px 0;font-family:'SFMono-Regular',Consolas,Menlo,Monaco,'Courier New',monospace;font-size:14px;font-weight:700;color:#16302E;">WPMgr <span class="muted" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-weight:400;color:#6B7280;">— fleet control for WordPress</span></p>
          <p class="muted" style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.6;color:#6B7280;">Sent to {{.Email}} for the {{.OrgName}} organization. To stop these, turn off this alert in <a href="{{.AlertSettingsURL}}" style="color:#0E7C8B;text-decoration:underline;">alert settings</a> or contact <a href="mailto:{{.SupportEmail}}" style="color:#0E7C8B;text-decoration:underline;">{{.SupportEmail}}</a>.</p>
        </td></tr>

      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body>
</html>{{end}}
```

**Severity badge palette (pass `SeverityBG`/`SeverityFG`/`SeverityLabel` from Go):**

| Severity | Label | `SeverityBG` (light) | `SeverityFG` (light) | Dark note |
|---|---|---|---|---|
| High | `CRITICAL` | `#FEF3F2` | `#B42318` | badge bg/fg survive inversion; keep red FG |
| Medium | `WARNING` | `#FFFAEB` | `#B54708` | amber stays legible |
| Low / info | `INFO` | `#E6F4F3` | `#0E7C8B` | brand teal tint |

---

## 3. Plaintext Alternatives

### Reset (plaintext)

```text
{{define "reset.txt"}}Reset your WPMgr password
===========================

We received a request to reset the password for your WPMgr account
({{.Email}}).

Reset your password using this link:

{{.ResetURL}}

This link expires in {{.ExpiryMinutes}} minutes and can be used once.

If you didn't request a password reset, you can safely ignore this
email — your password won't change. If this wasn't you, contact us at
{{.SupportEmail}}.

--
WPMgr — fleet control for WordPress
You received this because a password reset was requested for {{.Email}}.{{end}}
```

### Activation (plaintext)

```text
{{define "activation.txt"}}Activate your WPMgr account
============================

Hi {{.Name}},

Welcome to WPMgr. To finish setting up your account, choose a password
and activate it using this link:

{{.ActivateURL}}

This activation link expires in {{.ExpiryMinutes}} minutes.

If you didn't expect this email, you can safely ignore it. Need help?
Contact {{.SupportEmail}}.

--
WPMgr — fleet control for WordPress
You received this because an account was created for {{.Email}} on WPMgr.{{end}}
```

> Wire as the `multipart/alternative` text part via go-mail `AddAlternativeString(mail.TypeTextPlain, …)`. For the remaining templates, generate the text part at runtime from the same HTML with `go-premailer` `TransformText()` so it never drifts. (The em dashes above are intentional in email copy; only the marketing landing site enforces the no-em-dash rule.)

---

## 4. Palette, Font Stack & Test Checklist

### Hex palette

| Token | Light | Dark | Use |
|---|---|---|---|
| Brand teal (primary) | `#0E7C8B` | `#2BB7AE` (CTA on dark) | CTA fill, links, accents, "mgr" |
| Teal dark (emphasis) | `#0A6675` | — | hover / pressed accents |
| Teal tint (band) | `#E6F4F3` | `#0F1716` (band on dark) | detail-table band, info badge bg |
| Page background | `#F4F6F6` | `#0F1716` | outer body |
| Card / content bg | `#FFFFFE` | `#15201F` | the 600px card |
| Ink (heading) | `#16302E` | `#EAF4F2` | H1, wordmark "wp" uses `#0A0A0A` |
| Wordmark ink | `#0A0A0A` | `#EAF4F2` | "wp" in lockup |
| Body text | `#3A4A48` | `#C4D2D0` | paragraphs |
| Muted / secondary | `#6B7280` | `#8FA29F` | footer, captions |
| Border / hairline | `#D8E2E1` (or `#E5E7EB`) | `#28403D` | dividers, card border, table rules |
| CTA text | `#FFFFFE` | `#0F1716` | button label |
| Alert HIGH bg/fg | `#FEF3F2` / `#B42318` | same | critical badge |
| Alert MED bg/fg | `#FFFAEB` / `#B54708` | same | warning badge |
| Alert LOW bg/fg | `#E6F4F3` / `#0E7C8B` | same | info badge |

> All values are 6-digit hex (no `oklch`, `var()`, `color-mix`). Near-white `#FFFFFE` / near-black `#0F1716` deliberately avoid pure `#FFF`/`#000` so dark-mode clients don't force-invert them.

### Font stacks

```
Sans (body/headings):
  -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu,
  Cantarell, "Helvetica Neue", Arial, sans-serif

Monospace (wordmark + mono link box):
  "SFMono-Regular", Consolas, "Liberation Mono", Menlo, Monaco,
  "Courier New", monospace
```

No `@font-face`/webfonts — they fail in Gmail and classic Outlook. Design is built for the fallback.

### Accessibility / QA checklist

**Accessibility**
- [ ] `<html lang="en">` set; single logical single-column reading order.
- [ ] Every layout `<table role="presentation">`; CTA `<a role="button">`.
- [ ] Inline SVG mark has `role="img"` + `aria-label`; MSO PNG fallback has meaningful `alt`; preheader is the hidden first text.
- [ ] Body text ≥ 14–16px; line-height ≥ 1.5; no critical content inside images.
- [ ] Contrast ≥ 4.5:1 in **both** light and dark: body `#3A4A48`/`#C4D2D0`, CTA `#FFFFFE` on `#0E7C8B` (light) and `#0F1716` on `#2BB7AE` (dark) both verified.
- [ ] Plaintext `multipart/alternative` part attached for every send.

**Cross-client rendering**
- [ ] Apple Mail macOS + iOS (light + dark) — SVG mark, prefers-color-scheme honored.
- [ ] Gmail web + iOS app + Android app — `<style>`-stripped fallback looks correct; PNG mark shows (SVG dropped); app force-inversion legible on `#FFFFFE` band.
- [ ] Outlook classic Windows (Word engine) — VML rounded button renders teal; ghost table holds 600px; no border-radius loss on the button; PNG mark shows.
- [ ] New Outlook / Outlook.com (Chromium) — modern CSS OK, MSO conditionals ignored (so `<a>` button path used), dark rewrite legible.
- [ ] Yahoo / AOL — embedded styles ignored; inline styles carry the design.
- [ ] Images-off: all text + CTA still present (nothing critical in an image).

**Structural / deliverability**
- [ ] Total HTML < 102KB (avoid Gmail "[Message clipped]").
- [ ] All links + logo built from `{{.BaseURL}}` (never inbound Host header).
- [ ] All assets HTTPS; PNG logo hosted at 2×, displayed 48×48 with explicit `width`/`height`.
- [ ] No `<script>`, `<form>`, `on*`, external `<link>` stylesheets.
- [ ] Sending domain has SPF + DKIM + DMARC (aligned From) — required or branding work lands in spam.
- [ ] No tokens/secrets logged; reset/activation URLs are single-use, short-TTL, scrubbed from logs.
- [ ] Seed-test on real clients via Litmus or Email on Acid before relying on a template (emulators miss app-level inversion + Gmail style-stripping).

---

Integration files map cleanly to `apps/api/internal/email/templates/`: `layout.tmpl` (head/header/footer/button `{{define}}` blocks) + one file per email (`activation.tmpl`, `invite.tmpl`, `reset.tmpl`, `password_changed.tmpl`, `verify_email.tmpl`, `alert.tmpl`), embedded via `embed.FS` and rendered through the `email.Render(name, data)` helper, sent with go-mail HTML + plaintext multipart, ideally inside the recommended `send_email` River job for durable retries.