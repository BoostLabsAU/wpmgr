// CodeMirrorEditor.tsx — lazy chunk (never imported directly; only via
// CodeEditor which React.lazy()s this module).
//
// This file is intentionally NOT re-exported from any barrel — it only enters
// the bundle as a split chunk loaded on demand when the editor first mounts.
//
// Language extension imports are plain static imports inside the chunk itself.
// Because this whole module is already lazy (the chunk boundary is at the
// React.lazy boundary in CodeEditor.tsx), the language grammars never enter
// the initial bundle.

import { useMemo } from "react";
import ReactCodeMirror from "@uiw/react-codemirror";

// CM core extensions.
import { EditorState } from "@codemirror/state";
import { EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightActiveLine } from "@codemirror/view";
import { defaultKeymap, history, historyKeymap } from "@codemirror/commands";
import { searchKeymap, search } from "@codemirror/search";
import {
  bracketMatching,
  foldGutter,
  syntaxHighlighting,
  defaultHighlightStyle,
} from "@codemirror/language";

// Language packs.
import { php } from "@codemirror/lang-php";
import { javascript } from "@codemirror/lang-javascript";
import { css } from "@codemirror/lang-css";
import { html } from "@codemirror/lang-html";
import { json } from "@codemirror/lang-json";
import { yaml } from "@codemirror/lang-yaml";
import { xml } from "@codemirror/lang-xml";
import { markdown } from "@codemirror/lang-markdown";

// Our Impeccable dark theme + syntax highlight.
import { impeccableTheme, impeccableHighlight } from "./codemirror-theme";

import type { EditorLanguage } from "./editor-lang";

export interface CodeMirrorEditorProps {
  value: string;
  onChange?: (value: string) => void;
  language: EditorLanguage;
  readOnly?: boolean;
  ariaLabel?: string;
  className?: string;
  height?: string;
  autoFocus?: boolean;
}

/** Map an EditorLanguage to the corresponding CodeMirror language extension. */
function languageExtension(lang: EditorLanguage) {
  switch (lang) {
    case "php":
      // html() returns LanguageSupport; .language extracts the Language object
      // that php()'s baseLanguage option expects. This makes PHP mode handle
      // <?php...?> islands inside HTML markup correctly.
      return php({ baseLanguage: html().language });
    case "javascript":
      return javascript({ jsx: true, typescript: true });
    case "css":
      return css();
    case "html":
      return html();
    case "json":
      return json();
    case "yaml":
      return yaml();
    case "xml":
      return xml();
    case "markdown":
      return markdown();
    case "plaintext":
    default:
      return [];
  }
}

export default function CodeMirrorEditor({
  value,
  onChange,
  language,
  readOnly = false,
  ariaLabel,
  className,
  height = "min(60vh, 480px)",
  autoFocus = false,
}: CodeMirrorEditorProps) {
  // Memoize on [language, readOnly] only — rebuilding extensions per keystroke
  // would cause CM to re-initialize the state on every character.
  const extensions = useMemo(
    () => [
      // Line numbers + gutter highlights.
      lineNumbers(),
      highlightActiveLineGutter(),
      highlightActiveLine(),

      // Code folding gutter.
      foldGutter(),

      // Bracket matching.
      bracketMatching(),

      // Undo/redo history.
      history(),

      // Full-document search panel (Ctrl/Cmd+F).
      search(),

      // Key bindings: default + history + search.
      keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap]),

      // Syntax highlight fallback for languages without a custom style.
      syntaxHighlighting(defaultHighlightStyle, { fallback: true }),

      // Our Impeccable theme + highlight.
      impeccableTheme,
      impeccableHighlight,

      // Language grammar.
      languageExtension(language),

      // Read-only modes: set BOTH the state facet AND the view editable flag.
      // Setting only one leaves partial interactivity (e.g. still accepts paste).
      EditorState.readOnly.of(readOnly),
      EditorView.editable.of(!readOnly),
    ],
    [language, readOnly],
  );

  return (
    <ReactCodeMirror
      value={value}
      onChange={onChange}
      readOnly={readOnly}
      editable={!readOnly}
      autoFocus={autoFocus}
      extensions={extensions}
      height={height}
      className={className}
      // Suppress the built-in basicSetup so we compose our own extension set.
      basicSetup={false}
      // Accessibility label forwarded to the editor container element.
      aria-label={ariaLabel}
    />
  );
}
