package engine

import "regexp"

// varRefRe extracts the custom-property NAME referenced by a var() call:
//
//	var(--foo)            -> --foo
//	var(--foo, fallback)  -> --foo  (and the fallback is scanned recursively by
//	                                  the caller, since it may itself var())
//
// It is intentionally permissive: it matches `var(` followed by optional
// whitespace and a `--ident`. Nested var() in the fallback is found by a second
// pass over the whole value, so a single regexp suffices.
var varRefRe = regexp.MustCompile(`var\(\s*(--[A-Za-z0-9_-]+)`)

// referencedVars returns the set of custom-property names referenced via var()
// anywhere in a value string (including inside fallbacks).
func referencedVars(value string) []string {
	matches := varRefRe.FindAllStringSubmatch(value, -1)
	if len(matches) == 0 {
		return nil
	}
	out := make([]string, 0, len(matches))
	for _, m := range matches {
		out = append(out, m[1])
	}
	return out
}

// liveVars computes the transitive closure of custom properties that must be
// retained. A variable is live if it is referenced (via var()) by a NON-custom-
// property declaration that survived purging, OR by another live custom
// property's value (transitive: `--a: var(--b)` keeps --b iff --a is live).
//
// Inputs:
//   - rootRefs: var() references found in surviving NORMAL declarations (the
//     declarations that are not themselves `--x:` definitions).
//   - varDefs:  every custom-property definition we saw, name -> its value
//     string (so we can follow `--a: var(--b)` chains).
//
// The returned set contains the names (with the leading --) that are live and
// therefore must be emitted; any `--x` definition not in the set is dead CSS.
func liveVars(rootRefs []string, varDefs map[string]string) map[string]struct{} {
	live := make(map[string]struct{})
	// Worklist seeded by the references from surviving normal declarations.
	queue := make([]string, 0, len(rootRefs))
	for _, r := range rootRefs {
		if _, ok := live[r]; !ok {
			live[r] = struct{}{}
			queue = append(queue, r)
		}
	}
	for len(queue) > 0 {
		name := queue[len(queue)-1]
		queue = queue[:len(queue)-1]
		def, ok := varDefs[name]
		if !ok {
			continue // referenced but never defined — nothing to follow.
		}
		for _, ref := range referencedVars(def) {
			if _, seen := live[ref]; !seen {
				live[ref] = struct{}{}
				queue = append(queue, ref)
			}
		}
	}
	return live
}
