# Changelog

All notable changes to WPMgr are documented here.
Format: Keep a Changelog (keepachangelog.com). Versioning: SemVer (semver.org).
House rules: no em dashes, no en dashes, no competitor names. Use "to" for ranges.

## [Unreleased]

### Added
- Performance Suite, per site and across your whole portfolio. Turn on full-page caching and WPMgr serves anonymous pages as pre-gzipped HTML straight from disk, with logged-in, per-role, mobile, and per-query cache variants, bypass rules for cart and checkout pages, a configurable refresh interval, manual and automatic purge, and a preload warmer. The server fast-path installs automatically on Apache, with a copy-paste snippet for nginx and built-in handling for OpenLiteSpeed and WP Engine.
- Asset optimization that makes pages lighter without breaking them: CSS and JS minification, JS delay, font display-swap and self-hosting, lazy-load with width and height and srcset preserved, bloat removal, CDN URL rewriting with encrypted credentials, and on-demand or scheduled database cleanup. A failed optimization never breaks the page, it simply falls back to the original asset.
- Remove Unused CSS strips the rules a page does not use and inlines only what it needs, computed by WPMgr's own engine with no headless browser and no third-party service. Interactive states like hover and focus are always kept, a per-site safelist covers anything added by scripts, and results are cached and shared across pages with the same structure. On a cache miss or any failure the full CSS is served, so rendering is never blocked.
- Per-site controls plus portfolio bulk actions: save the performance config for one site, purge the cache across many sites at once, or apply a safe, balanced, or aggressive preset to a whole group in one run.

## [0.16.9] - 2026-06-03

### Added
- Operator account recovery: a one-shot, env-driven seeder (`WPMGR_RECOVER_ACCOUNTS`) recreates a deleted user and re-attaches it as owner of an existing organisation it had lost access to, then logs a one-time set-password link. Lets an instance operator restore an account whose organisation and sites are still intact after an accidental user deletion, without touching the database by hand.

## [0.16.8] - 2026-06-03

### Fixed
- The superadmin orphaned-organisation cleanup added in 0.16.7 silently failed to remove empty organisations: deleting a user left their now-empty organisation behind and the organisation count unchanged. This was a database privilege interaction with the append-only audit log; empty orphaned organisations are now removed reliably when their sole owner is deleted, and organisations that still own sites are still kept and flagged.

## [0.16.7] - 2026-06-03

### Changed
- Superadmin user delete now tidies up the organisations that user solely owned: an organisation left with no members and no sites is removed automatically, and the user list shows an accurate organisation count per user.

### Fixed
- Deleting a superadmin-managed user no longer leaves behind an empty, unreachable organisation. When such an organisation still has sites, it is kept and the operator is warned to reassign or remove it rather than losing track of those sites.

## [0.16.5] - 2026-06-03

### Fixed
- Open self-serve sign up failed for everyone after the first account because every new workspace was created with the same internal identifier. Each sign up now gets a unique one, so registrations no longer collide.

## [0.16.0] - 2026-06-03

### Added
- Superadmin area for instance operators: a cross-tenant user list with search, the ability to delete or disable a user, resend a verification email, and an instance stats overview. Visible only to accounts listed in the superadmin allowlist; it cannot be granted through the app.

## [0.15.5] - 2026-06-02

### Added
- Site sharing now emails the person you share with: a new user gets a branded invite link to set their own password, and an existing user gets a notification that a site was shared with them and is ready in their account.

## [0.15.4] - 2026-06-02

### Fixed
- Creating your first organisation from the welcome screen returned a 403; org create, list, and switch no longer require an existing organisation, and creating one now drops you straight into it.

## [0.15.3] - 2026-06-02

### Added
- Invite teammates to an organisation by email: they receive a branded link and set their own password, so admins no longer choose a password on their behalf.
- A welcome screen that invites you to create an organisation when your account does not belong to one yet.

### Changed
- Trying to sign up with an email that already has an account now sends a short "you already have an account" email pointing to sign in or password reset, instead of doing nothing.

### Fixed
- Saving SMTP settings could fail with a server error; the settings now save reliably.

## [0.15.0] - 2026-06-02

### Added
- UI-configured SMTP: admins set SMTP credentials in Settings, the password is stored encrypted, and a test-send button confirms delivery before saving.
- Self-serve password reset and a strengthened change-password flow; changing a password immediately revokes all other active sessions.
- Open self-serve sign up with email verification: new users register with their email address and gain access only after clicking a verification link.

[0.15.0]: https://github.com/mosamlife/wpmgr/releases/tag/v0.15.0
