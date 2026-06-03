package engine

import "strings"

// alwaysKeep is the set of runtime-state pseudo-classes and ALL pseudo-elements
// whose presence in a selector forces the rule to be kept regardless of whether
// the static DOM snapshot we parse currently matches it.
//
// Why: cascadia matches a selector against a STATIC document. Runtime-state
// pseudos (:hover, :focus, :checked, …) describe transient states the page
// never exhibits in the parsed HTML, so a literal match would always fail and
// we'd wrongly strip the rule that styles the hover/focus state. Pseudo-
// elements (::before, ::after, …) generate boxes that are not part of the DOM
// tree cascadia can query at all. For both, the safe answer is: if the selector
// (after we strip these pseudos) targets an element that DOES exist, keep it;
// and a "bare" runtime pseudo (e.g. a standalone `:hover` or `*:hover`) is kept
// unconditionally because we cannot prove it dead.
//
// Keys are stored WITHOUT the leading colon(s); lookups normalise first.
var alwaysKeep = map[string]struct{}{
	// ---- runtime-state pseudo-classes (single colon) ----
	"hover":             {},
	"focus":             {},
	"focus-within":      {},
	"focus-visible":     {},
	"active":            {},
	"visited":           {},
	"checked":           {},
	"disabled":          {},
	"enabled":           {},
	"required":          {},
	"optional":          {},
	"valid":             {},
	"invalid":           {},
	"target":            {},
	"placeholder-shown": {},
	"default":           {},
	"indeterminate":     {},
	"read-only":         {},
	"read-write":        {},
	"autofill":          {},

	// ---- pseudo-elements (canonical double-colon; also written single-colon
	// in legacy CSS — both normalise to the bare name) ----
	"before":               {},
	"after":                {},
	"placeholder":          {},
	"selection":            {},
	"first-line":           {},
	"first-letter":         {},
	"marker":               {},
	"backdrop":             {},
	"file-selector-button": {},
}

// isRuntimePseudoName reports whether a bare pseudo name (no leading colons, no
// functional parens, lower-cased) is in the always-keep set.
func isRuntimePseudoName(name string) bool {
	_, ok := alwaysKeep[name]
	return ok
}

// stripRuntimePseudos removes every runtime-state pseudo-class and pseudo-
// element token from a SINGLE compound/complex selector (no commas — the caller
// splits the list first). It returns the selector with those pseudos removed and
// a bool reporting whether at least one was present.
//
// The result is intended to be fed to cascadia: by stripping the un-matchable
// pseudos we let cascadia answer "does the host element exist in the DOM?". A
// trailing combinator/whitespace left behind by stripping a final pseudo is
// trimmed so the remainder is still parseable (or empty, signalling a bare
// runtime pseudo the caller must keep).
//
// It is deliberately a lightweight scanner rather than a full CSS-selector
// parser: it understands strings, escapes, bracket/paren nesting, the pseudo
// boundary characters, and functional pseudos like :not(...) / ::slotted(...).
// Anything it cannot confidently classify is left in place (fail-safe — an
// unstripped token at worst makes cascadia keep the rule).
func stripRuntimePseudos(selector string) (stripped string, hadRuntimePseudo bool) {
	var b strings.Builder
	b.Grow(len(selector))

	runes := []rune(selector)
	n := len(runes)
	for i := 0; i < n; {
		c := runes[i]

		switch {
		case c == '\\': // escape — copy this and the next rune verbatim
			b.WriteRune(c)
			if i+1 < n {
				b.WriteRune(runes[i+1])
				i += 2
			} else {
				i++
			}
			continue

		case c == '\'' || c == '"': // attribute string — copy through the close quote
			quote := c
			b.WriteRune(c)
			i++
			for i < n {
				b.WriteRune(runes[i])
				if runes[i] == '\\' && i+1 < n {
					b.WriteRune(runes[i+1])
					i += 2
					continue
				}
				if runes[i] == quote {
					i++
					break
				}
				i++
			}
			continue

		case c == '[': // attribute selector — copy through the matching ]
			depth := 0
			for i < n {
				b.WriteRune(runes[i])
				if runes[i] == '[' {
					depth++
				} else if runes[i] == ']' {
					depth--
					if depth == 0 {
						i++
						break
					}
				}
				i++
			}
			continue

		case c == ':': // start of a pseudo (single or double colon)
			start := i
			i++ // past first ':'
			if i < n && runes[i] == ':' {
				i++ // past second ':' (pseudo-element)
			}
			// read the pseudo identifier
			nameStart := i
			for i < n && isIdentRune(runes[i]) {
				i++
			}
			name := strings.ToLower(string(runes[nameStart:i]))

			// functional pseudo? capture the (…) argument (balanced).
			var arg string
			hasArg := false
			if i < n && runes[i] == '(' {
				hasArg = true
				argStart := i
				depth := 0
				for i < n {
					if runes[i] == '(' {
						depth++
					} else if runes[i] == ')' {
						depth--
						if depth == 0 {
							i++
							break
						}
					}
					i++
				}
				arg = string(runes[argStart:i]) // includes the surrounding ()
			}

			if isRuntimePseudoName(name) {
				hadRuntimePseudo = true
				// Drop the whole pseudo token (and its arg). Do not emit anything.
				continue
			}
			// Not a runtime pseudo: keep the token verbatim. For structural
			// functional pseudos (:not, :is, :where, :nth-child, …) we keep the
			// argument as-is so cascadia still parses/matches it.
			b.WriteString(string(runes[start:nameStart]))
			b.WriteString(string(runes[nameStart : nameStart+len([]rune(name))]))
			if hasArg {
				b.WriteString(arg)
			}
			continue

		default:
			b.WriteRune(c)
			i++
		}
	}

	out := b.String()
	// Stripping a trailing pseudo can leave a dangling combinator or trailing
	// space (e.g. "a:hover" -> "a", but "a >:hover" -> "a >"). Trim trailing
	// combinators/space so the remainder is a valid selector (or empty).
	out = strings.TrimRight(out, " \t\n\r>+~")
	out = strings.TrimSpace(out)
	return out, hadRuntimePseudo
}

// isIdentRune reports whether r can appear in a CSS identifier (pseudo name).
// Hyphen is included so names like "focus-within" and "file-selector-button"
// read as one token.
func isIdentRune(r rune) bool {
	return r == '-' || r == '_' ||
		(r >= 'a' && r <= 'z') ||
		(r >= 'A' && r <= 'Z') ||
		(r >= '0' && r <= '9') ||
		r >= 0x80 // non-ASCII identifiers
}
