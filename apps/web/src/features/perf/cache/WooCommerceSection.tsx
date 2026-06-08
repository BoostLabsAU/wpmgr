import { SettingRow } from "../components/SettingRow";
import { SettingsCard } from "../components/SettingsCard";
import type { PerfConfig } from "../types";

// WooCommerce cart-session caching (#169).
//
// Surfaces the woo_cacheable_session toggle that lets catalog pages (shop,
// product, category, home) be served from the page cache even when the visitor
// has an active cart session. The cart total and mini-cart update live in the
// browser via WooCommerce cart fragments. Cart, checkout, and account pages are
// never cached regardless of this setting.
//
// The toggle is gated on woo_theme_fragments_supported (agent-reported). When
// the active theme does not expose the wc-cart-fragments script the feature is
// unsafe to enable, so the row is rendered disabled with a short explanation.

export interface WooCommerceSectionProps {
  config: PerfConfig;
  save: (patch: Partial<PerfConfig>) => void;
  disabled: boolean;
  saving: boolean;
}

export function WooCommerceSection({
  config,
  save,
  disabled,
  saving,
}: WooCommerceSectionProps) {
  const fragmentsSupported = config.woo_theme_fragments_supported;
  const rowDisabled = disabled || !fragmentsSupported;

  const description = fragmentsSupported
    ? "Catalog pages (shop, product, category, home) are served from cache even to visitors who have items in their cart. The cart total and mini-cart update live in the browser. Cart, checkout, and account pages are never cached."
    : "Available once your active theme exposes WooCommerce cart fragments. We detect this automatically.";

  return (
    <SettingsCard
      title="WooCommerce"
      description="Cache settings for WooCommerce stores."
    >
      <SettingRow
        label="Cache WooCommerce pages for shoppers with a cart"
        description={description}
        checked={config.woo_cacheable_session}
        onChange={(v) => save({ woo_cacheable_session: v })}
        disabled={rowDisabled}
        saving={saving}
      />
    </SettingsCard>
  );
}
