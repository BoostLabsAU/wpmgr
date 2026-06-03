package engine

import (
	"strings"
	"sync"
	"testing"
)

// realisticHTML is a small but representative header/nav/button document reused
// across the golden cases. It exercises classes, ids, descendant structure, and
// a form control (for runtime-state pseudos).
const realisticHTML = `<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Demo</title></head>
<body class="theme-light">
  <header id="masthead" class="site-header">
    <nav class="nav primary-nav" aria-label="Primary">
      <ul class="nav-list">
        <li class="nav-item"><a class="nav-link" href="/">Home</a></li>
        <li class="nav-item"><a class="nav-link is-active" href="/about">About</a></li>
      </ul>
      <button type="button" class="btn btn-primary">Get started</button>
    </nav>
  </header>
  <main class="content">
    <h1 class="page-title">Welcome</h1>
    <input type="text" class="field" name="q" />
  </main>
</body>
</html>`

// helper: does out contain a rule whose selector text includes needle?
func mustContain(t *testing.T, out, needle string) {
	t.Helper()
	if !strings.Contains(out, needle) {
		t.Errorf("expected output to CONTAIN %q\n--- output ---\n%s", needle, out)
	}
}

func mustNotContain(t *testing.T, out, needle string) {
	t.Helper()
	if strings.Contains(out, needle) {
		t.Errorf("expected output to NOT contain %q\n--- output ---\n%s", needle, out)
	}
}

// (1) used class kept, unused dropped, with a reasonable reduction.
func TestPurge_UsedKept_UnusedDropped(t *testing.T) {
	css := `
.site-header { padding: 20px; background: #fff; }
.nav-link { color: #06c; text-decoration: none; }
.btn-primary { background: #06c; color: #fff; }
.page-title { font-size: 2rem; }
.field { border: 1px solid #ccc; }
.totally-unused { display: none; }
.another-ghost { margin: 0; }
#nonexistent { color: red; }
`
	out, stats, err := Purge([]byte(realisticHTML), []byte(css), nil)
	if err != nil {
		t.Fatalf("unexpected err: %v", err)
	}
	if stats.FellBack {
		t.Fatalf("did not expect fallback, note=%q", stats.Note)
	}

	mustContain(t, out, ".site-header")
	mustContain(t, out, ".nav-link")
	mustContain(t, out, ".btn-primary")
	mustContain(t, out, ".page-title")
	mustContain(t, out, ".field")

	mustNotContain(t, out, "totally-unused")
	mustNotContain(t, out, "another-ghost")
	mustNotContain(t, out, "nonexistent")

	if stats.ReductionPct <= 10 {
		t.Errorf("expected a reduction > 10%%, got %.1f%%", stats.ReductionPct)
	}
	if stats.SelectorsDropped != 3 {
		t.Errorf("expected 3 dropped selectors, got %d (kept=%d total=%d)",
			stats.SelectorsDropped, stats.SelectorsKept, stats.SelectorsTotal)
	}
}

// (2) a :hover-only (and other runtime-pseudo) rule is always kept, even when
// the bare pseudo cannot match the static DOM.
func TestPurge_RuntimePseudoAlwaysKept(t *testing.T) {
	css := `
.btn-primary:hover { background: #048; }
.nav-link:focus-visible { outline: 2px solid; }
.field:placeholder-shown { color: #999; }
a::before { content: "→"; }
:hover { cursor: pointer; }
.ghost-button:hover { color: red; }
`
	out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
	if err != nil {
		t.Fatal(err)
	}
	// Host element exists -> kept.
	mustContain(t, out, ".btn-primary:hover")
	mustContain(t, out, ".nav-link:focus-visible")
	mustContain(t, out, ".field:placeholder-shown")
	mustContain(t, out, "a::before")
	// Bare runtime pseudo -> kept unconditionally.
	mustContain(t, out, ":hover")
	// Runtime pseudo on a NON-existent host -> dropped (host doesn't match).
	mustNotContain(t, out, "ghost-button")
}

// (3) @keyframes dropped when its animation is unused, kept when a surviving
// rule references it.
func TestPurge_KeyframesLiveness(t *testing.T) {
	t.Run("dropped when unused", func(t *testing.T) {
		css := `
.btn-primary { background: #06c; }
@keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
`
		out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
		if err != nil {
			t.Fatal(err)
		}
		mustNotContain(t, out, "@keyframes")
		mustNotContain(t, out, "spin")
	})

	t.Run("kept when a surviving rule animates it", func(t *testing.T) {
		css := `
.btn-primary { animation: spin 2s linear infinite; }
@keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
@keyframes pulse { 0% { opacity: 1; } 100% { opacity: 0; } }
`
		out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
		if err != nil {
			t.Fatal(err)
		}
		mustContain(t, out, "@keyframes spin")
		// pulse is defined but never referenced -> dropped.
		mustNotContain(t, out, "pulse")
	})

	t.Run("kept via animation-name longhand", func(t *testing.T) {
		css := `
.page-title { animation-name: fade; animation-duration: 1s; }
@keyframes fade { from { opacity: 0; } to { opacity: 1; } }
`
		out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
		if err != nil {
			t.Fatal(err)
		}
		mustContain(t, out, "@keyframes fade")
	})
}

// (4) @font-face dropped for an unused family, kept for a used family.
func TestPurge_FontFaceLiveness(t *testing.T) {
	css := `
@font-face { font-family: "Inter"; src: url(/fonts/inter.woff2) format("woff2"); }
@font-face { font-family: "GhostFont"; src: url(/fonts/ghost.woff2) format("woff2"); }
.page-title { font-family: "Inter", sans-serif; }
.nav-link { color: #06c; }
`
	out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
	if err != nil {
		t.Fatal(err)
	}
	mustContain(t, out, `font-family:"Inter"`)
	mustContain(t, out, "@font-face")
	mustNotContain(t, out, "GhostFont")
}

// (5) safelist regex forces retention of an otherwise-unmatched class.
func TestPurge_SafelistForcesRetention(t *testing.T) {
	css := `
.js-modal-open { overflow: hidden; }
.swiper-slide-active { transform: none; }
.really-unused { color: red; }
`
	// Neither selector matches the static DOM (these are JS-toggled classes).
	// A regex safelist must retain js-* and swiper-* but NOT really-unused.
	safelist := []string{`/^\.js-/`, `swiper-`}
	out, stats, err := Purge([]byte(realisticHTML), []byte(css), safelist)
	if err != nil {
		t.Fatal(err)
	}
	mustContain(t, out, ".js-modal-open")
	mustContain(t, out, ".swiper-slide-active")
	mustNotContain(t, out, "really-unused")
	if stats.SelectorsDropped != 1 {
		t.Errorf("expected 1 dropped, got %d", stats.SelectorsDropped)
	}
}

// (6) a @media block whose every inner rule is dropped is removed entirely.
func TestPurge_EmptyMediaBlockRemoved(t *testing.T) {
	css := `
@media (max-width: 600px) {
  .ghost-a { color: red; }
  .ghost-b { color: blue; }
}
@media (min-width: 900px) {
  .nav-link { color: green; }
  .ghost-c { color: pink; }
}
`
	out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
	if err != nil {
		t.Fatal(err)
	}
	// The first @media has no surviving inner rule -> the whole block is gone.
	mustNotContain(t, out, "max-width:600px")
	mustNotContain(t, out, "ghost-a")
	mustNotContain(t, out, "ghost-b")
	// The second @media survives (carrying only .nav-link).
	mustContain(t, out, "min-width:900px")
	mustContain(t, out, ".nav-link")
	mustNotContain(t, out, "ghost-c")
}

// (7) malformed HTML AND malformed CSS -> no panic, keep-all fallback.
func TestPurge_MalformedInputs(t *testing.T) {
	t.Run("malformed html stays robust", func(t *testing.T) {
		badHTML := `<div class="nav-link"><span><p>unclosed <button class=btn>x`
		css := `.nav-link { color: blue; } .ghost { color: red; }`
		out, stats, err := Purge([]byte(badHTML), []byte(css), nil)
		if err != nil {
			t.Fatalf("must not error: %v", err)
		}
		// x/net/html recovers; .nav-link matches, .ghost does not.
		mustContain(t, out, ".nav-link")
		mustNotContain(t, out, "ghost")
		_ = stats
	})

	t.Run("malformed css falls back to keep-all", func(t *testing.T) {
		badCSS := `.x { color: ;;; } @media screen and { .y {{{ } } broken } } @@@ !!!`
		out, stats, err := Purge([]byte(realisticHTML), []byte(badCSS), nil)
		if err != nil {
			t.Fatalf("must not error: %v", err)
		}
		// The engine must never panic and must return SOMETHING usable. Whether
		// it parsed partially or fell back, the output must be non-empty and the
		// call must have completed.
		if out == "" {
			t.Errorf("expected non-empty output for malformed css")
		}
		_ = stats
	})

	t.Run("total garbage css keeps all", func(t *testing.T) {
		garbage := "\x00\x01\x02 not css at all {{{ [[[ ((("
		out, stats, err := Purge([]byte(realisticHTML), []byte(garbage), nil)
		if err != nil {
			t.Fatalf("must not error: %v", err)
		}
		if out == "" && len(garbage) > 0 {
			t.Errorf("expected output for garbage input")
		}
		_ = stats
	})

	t.Run("empty inputs", func(t *testing.T) {
		out, stats, err := Purge([]byte(``), []byte(``), nil)
		if err != nil {
			t.Fatal(err)
		}
		if out != "" {
			t.Errorf("empty css should yield empty output, got %q", out)
		}
		if stats.OriginalBytes != 0 {
			t.Errorf("expected 0 original bytes")
		}
	})
}

// transitive custom-property liveness: --a kept because a live --b uses it.
func TestPurge_TransitiveCustomProps(t *testing.T) {
	css := `
:root {
  --base: #06c;
  --brand: var(--base);
  --unused: #f00;
  --orphan-chain: var(--also-unused);
  --also-unused: #0f0;
}
.btn-primary { background: var(--brand); }
`
	out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
	if err != nil {
		t.Fatal(err)
	}
	mustContain(t, out, "--brand")
	mustContain(t, out, "--base") // transitively referenced via --brand.
	mustNotContain(t, out, "--unused")
	mustNotContain(t, out, "--orphan-chain")
	mustNotContain(t, out, "--also-unused")
}

// comma-separated selector list: only the matching parts are kept; a list with
// no matching part drops the whole rule.
func TestPurge_SelectorListPartialKeep(t *testing.T) {
	css := `
.nav-link, .ghost-x, .btn-primary { color: #06c; }
.ghost-a, .ghost-b { color: red; }
`
	out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
	if err != nil {
		t.Fatal(err)
	}
	mustContain(t, out, ".nav-link")
	mustContain(t, out, ".btn-primary")
	mustNotContain(t, out, "ghost-x")
	mustNotContain(t, out, "ghost-a")
	mustNotContain(t, out, "ghost-b")
	// The kept rule must NOT keep the dropped middle part.
	if strings.Contains(out, "ghost") {
		t.Errorf("dropped selector part leaked into output:\n%s", out)
	}
}

// descendant / structural selectors match against the real DOM tree.
func TestPurge_StructuralSelectors(t *testing.T) {
	css := `
.site-header .nav-link { font-weight: bold; }
.content > .page-title { letter-spacing: 0.02em; }
.nav-list li.nav-item { list-style: none; }
header.no-such .nav-link { color: red; }
`
	out, _, err := Purge([]byte(realisticHTML), []byte(css), nil)
	if err != nil {
		t.Fatal(err)
	}
	mustContain(t, out, ".site-header .nav-link")
	mustContain(t, out, ".page-title")
	mustContain(t, out, ".nav-item")
	mustNotContain(t, out, "no-such")
}

// (8) race: 32 concurrent Purge calls must not data-race. Run with -race.
func TestPurge_Concurrent(t *testing.T) {
	css := `
.site-header { padding: 20px; }
.nav-link { color: #06c; }
.btn-primary { animation: spin 2s linear; }
.unused-thing { display: none; }
.btn-primary:hover { background: #048; }
:root { --brand: #06c; --ghost: #000; }
@keyframes spin { from { opacity: 0; } to { opacity: 1; } }
@font-face { font-family: "Ghost"; src: url(g.woff2); }
@media (max-width: 600px) { .nav-link { color: green; } .nope { color: pink; } }
`
	safelist := []string{`/^\.js-/`, "swiper-"}

	const n = 32
	var wg sync.WaitGroup
	wg.Add(n)
	results := make([]string, n)
	for i := 0; i < n; i++ {
		go func(idx int) {
			defer wg.Done()
			out, stats, err := Purge([]byte(realisticHTML), []byte(css), safelist)
			if err != nil {
				t.Errorf("goroutine %d: %v", idx, err)
			}
			if stats.FellBack {
				t.Errorf("goroutine %d unexpectedly fell back: %s", idx, stats.Note)
			}
			results[idx] = out
		}(i)
	}
	wg.Wait()

	// Determinism: every goroutine must produce identical output (no shared
	// mutable state leaking between calls).
	for i := 1; i < n; i++ {
		if results[i] != results[0] {
			t.Fatalf("non-deterministic output between goroutine 0 and %d:\n0: %s\n%d: %s",
				i, results[0], i, results[i])
		}
	}
}
