package font

// This file exports internal symbols for use by tests in the font_test package.
// It is only compiled during testing (filename ends with _test.go).

// IsVariableFont exposes the internal variable-font detector for testing.
var IsVariableFont = isVariableFont

// IsIconFont exposes the internal icon-font detector for testing.
var IsIconFont = isIconFont

// BuildGlyphIDs exposes the glyph-ID builder for testing.
var BuildGlyphIDs = buildGlyphIDs

// LatinExtRanges exposes the fixed latin-ext codepoint ranges for testing.
var LatinExtRanges = latinExtRanges

// DecodeNameValue exposes the name-record UTF-16 decoder for testing.
var DecodeNameValue = decodeNameValue
