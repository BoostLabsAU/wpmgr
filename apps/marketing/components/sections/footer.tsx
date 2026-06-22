import { Container } from "@/components/ui/primitives";
import { Icon } from "@/components/ui/icon";
import { Logo } from "@/components/ui/logo";
import { FOOTER_NAV, WORDPRESS_TRADEMARK_DISCLAIMER, SITE_CONFIG } from "@/lib/site";

export function SiteFooter() {
  return (
    <footer className="border-t border-[var(--border)] bg-[var(--muted)]/30 py-14">
      <Container>
        {/* Top grid: brand + nav mesh */}
        <div className="grid gap-10 sm:grid-cols-2 lg:grid-cols-5">
          {/* Brand column */}
          <div className="flex flex-col gap-4 lg:col-span-1">
            <a
              href="/"
              className="inline-flex items-center gap-2.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--ring)] rounded-sm"
              aria-label="WPMgr home"
            >
              <Logo />
            </a>
            <p className="text-sm leading-relaxed text-[var(--muted-foreground)] max-w-[220px]">
              Open-source, self-hostable WordPress fleet management you can run, read, and improve.
            </p>
            <a
              href={SITE_CONFIG.github}
              target="_blank"
              rel="noreferrer noopener"
              className="inline-flex items-center gap-2 text-sm text-[var(--muted-foreground)] hover:text-foreground transition-colors duration-[var(--duration-fast)]"
            >
              <Icon name="Github" size={15} />
              GitHub
            </a>
          </div>

          {/* Nav columns */}
          {FOOTER_NAV.map((group) => (
            <div key={group.label} className="flex flex-col gap-3">
              <h3 className="text-xs font-semibold uppercase tracking-[0.1em] text-foreground">
                {group.label}
              </h3>
              <ul className="flex flex-col gap-2">
                {group.items.map((item) => (
                  <li key={item.href}>
                    <a
                      href={item.href}
                      {...(item.external ? { target: "_blank", rel: "noreferrer noopener" } : {})}
                      className="text-sm text-[var(--muted-foreground)] hover:text-foreground transition-colors duration-[var(--duration-fast)]"
                    >
                      {item.label}
                    </a>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        {/* Bottom bar */}
        <div className="mt-12 flex flex-col gap-3 border-t border-[var(--border)] pt-8 text-xs text-[var(--muted-foreground)]">
          <p>
            Open source under AGPL-3.0 (control plane and dashboard) and MIT (WordPress agent). Contributions welcome.
          </p>
          <p>{WORDPRESS_TRADEMARK_DISCLAIMER}</p>
          <p className="mt-1">
            &copy; {new Date().getFullYear()} WPMgr contributors.
          </p>
        </div>
      </Container>
    </footer>
  );
}
