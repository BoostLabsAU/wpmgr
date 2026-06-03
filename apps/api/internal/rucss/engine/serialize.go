package engine

import "strings"

// serialize renders the surviving node tree back to a compact CSS string. It is
// deliberately minimal (no pretty-printing): selectors and values are emitted as
// the parser reconstructed them, declarations joined with `;`, rules and blocks
// with `{` / `}`. The output is valid CSS that a browser (and our minifier
// downstream) accepts.
func serialize(nodes []node) string {
	var b strings.Builder
	for _, n := range nodes {
		serializeNode(&b, n)
	}
	return b.String()
}

func serializeNode(b *strings.Builder, n node) {
	switch n.kind {
	case nodeRuleset:
		serializeRuleset(b, n)
	case nodeAtRuleNest:
		// @media (...) { children }
		b.WriteString(n.atName)
		if n.atPrelude != "" {
			b.WriteByte(' ')
			b.WriteString(n.atPrelude)
		}
		b.WriteByte('{')
		for _, c := range n.children {
			serializeNode(b, c)
		}
		b.WriteByte('}')
	case nodeAtKeyframes:
		// @keyframes name { ...frames... }
		b.WriteString(n.atName)
		if n.atPrelude != "" {
			b.WriteByte(' ')
			b.WriteString(n.atPrelude)
		}
		b.WriteByte('{')
		for _, c := range n.children {
			serializeNode(b, c)
		}
		b.WriteByte('}')
	case nodeAtFontFace:
		b.WriteString(n.atName)
		b.WriteByte('{')
		writeDecls(b, n.decls)
		b.WriteByte('}')
	case nodeAtRulePlain:
		raw := strings.TrimSpace(n.raw)
		b.WriteString(raw)
		if !strings.HasSuffix(raw, ";") {
			b.WriteByte(';')
		}
	}
}

func serializeRuleset(b *strings.Builder, n node) {
	if strings.TrimSpace(n.selector) == "" || len(n.decls) == 0 {
		// A ruleset that lost all its declarations (e.g. only dead custom props)
		// is dropped — emitting `sel{}` is pointless bytes.
		if len(n.decls) == 0 {
			return
		}
	}
	b.WriteString(n.selector)
	b.WriteByte('{')
	writeDecls(b, n.decls)
	b.WriteByte('}')
}

func writeDecls(b *strings.Builder, decls []decl) {
	first := true
	for _, d := range decls {
		if !first {
			b.WriteByte(';')
		}
		b.WriteString(d.emit())
		first = false
	}
}
