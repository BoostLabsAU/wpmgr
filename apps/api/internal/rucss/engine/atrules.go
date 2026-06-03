package engine

import "strings"

// classifyAtRule maps a block at-rule (already split into keyword + prelude +
// parsed children) to the right node kind. @media/@supports/@container/@layer
// (with a block) recurse; @keyframes is kept verbatim and gated on animation
// references; @font-face is gated on font-family references; anything else with
// a block is treated as a nested rule list so its inner rules are still purged.
func classifyAtRule(name, prelude string, children []node) node {
	bare := strings.TrimPrefix(name, "@")
	switch {
	case isKeyframesName(bare):
		return node{kind: nodeAtKeyframes, atName: name, atPrelude: prelude, children: children}
	case bare == "font-face":
		// @font-face's "children" are really declarations; tdewolff parses the
		// block as a declaration list, surfaced here as a single ruleset-like
		// child or as direct decls. We re-walk children to collect decls.
		return node{kind: nodeAtFontFace, atName: name, atPrelude: prelude, decls: collectDecls(children), children: children}
	case isConditionalGroup(bare):
		return node{kind: nodeAtRuleNest, atName: name, atPrelude: prelude, children: children}
	default:
		// Unknown block at-rule (e.g. @page with nested, vendor at-rules): keep
		// its inner rules subject to purging by treating it as a nesting group.
		return node{kind: nodeAtRuleNest, atName: name, atPrelude: prelude, children: children}
	}
}

func isKeyframesName(bare string) bool {
	return bare == "keyframes" ||
		bare == "-webkit-keyframes" ||
		bare == "-moz-keyframes" ||
		bare == "-o-keyframes"
}

// isConditionalGroup reports whether the at-rule is a conditional group whose
// body is a rule list we should recurse into and purge.
func isConditionalGroup(bare string) bool {
	switch bare {
	case "media", "supports", "container", "layer", "scope", "document",
		"-moz-document":
		return true
	}
	return false
}

// collectDecls flattens declarations out of the children a block at-rule parsed
// into. tdewolff parses @font-face as a declaration-list, but depending on the
// grammar path the decls may arrive either as direct children (rare) or folded
// into a single ruleset child. We harvest from both shapes.
func collectDecls(children []node) []decl {
	var out []decl
	for _, c := range children {
		out = append(out, c.decls...)
	}
	return out
}

// keyframesName extracts the animation name a @keyframes block defines from its
// prelude (the token(s) between `@keyframes` and `{`). It lower-cases nothing —
// keyframe / animation names are case-sensitive identifiers.
func keyframesName(prelude string) string {
	return strings.TrimSpace(prelude)
}

// fontFamilyOf returns the font-family name(s) declared by a @font-face block,
// normalised (quotes stripped, lower-cased) for matching against animation/
// font-family references collected from surviving rules.
func fontFamilyOf(decls []decl) string {
	for _, d := range decls {
		if d.prop == "font-family" {
			return normFontFamily(d.value)
		}
	}
	return ""
}

// normFontFamily strips surrounding quotes and lower-cases a font-family token
// so `"Inter"`, `'Inter'`, and `Inter` all compare equal.
func normFontFamily(v string) string {
	v = strings.TrimSpace(v)
	v = strings.Trim(v, `"'`)
	return strings.ToLower(strings.TrimSpace(v))
}

// referencedFontFamilies scans a surviving declaration's value for every
// font-family name it references. It handles both the `font-family:` shorthand
// list and the `font:` shorthand (where the family is the tail). Each token is
// normalised the same way as normFontFamily so a @font-face family matches.
func referencedFontFamilies(prop, value string) []string {
	if prop != "font-family" && prop != "font" {
		return nil
	}
	var out []string
	for _, part := range strings.Split(value, ",") {
		out = append(out, splitFontTokens(part)...)
	}
	return out
}

// splitFontTokens breaks one comma segment of a font/font-family value into the
// candidate family names. For `font:` shorthand the leading size/weight tokens
// are also emitted, which is harmless (they won't match a real family name).
func splitFontTokens(seg string) []string {
	seg = strings.TrimSpace(seg)
	if seg == "" {
		return nil
	}
	// Quoted family — take it whole.
	if (strings.HasPrefix(seg, `"`) && strings.HasSuffix(seg, `"`)) ||
		(strings.HasPrefix(seg, `'`) && strings.HasSuffix(seg, `'`)) {
		return []string{normFontFamily(seg)}
	}
	// Unquoted family may be multi-word (e.g. `Times New Roman`); also the font
	// shorthand has size/weight tokens. Emit the whole trimmed segment AND each
	// word, so both `Times New Roman` and a single-word family resolve.
	out := []string{normFontFamily(seg)}
	for _, w := range strings.Fields(seg) {
		out = append(out, normFontFamily(w))
	}
	return out
}

// referencedAnimations scans a surviving declaration for the @keyframes names it
// uses via `animation` / `animation-name`. The `animation` shorthand interleaves
// the name with durations/timing-functions/keywords, so we emit every
// non-numeric, non-keyword token as a candidate name (a superset is safe — it
// can only keep a keyframes block, never wrongly drop one).
func referencedAnimations(prop, value string) []string {
	if prop != "animation" && prop != "animation-name" &&
		prop != "-webkit-animation" && prop != "-webkit-animation-name" {
		return nil
	}
	var out []string
	for _, seg := range strings.Split(value, ",") {
		for _, tok := range strings.Fields(seg) {
			t := strings.TrimSpace(tok)
			if t == "" || isAnimationKeyword(t) || looksNumeric(t) {
				continue
			}
			out = append(out, t)
		}
	}
	return out
}

// isAnimationKeyword filters the non-name tokens that can appear in the
// `animation` shorthand so they are not mistaken for keyframes names.
func isAnimationKeyword(t string) bool {
	switch strings.ToLower(t) {
	case "none", "infinite", "normal", "reverse", "alternate",
		"alternate-reverse", "forwards", "backwards", "both",
		"running", "paused", "ease", "ease-in", "ease-out", "ease-in-out",
		"linear", "step-start", "step-end", "initial", "inherit", "unset":
		return true
	}
	// timing functions like steps(...) / cubic-bezier(...) contain a paren.
	if strings.ContainsAny(t, "()") {
		return true
	}
	return false
}

// looksNumeric reports whether a token is a number/time/percentage value (e.g.
// `2s`, `300ms`, `.5`) rather than an identifier.
func looksNumeric(t string) bool {
	if t == "" {
		return false
	}
	c := t[0]
	return c == '.' || c == '-' || c == '+' || (c >= '0' && c <= '9')
}
