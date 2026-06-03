package engine

import (
	"strings"

	"github.com/tdewolff/parse/v2"
	"github.com/tdewolff/parse/v2/css"
)

// The engine parses CSS into a small intermediate tree, decides liveness per
// node, then re-serialises the surviving nodes. We do NOT mutate the input or
// rely on byte offsets; every node carries the reconstructed source it needs to
// emit. This keeps the purge deterministic and lets the at-rule / @keyframes /
// @font-face / custom-property liveness passes operate on a structured tree
// rather than a raw token stream.

// node is one top-level (or nested) CSS construct.
type nodeKind int

const (
	nodeRuleset     nodeKind = iota // qualified rule: `selector { decls }`
	nodeAtRuleNest                  // nested-block at-rule: @media/@supports { rules }
	nodeAtKeyframes                 // @keyframes name { ... }  (kept verbatim if live)
	nodeAtFontFace                  // @font-face { ... }
	nodeAtRulePlain                 // @charset/@import/@namespace/@page/... (kept as-is)
)

// decl is a single declaration inside a ruleset (or @font-face/@page).
type decl struct {
	// prop is the property name, lower-cased for comparison but emitted as-is.
	prop string
	// propRaw is the property name exactly as written (preserves case for emit).
	propRaw string
	// value is the full value text (tokens concatenated), e.g. "1px solid red".
	value string
	// custom marks a `--x:` custom-property declaration.
	custom bool
	// important is implied by the value text (we keep value verbatim, so the
	// !important is already part of value; this field is unused but reserved).
}

// emit renders the declaration as `prop: value`.
func (d decl) emit() string {
	return d.propRaw + ":" + d.value
}

type node struct {
	kind nodeKind

	// ruleset
	selector string // raw selector list text (with commas) for nodeRuleset
	decls    []decl // declarations for ruleset / @font-face / @page

	// at-rule
	atName    string // e.g. "@media", "@keyframes" (lower-cased keyword incl @)
	atPrelude string // the text between the at-keyword and the block, e.g. the media query
	children  []node // nested rules for nodeAtRuleNest

	// raw keeps the fully-reconstructed source for plain at-rules emitted as-is.
	raw string
}

// parseStylesheet runs the tdewolff CSS parser and builds the node tree. It
// returns the parsed top-level nodes and ok=false when a genuine parse error
// was encountered (HasParseError) — the caller then falls back to keep-all,
// because a partially-parsed stylesheet risks dropping rules the parser failed
// to read past. A clean EOF returns ok=true. Recoverable comment/whitespace
// noise never flips ok.
func parseStylesheet(cssBytes []byte) (nodes []node, ok bool) {
	p := css.NewParser(parse.NewInputBytes(cssBytes), false)
	pe := &parseErr{}
	nodes = parseRuleList(p, true, pe)
	// A real parse error (anything other than the terminal EOF) means the input
	// was malformed enough that we cannot trust the partial tree — the parser
	// resets its error state on the next Next() call, so we capture it at the
	// ErrorGrammar moment (in parseRuleList) rather than reading it here. Fail
	// safe: keep-all.
	if pe.hit {
		return nil, false
	}
	return nodes, true
}

// parseErr threads the "a genuine parse error occurred" signal up through the
// recursive parseRuleList calls, captured at the moment the ErrorGrammar is
// seen (the parser clears p.err on the subsequent Next(), so a later clean EOF
// would otherwise mask an earlier mid-stylesheet failure).
type parseErr struct{ hit bool }

// parseRuleList consumes grammar until the matching EndAtRule/EndRuleset (or
// EOF at the top level), returning the parsed nodes at this level. Declarations
// that appear directly inside a block (the @font-face / @page declaration-list
// shape) are folded into a single synthetic ruleset child so collectDecls /
// @font-face liveness can find them. top=true means we are at the stylesheet
// root. The terminal parse error (if any) is read by the caller via
// p.HasParseError(); this function does not itself decide fallback.
func parseRuleList(p *css.Parser, top bool, pe *parseErr) (nodes []node) {
	var directDecls []decl // declarations seen directly in this block (@font-face)
	flushDirect := func() {
		if len(directDecls) > 0 {
			nodes = append(nodes, node{kind: nodeRuleset, decls: directDecls})
			directDecls = nil
		}
	}
	for {
		gt, _, data := p.Next()
		switch gt {
		case css.ErrorGrammar:
			// Capture a genuine parse error here (it is cleared on the next
			// Next()); a terminal EOF is NOT an error.
			if p.HasParseError() {
				pe.hit = true
			}
			flushDirect()
			return nodes

		case css.EndAtRuleGrammar, css.EndRulesetGrammar:
			flushDirect()
			return nodes

		case css.CommentGrammar:
			// Drop comments from output (they carry no liveness and bloat).
			continue

		case css.DeclarationGrammar:
			// Direct declaration inside a block — @font-face / @page body.
			prop := string(data)
			directDecls = append(directDecls, decl{
				prop:    strings.ToLower(prop),
				propRaw: prop,
				value:   tokensToString(p.Values()),
			})

		case css.CustomPropertyGrammar:
			prop := string(data)
			directDecls = append(directDecls, decl{
				prop:    prop,
				propRaw: prop,
				value:   tokensToString(p.Values()),
				custom:  true,
			})

		case css.AtRuleGrammar:
			// Block-less at-rule: @charset "..."; @import url(); @namespace ...;
			name := strings.ToLower(string(data))
			nodes = append(nodes, node{
				kind:   nodeAtRulePlain,
				atName: name,
				raw:    string(data) + " " + tokensToString(p.Values()),
			})

		case css.BeginAtRuleGrammar:
			name := strings.ToLower(string(data))
			prelude := tokensToString(p.Values())
			child := parseRuleList(p, false, pe)
			nodes = append(nodes, classifyAtRule(name, prelude, child))

		case css.BeginRulesetGrammar:
			sel := tokensToString(p.Values())
			decls := parseDeclarations(p, pe)
			nodes = append(nodes, node{
				kind:     nodeRuleset,
				selector: strings.TrimSpace(sel),
				decls:    decls,
			})

		case css.QualifiedRuleGrammar:
			// A selector in a comma list the parser split before the block opener;
			// tdewolff folds them into the next BeginRuleset, so ignore.
			continue

		default:
			continue
		}
	}
}

// parseDeclarations consumes DeclarationGrammar/CustomPropertyGrammar until the
// EndRulesetGrammar. Nested at-rules cannot appear here in standard CSS.
func parseDeclarations(p *css.Parser, pe *parseErr) []decl {
	var out []decl
	for {
		gt, _, data := p.Next()
		switch gt {
		case css.ErrorGrammar:
			if p.HasParseError() {
				pe.hit = true
			}
			return out
		case css.EndRulesetGrammar, css.EndAtRuleGrammar:
			return out
		case css.DeclarationGrammar:
			prop := string(data)
			out = append(out, decl{
				prop:    strings.ToLower(prop),
				propRaw: prop,
				value:   tokensToString(p.Values()),
			})
		case css.CustomPropertyGrammar:
			prop := string(data)
			out = append(out, decl{
				prop:    prop, // custom props are case-sensitive
				propRaw: prop,
				value:   tokensToString(p.Values()),
				custom:  true,
			})
		case css.CommentGrammar:
			continue
		default:
			continue
		}
	}
}

// tokensToString concatenates token data with the minimal spacing tdewolff
// already applies (it strips redundant whitespace and emits WhitespaceToken
// where a space is semantically required). The result round-trips selectors and
// values faithfully enough for both cascadia matching and re-emission.
func tokensToString(toks []css.Token) string {
	var b strings.Builder
	for _, t := range toks {
		b.Write(t.Data)
	}
	return strings.TrimSpace(b.String())
}
