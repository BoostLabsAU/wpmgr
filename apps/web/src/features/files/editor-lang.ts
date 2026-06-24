// editor-lang.ts — file-extension to EditorLanguage mapping.
// Kept in its own module so CodeEditor.tsx exports only React components
// (required for fast-refresh to work correctly in dev).

export type EditorLanguage =
  | "php"
  | "javascript"
  | "css"
  | "html"
  | "json"
  | "yaml"
  | "xml"
  | "markdown"
  | "plaintext";

/**
 * Map a file path/name to an EditorLanguage by extension.
 * Falls back to "plaintext" for unknown extensions.
 */
export function langFromPath(path: string): EditorLanguage {
  // Extract the last extension, lowercased.
  const name = path.split("/").pop() ?? path;
  // Handle dotfiles (.env, .htaccess) and compound extensions (.phtml etc.).
  const dotIndex = name.lastIndexOf(".");
  const ext = dotIndex > 0 ? name.slice(dotIndex + 1).toLowerCase() : "";

  switch (ext) {
    // PHP family.
    case "php":
    case "phtml":
    case "phps":
    case "php3":
    case "php4":
    case "php5":
    case "php7":
    case "php8":
    case "phar":
      return "php";

    // JavaScript / TypeScript family.
    case "js":
    case "jsx":
    case "mjs":
    case "cjs":
    case "ts":
    case "tsx":
      return "javascript";

    // CSS family.
    case "css":
    case "scss":
    case "sass":
    case "less":
      return "css";

    // HTML / template family.
    case "html":
    case "htm":
      return "html";

    // JSON.
    case "json":
    case "jsonc":
      return "json";

    // YAML.
    case "yaml":
    case "yml":
      return "yaml";

    // XML / SVG.
    case "xml":
    case "svg":
      return "xml";

    // Markdown.
    case "md":
    case "markdown":
    case "mdx":
      return "markdown";

    // Everything else.
    default:
      return "plaintext";
  }
}
