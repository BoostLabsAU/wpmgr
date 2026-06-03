// Package engine is the pure-Go Remove-Unused-CSS (RUCSS) engine for the WPMgr
// performance suite. Given a rendered HTML document and the CSS that styles it,
// Purge returns the subset of CSS whose selectors actually match the document
// (plus everything that must be retained for correctness: runtime-state pseudos,
// referenced @keyframes/@font-face, and live custom properties).
//
// Accuracy model. The engine is a STATIC analyser over one HTML snapshot:
//   - Selector matching uses cascadia against the parsed DOM. A selector is kept
//     iff at least one of its comma-separated parts matches an element.
//   - Runtime-state pseudos (:hover/:focus/…) and pseudo-elements (::before/…)
//     cannot match a static snapshot, so a selector that reduces to a bare
//     runtime pseudo is kept unconditionally, and one with a runtime pseudo
//     attached to a host element is kept iff the (pseudo-stripped) host matches.
//   - @media/@supports/@container recurse and are emitted only if ≥1 inner rule
//     survives; @keyframes survive iff a surviving rule animates their name;
//     @font-face survive iff a surviving rule references their font-family;
//     @charset/@import/@namespace are always kept.
//   - Custom properties (--x) are kept iff transitively referenced via var() by
//     a surviving declaration.
//
// Graceful degradation. The engine NEVER panics. Malformed HTML is parsed
// leniently by x/net/html (which always yields a tree); a fatal CSS parse error,
// an empty selector budget, or a recovered panic all fall back to returning the
// FULL input CSS unchanged with Stats.FellBack=true and a Stats.Note explaining
// why. A keep-all result is always safe (it can only fail to shrink, never break
// the page).
package engine

import (
	"bytes"
	"fmt"
	"regexp"
	"strings"

	"github.com/andybalholm/cascadia"
	"golang.org/x/net/html"
)

// Stats summarises a purge pass for telemetry and the rucss_results row.
type Stats struct {
	// OriginalBytes / UsedBytes are the byte lengths of the input and output CSS.
	OriginalBytes int
	UsedBytes     int
	// ReductionPct is 100*(1 - UsedBytes/OriginalBytes), 0 when OriginalBytes==0.
	ReductionPct float64
	// SelectorsTotal counts every comma-separated selector part seen across all
	// rulesets; SelectorsKept/Dropped partition them by the keep decision.
	SelectorsTotal   int
	SelectorsKept    int
	SelectorsDropped int
	// RulesTotal / RulesKept count whole rulesets (a ruleset is dropped when none
	// of its selector parts survive).
	RulesTotal int
	RulesKept  int
	// FellBack is true when the engine returned the input CSS unchanged because
	// it could not safely purge (see Note).
	FellBack bool
	// Note is a short human-readable explanation, set on fallback or for
	// noteworthy conditions (empty on a clean purge).
	Note string
}

// Purge removes unused CSS rules from cssBytes relative to the document in
// htmlBytes. safelist is a list of selector patterns (substring or /regex/
// form, see compileSafelist) that force-retain any selector they match. The
// returned usedCSS is the minimised stylesheet; stats describes the pass; err
// is non-nil only for programmer errors (today: never — all failures degrade to
// keep-all with FellBack=true).
func Purge(htmlBytes, cssBytes []byte, safelist []string) (usedCSS string, stats Stats, err error) {
	// Hard panic guard: nothing in here should panic, but a defensive recover
	// guarantees the contract ("never panic; fall back to full CSS").
	defer func() {
		if r := recover(); r != nil {
			usedCSS = string(cssBytes)
			stats = keepAllStats(cssBytes, fmt.Sprintf("recovered from panic: %v", r))
			err = nil
		}
	}()

	original := len(cssBytes)
	if original == 0 {
		return "", Stats{Note: "empty css"}, nil
	}

	doc, parseErr := html.Parse(bytes.NewReader(htmlBytes))
	if parseErr != nil || doc == nil {
		// x/net/html is extremely lenient and essentially never fails, but if it
		// somehow does we cannot match anything — keep everything.
		return string(cssBytes), keepAllStats(cssBytes, "html parse failed; kept full css"), nil
	}

	nodes, ok := parseStylesheet(cssBytes)
	if !ok {
		return string(cssBytes), keepAllStats(cssBytes, "css parse failed; kept full css"), nil
	}

	safe, serr := compileSafelist(safelist)
	if serr != nil {
		// A bad safelist pattern must not break the purge; ignore the bad entry.
		safe = compileSafelistLenient(safelist)
	}

	m := &matcher{doc: doc, cache: map[string]bool{}}
	pass := &purgePass{
		matcher:  m,
		safe:     safe,
		varDefs:  map[string]string{},
		rootRefs: nil,
		animRefs: map[string]struct{}{},
		fontRefs: map[string]struct{}{},
		stats:    &stats,
	}

	kept := pass.walk(nodes)

	// Second phase: now that we know which normal declarations survived (and the
	// animation/font-family/var references they carry), decide @keyframes /
	// @font-face / custom-property liveness and drop the dead ones.
	live := liveVars(pass.rootRefs, pass.varDefs)
	kept = pass.finalize(kept, live)

	out := serialize(kept)
	stats.OriginalBytes = original
	stats.UsedBytes = len(out)
	stats.ReductionPct = reduction(original, len(out))
	return out, stats, nil
}

// keepAllStats builds the Stats for a keep-all fallback.
func keepAllStats(cssBytes []byte, note string) Stats {
	return Stats{
		OriginalBytes: len(cssBytes),
		UsedBytes:     len(cssBytes),
		ReductionPct:  0,
		FellBack:      true,
		Note:          note,
	}
}

func reduction(orig, used int) float64 {
	if orig <= 0 {
		return 0
	}
	r := 100 * (1 - float64(used)/float64(orig))
	if r < 0 {
		return 0
	}
	return r
}

// ---------------------------------------------------------------------------
// purge pass
// ---------------------------------------------------------------------------

type purgePass struct {
	matcher *matcher
	safe    *safelist

	// varDefs maps every custom-property name we encountered to its value (used
	// for transitive liveness). Populated during the walk.
	varDefs map[string]string
	// rootRefs collects var() references made by surviving NORMAL declarations.
	rootRefs []string
	// animRefs / fontRefs collect animation names / font-family names referenced
	// by surviving declarations, deciding @keyframes / @font-face liveness.
	animRefs map[string]struct{}
	fontRefs map[string]struct{}

	stats *Stats
}

// walk processes a list of nodes, returning the survivors. Custom-property
// liveness is applied later (finalize) once all references are known.
func (p *purgePass) walk(nodes []node) []node {
	var out []node
	for _, n := range nodes {
		switch n.kind {
		case nodeRuleset:
			if r, ok := p.keepRuleset(n); ok {
				out = append(out, r)
			}
		case nodeAtRuleNest:
			inner := p.walk(n.children)
			if len(inner) > 0 {
				nn := n
				nn.children = inner
				out = append(out, nn)
			}
			// else: a conditional group with no surviving inner rule is dropped.
		case nodeAtKeyframes, nodeAtFontFace, nodeAtRulePlain:
			// Deferred: keyframes/font-face liveness is decided in finalize;
			// plain at-rules are always kept. Carry them through unchanged.
			out = append(out, n)
		}
	}
	return out
}

// keepRuleset decides which selector parts of a ruleset survive and records its
// surviving declarations' references. Returns ok=false when the whole ruleset is
// dropped (no part matched and nothing safelisted).
func (p *purgePass) keepRuleset(n node) (node, bool) {
	parts := splitSelectorList(n.selector)
	p.stats.RulesTotal++
	var keptParts []string
	for _, part := range parts {
		p.stats.SelectorsTotal++
		if p.keepSelector(part) {
			p.stats.SelectorsKept++
			keptParts = append(keptParts, part)
		} else {
			p.stats.SelectorsDropped++
		}
	}
	if len(keptParts) == 0 {
		return node{}, false
	}
	p.stats.RulesKept++

	// The ruleset survives: record what its declarations reference so the
	// deferred passes can keep the @keyframes/@font-face/--vars they depend on.
	for _, d := range n.decls {
		if d.custom {
			p.varDefs[d.prop] = d.value
			// A custom-property definition's own var() refs only matter if the
			// var is itself live; finalize handles that transitively.
			continue
		}
		for _, v := range referencedVars(d.value) {
			p.rootRefs = append(p.rootRefs, v)
		}
		for _, a := range referencedAnimations(d.prop, d.value) {
			p.animRefs[a] = struct{}{}
		}
		for _, f := range referencedFontFamilies(d.prop, d.value) {
			p.fontRefs[f] = struct{}{}
		}
	}

	nn := n
	nn.selector = strings.Join(keptParts, ",")
	return nn, true
}

// keepSelector applies the three keep rules to ONE selector part:
//  1. safelisted -> keep.
//  2. stripping runtime pseudos leaves empty AND a runtime pseudo was present
//     (bare :hover/*:hover) -> keep.
//  3. cascadia matches the (pseudo-stripped) selector against the DOM -> keep.
//
// Unparseable selectors are kept (fail-safe).
func (p *purgePass) keepSelector(part string) bool {
	part = strings.TrimSpace(part)
	if part == "" {
		return false
	}
	if p.safe.matches(part) {
		return true
	}
	stripped, hadRuntime := stripRuntimePseudos(part)
	if stripped == "" {
		// Whole selector was runtime pseudos (e.g. ":hover", "::before" only,
		// or "*:hover" reduced to ""). If a runtime pseudo was present we keep
		// it (cannot prove it dead); if not, an empty selector is meaningless —
		// keep it fail-safe rather than risk dropping something we mis-parsed.
		return true
	}
	if hadRuntime {
		// host element + runtime pseudo: keep iff the host exists in the DOM.
		return p.matcher.matches(stripped)
	}
	return p.matcher.matches(stripped)
}

// finalize drops dead @keyframes / @font-face / custom-property declarations now
// that every reference from surviving rules is known.
func (p *purgePass) finalize(nodes []node, live map[string]struct{}) []node {
	var out []node
	for _, n := range nodes {
		switch n.kind {
		case nodeRuleset:
			n.decls = p.pruneCustomProps(n.decls, live)
			out = append(out, n)
		case nodeAtRuleNest:
			n.children = p.finalize(n.children, live)
			if len(n.children) > 0 {
				out = append(out, n)
			}
		case nodeAtKeyframes:
			if _, ok := p.animRefs[keyframesName(n.atPrelude)]; ok {
				out = append(out, n)
			}
			// else: no surviving rule animates this name -> drop.
		case nodeAtFontFace:
			fam := fontFamilyOf(n.decls)
			if fam != "" {
				if _, ok := p.fontRefs[fam]; ok {
					out = append(out, n)
				}
			}
			// A @font-face with no family, or an unreferenced family, is dropped.
		case nodeAtRulePlain:
			out = append(out, n)
		}
	}
	return out
}

// pruneCustomProps removes `--x:` declarations whose variable is not live.
func (p *purgePass) pruneCustomProps(decls []decl, live map[string]struct{}) []decl {
	hasDead := false
	for _, d := range decls {
		if d.custom {
			if _, ok := live[d.prop]; !ok {
				hasDead = true
				break
			}
		}
	}
	if !hasDead {
		return decls
	}
	out := decls[:0:0]
	for _, d := range decls {
		if d.custom {
			if _, ok := live[d.prop]; !ok {
				continue
			}
		}
		out = append(out, d)
	}
	return out
}

// ---------------------------------------------------------------------------
// selector matching
// ---------------------------------------------------------------------------

type matcher struct {
	doc   *html.Node
	cache map[string]bool
}

// matches reports whether sel matches at least one element in the document. A
// selector cascadia cannot parse is treated as a match (fail-safe keep). Results
// are memoised because the same selector commonly recurs across media queries.
func (m *matcher) matches(sel string) bool {
	if v, ok := m.cache[sel]; ok {
		return v
	}
	v := m.matchUncached(sel)
	m.cache[sel] = v
	return v
}

func (m *matcher) matchUncached(sel string) bool {
	compiled, err := cascadia.Parse(sel)
	if err != nil {
		// Unparseable (e.g. a pseudo cascadia doesn't model that survived
		// stripping) -> keep, fail-safe.
		return true
	}
	return cascadia.Query(m.doc, compiled) != nil
}

// splitSelectorList splits a comma-separated selector list, honouring strings,
// brackets, and parentheses so a comma inside :not(a, b) or [x="a,b"] does not
// split the list. Empty parts are dropped.
func splitSelectorList(s string) []string {
	var parts []string
	var b strings.Builder
	depthParen, depthBracket := 0, 0
	var quote rune
	for _, r := range s {
		switch {
		case quote != 0:
			b.WriteRune(r)
			if r == quote {
				quote = 0
			}
		case r == '\'' || r == '"':
			quote = r
			b.WriteRune(r)
		case r == '(':
			depthParen++
			b.WriteRune(r)
		case r == ')':
			if depthParen > 0 {
				depthParen--
			}
			b.WriteRune(r)
		case r == '[':
			depthBracket++
			b.WriteRune(r)
		case r == ']':
			if depthBracket > 0 {
				depthBracket--
			}
			b.WriteRune(r)
		case r == ',' && depthParen == 0 && depthBracket == 0:
			if p := strings.TrimSpace(b.String()); p != "" {
				parts = append(parts, p)
			}
			b.Reset()
		default:
			b.WriteRune(r)
		}
	}
	if p := strings.TrimSpace(b.String()); p != "" {
		parts = append(parts, p)
	}
	return parts
}

// ---------------------------------------------------------------------------
// safelist
// ---------------------------------------------------------------------------

// safelist holds compiled retention patterns. An entry written as `/.../`
// compiles to a regexp matched against the selector text; any other entry is a
// case-sensitive substring match (the css_rucss_include_selectors convention).
type safelist struct {
	res    []*regexp.Regexp
	substr []string
}

func (s *safelist) matches(sel string) bool {
	if s == nil {
		return false
	}
	for _, ss := range s.substr {
		if strings.Contains(sel, ss) {
			return true
		}
	}
	for _, re := range s.res {
		if re.MatchString(sel) {
			return true
		}
	}
	return false
}

// compileSafelist builds a safelist, returning an error if any `/regex/` entry
// fails to compile (the caller then uses compileSafelistLenient).
func compileSafelist(patterns []string) (*safelist, error) {
	s := &safelist{}
	for _, raw := range patterns {
		p := strings.TrimSpace(raw)
		if p == "" {
			continue
		}
		if len(p) >= 2 && strings.HasPrefix(p, "/") && strings.HasSuffix(p, "/") {
			re, err := regexp.Compile(p[1 : len(p)-1])
			if err != nil {
				return nil, err
			}
			s.res = append(s.res, re)
			continue
		}
		s.substr = append(s.substr, p)
	}
	return s, nil
}

// compileSafelistLenient compiles a safelist skipping any malformed regex entry
// (treating it as a literal substring instead). Used so one bad pattern can't
// disable the whole safelist.
func compileSafelistLenient(patterns []string) *safelist {
	s := &safelist{}
	for _, raw := range patterns {
		p := strings.TrimSpace(raw)
		if p == "" {
			continue
		}
		if len(p) >= 2 && strings.HasPrefix(p, "/") && strings.HasSuffix(p, "/") {
			if re, err := regexp.Compile(p[1 : len(p)-1]); err == nil {
				s.res = append(s.res, re)
				continue
			}
			// fall through: treat the inner text as a substring.
			s.substr = append(s.substr, p[1:len(p)-1])
			continue
		}
		s.substr = append(s.substr, p)
	}
	return s
}
