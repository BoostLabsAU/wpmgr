// Package font provides server-side font transcoding for the WPMgr media
// pipeline. It converts self-hosted fonts in TTF, OTF, and WOFF format to
// WOFF2, enabling agents to serve WOFF2 with correct format() fallbacks.
//
// The implementation is pure-Go (CGO_ENABLED=0) and uses:
//   - github.com/tdewolff/font (MIT) for SFNT parsing and WOFF2 encoding via
//     SFNT.WriteWOFF2(); reads WOFF2-compressed glyph tables via
//     github.com/andybalholm/brotli (MIT) as a transitive dependency.
//   - github.com/tdewolff/font's ParseWOFF to unpack WOFF to raw SFNT before
//     passing to WriteWOFF2().
//
// The package runs in the existing media-encoder River worker (cmd/media-encoder)
// because it is CGO-free and adds no new build constraints.
package font

import (
	"bytes"
	"errors"
	"fmt"
	"strings"
	"unicode/utf16"

	tdwfont "github.com/tdewolff/font"
)

// WOFF2Signature is the four-byte magic for a WOFF2 file.
// A valid output must begin with these bytes.
const WOFF2Signature = "wOF2"

// MaxFontBytes is the maximum accepted source font size (10 MiB).
// Self-hosted web fonts are typically < 1 MiB; this is a generous safety cap.
const MaxFontBytes = 10 << 20

// MaxDecodedFontBytes is the ceiling applied to the WOFF2 output after
// encoding. WOFF and WOFF2 are compressed; a 10 MiB compressed source can
// produce a much larger decoded SFNT. We cap the output at 64 MiB to bound
// memory use in the shared media-encoder process.
const MaxDecodedFontBytes = 64 << 20

// ErrFontTooLarge is returned when the source exceeds MaxFontBytes.
var ErrFontTooLarge = errors.New("font transcode: source exceeds 10 MiB limit")

// ErrDecodedTooLarge is returned when the WOFF2 output exceeds MaxDecodedFontBytes.
// This prevents a tiny crafted compressed font from OOM-ing the media-encoder.
var ErrDecodedTooLarge = errors.New("font transcode: decoded output exceeds 64 MiB limit")

// ErrAlreadyWOFF2 is returned when the source is already WOFF2.
// The caller should serve the original rather than re-encoding.
var ErrAlreadyWOFF2 = errors.New("font transcode: source is already WOFF2")

// ErrUnsupportedFormat is returned when the source magic does not match any
// supported input format (TTF/OTF/WOFF). WOFF2 inputs are rejected with
// ErrAlreadyWOFF2 instead.
var ErrUnsupportedFormat = errors.New("font transcode: unsupported source format (expected TTF/OTF/WOFF)")

// ErrVariableFont is a permanent-negative sentinel returned when the source
// font carries fvar/gvar tables indicating it is a variable font.
// Subsetting a variable font with SFNT.Subset silently drops fvar/gvar,
// producing a broken static font. We skip subsetting and serve the full WOFF2.
var ErrVariableFont = errors.New("font transcode: variable font cannot be subset (fvar/gvar present)")

// ErrIconFont is a permanent-negative sentinel returned when the source font
// is detected as an icon font (cmap predominantly maps into the PUA range
// U+E000–F8FF, or the family name matches known icon-font keywords).
// Icon glyphs live in PUA codepoints that fall outside any standard unicode
// range, so a latin-ext subset would strip every useful glyph. Skip and serve
// the full WOFF2 instead.
var ErrIconFont = errors.New("font transcode: icon font cannot be subset (PUA-heavy cmap or icon family name)")

// ErrSubsetEmpty is returned when the requested unicode range yields zero
// matched glyph IDs in the font's cmap. Serving an empty subset is useless;
// the full WOFF2 remains the @font-face src.
var ErrSubsetEmpty = errors.New("font transcode: subset produced no glyphs for the requested range")

// ErrSubsetFailed is the general sentinel for a Subset() call that returned
// an error (e.g. CFF2 unsupported, too many glyphs). The full WOFF2 is still
// valid; only the subset row is marked negative.
var ErrSubsetFailed = errors.New("font transcode: subset operation failed")

// SubsetResult carries the output of TranscodeToWOFF2WithSubset.
// When SubsetWOFF2 is nil, no subset was produced (either SubsetModeNone was
// requested, or the font was skipped/failed — see State/SubsetErr).
type SubsetResult struct {
	// FullWOFF2 is the full WOFF2 output (always non-nil on success).
	FullWOFF2 []byte
	// SubsetWOFF2 is the subset WOFF2 output. Nil when no subset was produced.
	SubsetWOFF2 []byte
	// UnicodeRange is the CSS unicode-range descriptor string for the subset
	// (e.g. "U+0000-00FF,U+0100-024F,U+1E00-1EFF"). Empty when no subset.
	UnicodeRange string
	// SubsetErr is non-nil when the subset pass was attempted but yielded a
	// permanent-negative result (ErrVariableFont, ErrIconFont, ErrSubsetEmpty,
	// ErrSubsetFailed). The full WOFF2 is still valid; only the subset row is
	// marked negative. SubsetErr is nil when SubsetModeNone was requested.
	SubsetErr error
}

// TranscodeToWOFF2 converts a font in TTF, OTF, or WOFF format to WOFF2
// bytes. It returns ErrAlreadyWOFF2 when the source is already WOFF2 (the
// caller must serve the original). ErrUnsupportedFormat is returned for any
// other magic.
//
// The returned bytes always begin with the WOFF2 magic "wOF2".
//
// This function is safe for concurrent use and allocates no shared state.
func TranscodeToWOFF2(src []byte) ([]byte, error) {
	if len(src) > MaxFontBytes {
		return nil, ErrFontTooLarge
	}
	if len(src) < 4 {
		return nil, ErrUnsupportedFormat
	}

	magic := src[:4]

	switch {
	case bytes.Equal(magic, []byte("wOF2")):
		return nil, ErrAlreadyWOFF2

	case bytes.Equal(magic, []byte("wOFF")):
		// WOFF: unpack to raw SFNT first, then encode to WOFF2.
		sfntBytes, err := tdwfont.ParseWOFF(src)
		if err != nil {
			return nil, fmt.Errorf("font transcode: parse WOFF: %w", err)
		}
		return sfntToWOFF2(sfntBytes)

	case isSFNT(magic):
		// TTF (0x00010000 or 'true') or OTF ('OTTO') or TTC ('ttcf').
		return sfntToWOFF2(src)

	default:
		return nil, ErrUnsupportedFormat
	}
}

// TranscodeToWOFF2WithSubset converts a source font to a full WOFF2 and
// optionally also produces a unicode-range subset WOFF2. The full WOFF2 is
// ALWAYS the primary output and the @font-face canonical src; the subset is
// additive only.
//
// When spec.Mode == SubsetModeNone the function behaves identically to
// TranscodeToWOFF2 (Phase-1 path, result.SubsetWOFF2 == nil, result.SubsetErr == nil).
//
// When spec.Mode == SubsetModeRange:
//   - Safety guards run BEFORE subsetting. Variable fonts (fvar/gvar present)
//     and icon fonts (PUA-heavy cmap or keyword family name) are not subset;
//     result.SubsetErr is set to ErrVariableFont or ErrIconFont and
//     result.SubsetWOFF2 is nil. The full WOFF2 result.FullWOFF2 is still valid.
//   - On success, result.SubsetWOFF2 is the subset WOFF2 bytes and
//     result.UnicodeRange is the CSS unicode-range descriptor string.
//   - On any subset failure (empty glyph set, Subset() error, oversize output),
//     result.SubsetErr is set, result.SubsetWOFF2 is nil.
func TranscodeToWOFF2WithSubset(src []byte, spec SubsetSpec) (SubsetResult, error) {
	if len(src) > MaxFontBytes {
		return SubsetResult{}, ErrFontTooLarge
	}
	if len(src) < 4 {
		return SubsetResult{}, ErrUnsupportedFormat
	}

	magic := src[:4]

	var sfntBytes []byte
	switch {
	case bytes.Equal(magic, []byte("wOF2")):
		return SubsetResult{}, ErrAlreadyWOFF2

	case bytes.Equal(magic, []byte("wOFF")):
		b, err := tdwfont.ParseWOFF(src)
		if err != nil {
			return SubsetResult{}, fmt.Errorf("font transcode: parse WOFF: %w", err)
		}
		sfntBytes = b

	case isSFNT(magic):
		sfntBytes = src

	default:
		return SubsetResult{}, ErrUnsupportedFormat
	}

	// Parse SFNT once — shared by both the full-WOFF2 and the subset paths.
	sfnt, err := tdwfont.ParseSFNT(sfntBytes, 0)
	if err != nil {
		return SubsetResult{}, fmt.Errorf("font transcode: parse SFNT: %w", err)
	}

	// Full WOFF2 (always produced; Phase-1 path unchanged).
	fullWOFF2, err := sfnt.WriteWOFF2()
	if err != nil {
		return SubsetResult{}, fmt.Errorf("font transcode: write WOFF2: %w", err)
	}
	if len(fullWOFF2) > MaxDecodedFontBytes {
		return SubsetResult{}, ErrDecodedTooLarge
	}

	res := SubsetResult{FullWOFF2: fullWOFF2}

	if spec.Mode == SubsetModeNone {
		// Phase-1 behavior: no subset attempted.
		return res, nil
	}

	// Subsetting pass.
	spec = CanonicalSubsetSpec(spec)

	// Guard 1: variable font detection.
	if isVariableFont(sfnt) {
		res.SubsetErr = ErrVariableFont
		return res, nil
	}

	// Guard 2: icon font detection.
	if isIconFont(sfnt) {
		res.SubsetErr = ErrIconFont
		return res, nil
	}

	// Build the glyph ID set for the requested range.
	ranges, rangeStr := unicodeRangesForSpec(spec)
	glyphIDs := buildGlyphIDs(sfnt, ranges)
	if len(glyphIDs) == 0 {
		res.SubsetErr = ErrSubsetEmpty
		return res, nil
	}

	// Run SFNT.Subset with KeepMinTables.
	// Signature: func (sfnt *SFNT) Subset(glyphIDs []uint16, options SubsetOptions) (*SFNT, error)
	subsetSFNT, err := sfnt.Subset(glyphIDs, tdwfont.SubsetOptions{
		Tables: tdwfont.KeepMinTables,
	})
	if err != nil {
		res.SubsetErr = fmt.Errorf("%w: %v", ErrSubsetFailed, err)
		return res, nil
	}

	subsetWOFF2, err := subsetSFNT.WriteWOFF2()
	if err != nil {
		res.SubsetErr = fmt.Errorf("%w: WriteWOFF2 on subset: %v", ErrSubsetFailed, err)
		return res, nil
	}
	if len(subsetWOFF2) > MaxDecodedFontBytes {
		res.SubsetErr = ErrDecodedTooLarge
		return res, nil
	}

	res.SubsetWOFF2 = subsetWOFF2
	res.UnicodeRange = rangeStr
	return res, nil
}

// sfntToWOFF2 parses raw SFNT bytes (TTF/OTF) and encodes them as WOFF2.
func sfntToWOFF2(src []byte) ([]byte, error) {
	sfnt, err := tdwfont.ParseSFNT(src, 0)
	if err != nil {
		return nil, fmt.Errorf("font transcode: parse SFNT: %w", err)
	}
	out, err := sfnt.WriteWOFF2()
	if err != nil {
		return nil, fmt.Errorf("font transcode: write WOFF2: %w", err)
	}
	if len(out) > MaxDecodedFontBytes {
		return nil, ErrDecodedTooLarge
	}
	return out, nil
}

// isSFNT returns true for the four-byte magic values that identify a raw
// OpenType/SFNT font:
//   - 0x00010000 — TrueType outline (the canonical TTF magic)
//   - 'true'     — Apple TrueType (some macOS fonts)
//   - 'OTTO'     — CFF/OpenType outline (OTF)
//   - 'ttcf'     — TrueType Collection (TTC)
func isSFNT(magic []byte) bool {
	return bytes.Equal(magic, []byte{0x00, 0x01, 0x00, 0x00}) || // TTF
		bytes.Equal(magic, []byte("true")) ||
		bytes.Equal(magic, []byte("OTTO")) || // OTF
		bytes.Equal(magic, []byte("ttcf")) // TTC
}

// isVariableFont returns true when the parsed SFNT carries an fvar or gvar
// table. Subsetting a variable font silently drops those tables, producing a
// broken static font — skip subsetting and serve the full WOFF2.
func isVariableFont(sfnt *tdwfont.SFNT) bool {
	_, hasFvar := sfnt.Tables["fvar"]
	_, hasGvar := sfnt.Tables["gvar"]
	return hasFvar || hasGvar
}

// puaStart is the start of the Basic Multilingual Plane Private Use Area.
// Icon fonts (Font Awesome, Dashicons, etc.) map glyphs into U+E000–F8FF.
const (
	puaStart = rune(0xE000)
	puaEnd   = rune(0xF8FF)
)

// iconFamilyKeywords are lowercased substrings that appear in the family names
// of the most common web icon fonts. A conservative set: false negatives (not
// detected as icon) are acceptable; false positives (detected as icon when it
// is not) break subsetting unnecessarily.
var iconFamilyKeywords = []string{
	"fontawesome", "font-awesome", "font awesome",
	"dashicons",
	"genericons",
	"glyphicons",
	"ionicons",
	"icomoon",
	"linearicons",
	"remixicon",
	"material icons",
	"feather",
}

// isIconFont returns true when the parsed SFNT appears to be an icon font.
// The heuristic uses two signals, applied with OR (conservative):
//
//  1. PUA cmap scan: iterate the PUA range (U+E000–F8FF) and count matched
//     glyphs. If > 5 PUA glyphs exist AND PUA glyphs outnumber printable-ASCII
//     glyphs (U+0020–007E), we declare it an icon font.
//
//  2. Family name match: any name-table family-name record that, after lowercasing,
//     contains a known icon-font keyword is declared an icon font.
func isIconFont(sfnt *tdwfont.SFNT) bool {
	// Signal 1: PUA-heavy cmap.
	puaCount := 0
	for r := puaStart; r <= puaEnd; r++ {
		if sfnt.GlyphIndex(r) != 0 {
			puaCount++
		}
	}
	if puaCount > 5 {
		asciiCount := 0
		for r := rune(0x20); r <= rune(0x7E); r++ {
			if sfnt.GlyphIndex(r) != 0 {
				asciiCount++
			}
		}
		if puaCount > asciiCount {
			return true
		}
	}

	// Signal 2: family name keyword match.
	if sfnt.Name != nil {
		for _, record := range sfnt.Name.Get(tdwfont.NameFontFamily) {
			family := decodeNameValue(record.Platform, record.Value)
			lower := strings.ToLower(family)
			for _, kw := range iconFamilyKeywords {
				if strings.Contains(lower, kw) {
					return true
				}
			}
		}
	}
	return false
}

// decodeNameValue attempts to decode a name-table raw byte value to a Go
// string. Windows records are UTF-16 BE; Mac records are typically ASCII/
// Mac Roman. We do a best-effort decode: for Windows (platform 3) we decode
// as UTF-16 BE; for everything else we treat the bytes as ASCII (Latin-1 for
// our purposes is fine — keyword matching is all we need).
func decodeNameValue(platform tdwfont.PlatformID, value []byte) string {
	if platform == tdwfont.PlatformWindows && len(value)%2 == 0 {
		// UTF-16 BE → []uint16 → string
		u16 := make([]uint16, len(value)/2)
		for i := range u16 {
			u16[i] = uint16(value[2*i])<<8 | uint16(value[2*i+1])
		}
		return string(utf16.Decode(u16))
	}
	return string(value)
}

// unicodeRange describes a contiguous range of unicode codepoints.
type unicodeRange struct {
	lo, hi rune
}

// latinExtRanges is the fixed set of codepoints for the "latin-ext" subset
// mode. These cover:
//   - Printable Basic Latin  U+0020–007F (space through DEL; U+0000–001F are C0
//     controls — no visual form in web fonts and their inclusion causes cmap
//     rebuild errors in some font formats, so we exclude them)
//   - Latin-1 Supplement     U+0080–00FF
//   - Latin Extended-A       U+0100–017F
//   - Latin Extended-B       U+0180–024F
//   - Latin Extended Additional U+1E00–1EFF
//
// Together they span the full latin-ext CSS unicode-range descriptor commonly
// used by Google Fonts for Western-European-language coverage, while excluding
// Cyrillic, Greek, CJK, etc.
var latinExtRanges = []unicodeRange{
	{0x0020, 0x00FF}, // Printable Basic Latin + Latin-1 Supplement (skip C0 controls)
	{0x0100, 0x024F}, // Latin Extended-A + Extended-B
	{0x1E00, 0x1EFF}, // Latin Extended Additional
}

// latinExtRangeStr is the CSS unicode-range descriptor for latinExtRanges.
const latinExtRangeStr = "U+0020-00FF,U+0100-024F,U+1E00-1EFF"

// unicodeRangesForSpec returns the codepoint ranges and the CSS unicode-range
// descriptor string for the given SubsetSpec. The spec must already be
// canonicalized (CanonicalSubsetSpec called).
func unicodeRangesForSpec(spec SubsetSpec) ([]unicodeRange, string) {
	// Only "range" + "latin-ext" is supported in v1.
	return latinExtRanges, latinExtRangeStr
}

// buildGlyphIDs iterates the given unicode ranges and collects the glyph IDs
// for each codepoint that the font actually maps (GlyphIndex != 0). The
// .notdef glyph (ID 0) is intentionally excluded — Subset adds it automatically
// as the implicit first glyph.
//
// The returned slice preserves iteration order (lo→hi across ranges) and
// contains no duplicates (one glyph ID per codepoint; composites are resolved
// by SFNT.Subset itself).
func buildGlyphIDs(sfnt *tdwfont.SFNT, ranges []unicodeRange) []uint16 {
	seen := make(map[uint16]bool)
	var ids []uint16
	for _, r := range ranges {
		for cp := r.lo; cp <= r.hi; cp++ {
			gid := sfnt.GlyphIndex(cp)
			if gid == 0 {
				continue
			}
			if seen[gid] {
				continue
			}
			seen[gid] = true
			ids = append(ids, gid)
		}
	}
	return ids
}
