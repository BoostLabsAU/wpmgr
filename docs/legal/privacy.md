# Privacy Policy

_Last updated: 8 June 2026_

WPMgr is open-source, self-hostable software for managing a fleet of WordPress
sites — backups, updates, performance, and security. This Privacy Policy explains
what data the WPMgr agent plugin transmits, and what the optional hosted service
at **manage.wpmgr.app** collects when you choose to use it.

The full source code is public at
**https://github.com/mosamlife/wpmgr** (the agent plugin is MIT-licensed; the
control plane is AGPL-3.0). You can self-host the entire stack and keep 100% of
your data on your own infrastructure.

## Private and self-hosted by default

The WPMgr agent plugin has **no default endpoint** and sends **no data anywhere**
until you connect it to a control plane that you choose and configure. The plugin
is inert on activation. If you point it at a control plane you self-host, your
data never reaches us — you operate the receiving service and your own policies
apply.

## What the agent sends, and only to your control plane

Once you connect a site, the agent communicates **only** with the control-plane
URL you configured, and only for the management actions you (or your schedules)
initiate:

- **Site and environment metadata** — site URL, WordPress / PHP / server
  versions, active theme and plugins, and Site Health diagnostics. Used to show
  your site's status.
- **Update inventory** — the list of available core, plugin, and theme updates.
- **Backup archives (encrypted)** — when you run or schedule a backup, the agent
  creates an archive of your database and/or files, encrypts it, and uploads it
  to the storage destination your control plane configures. Archive contents may
  include your site's content and personal data; they are encrypted before they
  leave your server.
- **Rendered HTML** — for used-CSS optimization, the agent submits rendered HTML
  of selected pages so unused CSS can be computed.
- **Diagnostics and activity logs** — error logs, performance/cache statistics,
  and a record of management actions, so they can be surfaced in the dashboard.

Every agent request is verified with an Ed25519 signature tied to the key
established when you enroll the site. The agent does not execute arbitrary remote
code; it accepts only a fixed, named allow-list of commands.

## The hosted service (manage.wpmgr.app)

If you use the hosted WPMgr service rather than self-hosting, we also process:

- **Account information** — your name and email address, used to operate your
  account and send transactional email (verification, password reset, alerts).
- **The site data described above**, on your behalf, to provide the dashboard,
  backups, and management features you use.
- **Encrypted backup archives**, stored in cloud object storage.
- **Operational logs** needed to run and secure the service.

## What we do not do

We do **not** sell your data, and we do **not** share it with third parties for
advertising. The only sub-processors involved in the hosted service are our cloud
infrastructure provider (Google Cloud Platform — hosting and encrypted storage)
and a transactional email provider. Self-hosted deployments involve no
sub-processors at all.

## Security

- Agent-to-control-plane requests are Ed25519-signed and replay-protected.
- Backups are encrypted before they leave your server.
- All network traffic uses TLS.

## Your data, your control

- **Self-host** to keep all data on infrastructure you control.
- **Disconnect** the agent (or deactivate the plugin) at any time to stop all
  data transmission immediately.
- On the hosted service you can request access to, export of, or deletion of your
  account data by contacting us.

## Contact

Questions about this policy or your data: **mosam@mosamgor.com**.

## Changes

We may update this policy as the software evolves. Material changes will be
reflected here with a new "Last updated" date.
