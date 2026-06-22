import { SiteHeader } from "@/components/sections/header";
import { SiteFooter } from "@/components/sections/footer";
import { ScrollProgress } from "@/components/motion/scroll-progress";

/**
 * (marketing) route group layout. Wraps all marketing pages with the shared
 * site header, footer, and scroll-progress indicator. The route group
 * `(marketing)` adds no URL segment.
 */
export default function MarketingLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <>
      <ScrollProgress />
      <SiteHeader />
      <main>{children}</main>
      <SiteFooter />
    </>
  );
}
