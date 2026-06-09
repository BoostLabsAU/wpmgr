import { createFileRoute, Link } from "@tanstack/react-router";
import { FleetHubLogo, Wordmark } from "@/components/brand/logo";

export const Route = createFileRoute("/terms")({
  component: TermsPage,
});

function TermsPage() {
  return (
    <div className="min-h-dvh bg-[var(--color-background)] text-[var(--color-foreground)]">
      <div className="mx-auto max-w-3xl px-6 py-14">
        {/* Brand lockup */}
        <div className="mb-10 flex items-center gap-2.5">
          <FleetHubLogo size={26} />
          <Wordmark className="text-base" />
        </div>

        {/* Page content */}
        <article className="prose-custom">
          <h1 className="mb-1 text-3xl font-bold tracking-tight">Terms of Service</h1>
          <p className="mb-8 text-sm text-[var(--color-muted-foreground)]">Last updated: 8 June 2026</p>

          <p className="mb-6 leading-relaxed text-[var(--color-muted-foreground)]">
            WPMgr is open-source software for managing a fleet of WordPress sites, plus an optional
            hosted service at <strong className="text-[var(--color-foreground)]">manage.wpmgr.app</strong>.
            These Terms govern your use of the hosted service. The software itself is governed by its
            open-source licenses: the agent plugin is <strong className="text-[var(--color-foreground)]">MIT</strong>-licensed
            and the control plane is <strong className="text-[var(--color-foreground)]">AGPL-3.0</strong>. The full source
            is at{" "}
            <a
              href="https://github.com/mosamlife/wpmgr"
              target="_blank"
              rel="noreferrer noopener"
              className="text-[#12B5BE] underline underline-offset-4 hover:opacity-80"
            >
              https://github.com/mosamlife/wpmgr
            </a>
            .
          </p>

          <Section title="You can self-host">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              You are free to run the entire WPMgr stack — control plane and agent — on your own
              infrastructure under the open-source licenses above. The hosted service is a convenience,
              not a requirement. If you self-host, these hosted-service Terms do not apply to you; only
              the software licenses do.
            </p>
          </Section>

          <Section title="Your responsibilities">
            <ul className="list-disc space-y-2 pl-5 text-[var(--color-muted-foreground)]">
              <li className="leading-relaxed">
                You may only connect WordPress sites that{" "}
                <strong className="text-[var(--color-foreground)]">you own or are authorized to manage</strong>.
              </li>
              <li className="leading-relaxed">
                You are responsible for the content of the sites you connect and for complying with
                applicable law.
              </li>
              <li className="leading-relaxed">
                You authorize the agent to perform the management actions you request — backups,
                updates, performance optimization, and security operations — on the sites you connect.
              </li>
              <li className="leading-relaxed">
                Keep your account credentials secure. You are responsible for activity under your account.
              </li>
            </ul>
          </Section>

          <Section title="Acceptable use">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              Do not use the hosted service to store or distribute unlawful content, to interfere with
              the service or other users, or to attempt to gain unauthorized access to any system.
            </p>
          </Section>

          <Section title="Backups">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              The service provides backups on a best-effort basis.{" "}
              <strong className="text-[var(--color-foreground)]">
                You are responsible for verifying that your backups restore correctly
              </strong>{" "}
              and for retaining independent copies of business-critical data. We are not liable for
              data that cannot be recovered.
            </p>
          </Section>

          <Section title="No warranty">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              The software and the hosted service are provided{" "}
              <strong className="text-[var(--color-foreground)]">
                "as is", without warranty of any kind
              </strong>
              , express or implied, including merchantability, fitness for a particular purpose, and
              non-infringement, to the maximum extent permitted by law. This mirrors the disclaimers in
              the open-source licenses.
            </p>
          </Section>

          <Section title="Limitation of liability">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              To the maximum extent permitted by law, WPMgr and its contributors are not liable for any
              indirect, incidental, special, or consequential damages, or for loss of data, profits, or
              business, arising from your use of the software or the hosted service.
            </p>
          </Section>

          <Section title="Suspension and termination">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              You may disconnect any site, or delete your account, at any time. We may suspend or
              terminate access that violates these Terms or that poses a security or operational risk to
              the service or other users.
            </p>
          </Section>

          <Section title="Changes">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              We may update these Terms as the software and service evolve. Material changes will be
              reflected here with a new "Last updated" date.
            </p>
          </Section>

          <Section title="Contact">
            <p className="leading-relaxed text-[var(--color-muted-foreground)]">
              Questions about these Terms:{" "}
              <a
                href="mailto:mosam@mosamgor.com"
                className="text-[#12B5BE] underline underline-offset-4 hover:opacity-80"
              >
                mosam@mosamgor.com
              </a>
              .
            </p>
          </Section>
        </article>

        {/* Footer nav */}
        <footer className="mt-14 flex flex-wrap items-center gap-4 border-t border-[var(--color-border)] pt-6 text-sm text-[var(--color-muted-foreground)]">
          <a
            href="https://manage.wpmgr.app"
            className="hover:text-[var(--color-foreground)]"
          >
            manage.wpmgr.app
          </a>
          <span aria-hidden="true" className="text-[var(--color-border)]">|</span>
          <Link
            to="/privacy"
            className="hover:text-[var(--color-foreground)]"
          >
            Privacy Policy
          </Link>
        </footer>
      </div>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="mb-8">
      <h2 className="mb-3 text-xl font-semibold tracking-tight text-[var(--color-foreground)]">
        {title}
      </h2>
      {children}
    </section>
  );
}
