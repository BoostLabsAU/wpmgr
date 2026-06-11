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
// The toggle gate is woo_theme_fragments_supported (agent-reported tri-state):
//   true  — probed, supported: toggle is enabled with the normal description.
//   null  — never probed: toggle is disabled with a "checking" message.
//   false — probed, unsupported: toggle is permanently disabled; the copy
//           explains the theme has replaced the standard mini-cart.

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

  // null  = never probed yet
  // false = probed, not supported
  // true  = probed, supported
  const rowDisabled =
    disabled || fragmentsSupported !== true;

  let description: string;
  if (fragmentsSupported === true) {
    description =
      "Catalog pages (shop, product, category, home) are served from cache even to visitors who have items in their cart. The cart total and mini-cart update live in the browser. Cart, checkout, and account pages are never cached.";
  } else if (fragmentsSupported === null) {
    description =
      "Checking your theme for cart fragments support. This happens automatically the next time your store's pages are visited.";
  } else {
    // false: probed, genuinely unsupported
    description =
      "Your active theme replaces the standard WooCommerce mini cart, so cart aware caching can't be enabled safely.";
  }

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
