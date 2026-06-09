# "Additional Information" — paste into the wp.org submission form

Thank you for reviewing Fleet Agent for WPMgr.

**What it is.** This is the site-side agent for WPMgr, an open-source, self-hostable
platform for managing a fleet of WordPress sites (backups, updates, performance,
and security). Full source code is public:
https://github.com/mosamlife/wpmgr (this agent plugin is GPLv2-or-later for the
.org distribution; the codebase is otherwise MIT/AGPL, all GPL-compatible).

**External service / privacy disclosure.** The plugin contacts NO external service
by default — there is no default endpoint and it is inert on activation. It only
communicates once the site owner supplies a control-plane URL they choose and
completes a one-time signed enrollment. The control plane can be self-hosted by
the user, or our optional hosted service at https://manage.wpmgr.app. What data is
sent, when, and where is documented in the readme ("Privacy / What data is sent
and where"), with live links to the Terms (https://manage.wpmgr.app/terms) and
Privacy Policy (https://manage.wpmgr.app/privacy).

**No remote code execution.** The agent accepts only a fixed, named allow-list of
commands (backup, restore, update, cache operations, diagnostics). Every command
is verified with an Ed25519 signature tied to the key established at enrollment,
plus replay protection. There is no eval, no remote include, and no execution of
arbitrary remote PHP/SQL/shell. Core/plugin/theme updates use WordPress's own
Upgrader against packages from WordPress.org.

**Updates.** This .org build contains no self-updater; updates come from
WordPress.org like any hosted plugin.

**On the plugin name (re: the Plugin Check "wp" trademark notices).** The plugin
name follows the recommended "{distinguishable-name} for {Brand}" pattern —
"Fleet Agent for WPMgr" — and the slug ("fleet-agent-for-wpmgr") begins with
"fleet", not "wp". "WPMgr" is our own project/brand (wpmgr.app), used as a
trailing qualifier, not as the leading term, and the plugin does not imply any
affiliation with or endorsement by the WordPress project. The three
"trademarked_term" notices from Plugin Check flag the substring "wp" inside our
brand name; aside from those, Plugin Check reports 0 errors. We're happy to adjust
the display name if the team prefers.

**Plugin Check.** Run against the latest WordPress with the Plugin Check plugin:
0 errors, with only the 3 "wp"-in-brand-name trademark notices noted above.

Contact: mosam@mosamgor.com
