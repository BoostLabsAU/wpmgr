// Backup component definitions — singular wire values per the canonical m49 contract.
// Imported by backup-scope-fields.tsx (the component layer) and any non-component
// code that needs the definitions without pulling in React.

/**
 * Singular-value component identifiers sent over the wire to the CP.
 * The OpenAPI spec has a known plural/singular mismatch for some values;
 * these are the CORRECT singular values that Go and the PHP agent validate
 * against. Never send "plugins", "themes", or "uploads" (plural).
 */
export type BackupComponent =
  | "plugin"
  | "theme"
  | "upload"
  | "wp-content"
  | "db"
  | "core";

export interface BackupComponentOption {
  value: BackupComponent;
  label: string;
  description: string;
}

export const BACKUP_COMPONENT_OPTIONS: BackupComponentOption[] = [
  {
    value: "plugin",
    label: "Plugins",
    description: "wp-content/plugins subtree.",
  },
  {
    value: "theme",
    label: "Themes",
    description: "wp-content/themes subtree.",
  },
  {
    value: "upload",
    label: "Uploads",
    description: "wp-content/uploads subtree (often the largest).",
  },
  {
    value: "wp-content",
    label: "Other wp-content",
    description: "mu-plugins, languages, drop-ins, custom directories.",
  },
  {
    value: "db",
    label: "Database",
    description: "Full database dump (all tables).",
  },
];

/** WordPress core — separate opt-in (ABSPATH: wp-admin, wp-includes, root PHP files). */
export const CORE_COMPONENT_OPTION: BackupComponentOption = {
  value: "core",
  label: "WordPress core",
  description:
    "ABSPATH: wp-admin, wp-includes, and root PHP files (wp-config.php etc). Explicit opt-in only.",
};
