// codemirror-theme.ts — Impeccable dark theme for CodeMirror 6.
//
// Reads our design-system CSS variables so the editor stays in sync with
// the rest of the dashboard in both light and dark mode. All token
// references use real variable names verified against globals.css.
//
// The theme is always dark (dark:true) because the editor panel is always
// rendered on a dark surface (--color-muted / --color-card in dark mode).
// In light mode the --color-muted is a very light tint; CodeMirror's own
// contrast rules keep the text readable either way.

import { EditorView } from "@codemirror/view";
import { HighlightStyle, syntaxHighlighting } from "@codemirror/language";
import { tags } from "@lezer/highlight";

// ── Base editor theme ────────────────────────────────────────────────────────

export const impeccableTheme = EditorView.theme(
  {
    // Root container: use the muted surface and foreground from our tokens.
    "&": {
      color: "var(--color-foreground)",
      backgroundColor: "var(--color-muted)",
      fontFamily: "var(--font-mono)",
      fontSize: "12px",
      lineHeight: "1.65",
    },

    // The actual content/scroll area.
    ".cm-scroller": {
      fontFamily: "inherit",
      overflow: "auto",
    },

    ".cm-content": {
      caretColor: "var(--color-primary)",
      padding: "12px 0",
    },

    // Gutter (line numbers, fold controls).
    ".cm-gutters": {
      backgroundColor: "var(--color-muted)",
      color: "var(--color-muted-foreground)",
      border: "none",
      borderRight: "1px solid var(--color-border)",
      minWidth: "3rem",
    },

    ".cm-lineNumbers .cm-gutterElement": {
      paddingRight: "0.75rem",
      paddingLeft: "0.5rem",
      color: "var(--color-muted-foreground)",
      opacity: "0.7",
    },

    // Current active line.
    ".cm-activeLine": {
      backgroundColor: "color-mix(in oklch, var(--color-primary) 6%, transparent)",
    },

    ".cm-activeLineGutter": {
      backgroundColor: "color-mix(in oklch, var(--color-primary) 8%, transparent)",
      color: "var(--color-foreground)",
      opacity: "1",
    },

    // Selection highlight.
    "&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection":
      {
        backgroundColor:
          "color-mix(in oklch, var(--color-primary) 22%, transparent)",
      },

    // Matching bracket highlight.
    "&.cm-focused .cm-matchingBracket": {
      backgroundColor:
        "color-mix(in oklch, var(--color-info) 20%, transparent)",
      color: "var(--color-foreground)",
      fontWeight: "600",
      borderRadius: "2px",
    },

    // Search match highlight.
    ".cm-searchMatch": {
      backgroundColor:
        "color-mix(in oklch, var(--color-warning) 30%, transparent)",
      outline: "1px solid color-mix(in oklch, var(--color-warning) 50%, transparent)",
    },

    ".cm-searchMatch.cm-searchMatch-selected": {
      backgroundColor:
        "color-mix(in oklch, var(--color-warning) 55%, transparent)",
    },

    // Cursor.
    ".cm-cursor, .cm-dropCursor": {
      borderLeftColor: "var(--color-primary)",
      borderLeftWidth: "2px",
    },

    // Focus ring on the editor outer wrapper.
    "&.cm-focused": {
      outline: "none",
    },

    "&.cm-focused .cm-editor": {
      outline: "none",
    },

    // Wrap the whole editor in a ring when focused (applied on the outer
    // DialogContent ancestor — we just need the inner element to not double-ring).
    ".cm-editor.cm-focused": {
      outline: "2px solid var(--color-ring)",
      outlineOffset: "0",
      borderRadius: "inherit",
    },

    // Fold gutter triangles.
    ".cm-foldGutter span": {
      color: "var(--color-muted-foreground)",
      opacity: "0.6",
    },

    // Search panel (Ctrl/Cmd+F).
    ".cm-panels": {
      backgroundColor: "var(--color-card)",
      borderTop: "1px solid var(--color-border)",
      color: "var(--color-foreground)",
    },

    ".cm-panels input": {
      backgroundColor: "var(--color-input)",
      color: "var(--color-foreground)",
      border: "1px solid var(--color-border)",
      borderRadius: "4px",
      padding: "2px 6px",
      fontFamily: "var(--font-mono)",
      fontSize: "12px",
    },

    ".cm-panels button": {
      backgroundColor: "var(--color-accent)",
      color: "var(--color-accent-foreground)",
      border: "1px solid var(--color-border)",
      borderRadius: "4px",
      padding: "2px 8px",
      cursor: "pointer",
    },

    ".cm-panels button:focus": {
      outline: "2px solid var(--color-ring)",
    },

    // Tooltip (hover type info etc.).
    ".cm-tooltip": {
      backgroundColor: "var(--color-popover)",
      border: "1px solid var(--color-border)",
      color: "var(--color-popover-foreground)",
      borderRadius: "6px",
      boxShadow: "0 4px 12px oklch(0% 0 0 / 0.25)",
    },
  },
  { dark: true },
);

// ── Syntax highlight style ────────────────────────────────────────────────────

// Token palette: map @lezer/highlight tags to our colour tokens.
// We use --color-primary (teal) for keywords/operators, the success/warning/
// info/destructive families for string/number/comment/function, matching the
// intent of those semantic tokens without inventing new variables.
const highlight = HighlightStyle.define([
  // Keywords: php, js, css, html keywords (e.g. if/else/function, @media).
  {
    tag: tags.keyword,
    color: "var(--color-primary)",
    fontWeight: "600",
  },
  // Operators: +, -, =, =>, ->, etc.
  {
    tag: [tags.operator, tags.punctuation],
    color: "var(--color-muted-foreground)",
  },
  // Strings (including template literals, attribute values in HTML).
  {
    tag: [tags.string, tags.special(tags.string)],
    color: "var(--color-success)",
  },
  // Numbers.
  {
    tag: tags.number,
    color: "var(--color-warning)",
  },
  // Comments.
  {
    tag: tags.comment,
    color: "var(--color-muted-foreground)",
    fontStyle: "italic",
    opacity: "0.75",
  },
  // Function / method names at definition and call sites.
  {
    tag: [tags.function(tags.variableName), tags.function(tags.propertyName)],
    color: "var(--color-info)",
  },
  // HTML tag names (<div>, <php:echo>, etc.).
  {
    tag: tags.tagName,
    color: "var(--color-primary)",
  },
  // HTML/XML attribute names.
  {
    tag: tags.attributeName,
    color: "var(--color-warning)",
  },
  // HTML/XML attribute values.
  {
    tag: tags.attributeValue,
    color: "var(--color-success)",
  },
  // Variable / identifier names.
  {
    tag: tags.variableName,
    color: "var(--color-foreground)",
  },
  // Property access (object.prop).
  {
    tag: tags.propertyName,
    color: "var(--color-info)",
    opacity: "0.9",
  },
  // Type names / class names.
  {
    tag: [tags.typeName, tags.className, tags.namespace],
    color: "var(--color-warning)",
  },
  // Boolean + null literals.
  {
    tag: [tags.bool, tags.null],
    color: "var(--color-primary)",
    fontWeight: "600",
  },
  // Regular expressions.
  {
    tag: tags.regexp,
    color: "var(--color-destructive)",
  },
  // Headings in Markdown.
  {
    tag: tags.heading,
    color: "var(--color-primary)",
    fontWeight: "700",
  },
  // URL / link in Markdown.
  {
    tag: tags.url,
    color: "var(--color-info)",
    textDecoration: "underline",
  },
  // Meta / preprocessor (#define, PHP opening tag, etc.).
  {
    tag: tags.meta,
    color: "var(--color-muted-foreground)",
  },
  // Invalid / error tokens.
  {
    tag: tags.invalid,
    color: "var(--color-destructive)",
  },
]);

export const impeccableHighlight = syntaxHighlighting(highlight);
