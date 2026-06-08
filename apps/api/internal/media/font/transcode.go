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
