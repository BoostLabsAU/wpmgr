package font_test

import (
	"bytes"
	"testing"

	"golang.org/x/image/font/gofont/goregular"

	"github.com/mosamlife/wpmgr/apps/api/internal/media/font"
)

// goregular.TTF is an embedded Go-licensed TTF (~66 KiB) used as the test
// fixture. No network access; the bytes are compiled in via the gofont package.

func TestTranscodeToWOFF2_TTF(t *testing.T) {
	src := goregular.TTF
	if len(src) == 0 {
		t.Fatal("fixture TTF is empty")
	}

	out, err := font.TranscodeToWOFF2(src)
	if err != nil {
		t.Fatalf("TranscodeToWOFF2 returned error: %v", err)
	}

	// (a) no error — covered by the check above.

	// (b) output starts with the WOFF2 four-byte magic.
	if !bytes.HasPrefix(out, []byte(font.WOFF2Signature)) {
		t.Fatalf("output does not start with WOFF2 magic: first 4 bytes = %q", out[:min(4, len(out))])
	}

	// (c) WOFF2 is meaningfully smaller than the raw TTF.
	// For goregular (a clean hinted TTF) WOFF2 is typically 50–60% of the TTF.
	// We require at least 5% reduction to give the test headroom without being
	// brittle across library versions.
	ratio := float64(len(out)) / float64(len(src))
	if ratio >= 0.95 {
		t.Errorf("WOFF2 output is not meaningfully smaller: TTF=%d bytes, WOFF2=%d bytes (ratio=%.2f)", len(src), len(out), ratio)
	}
	t.Logf("TTF → WOFF2: %d → %d bytes (%.1f%% of original)", len(src), len(out), ratio*100)
}

func TestTranscodeToWOFF2_AlreadyWOFF2(t *testing.T) {
	// Build a source that begins with the WOFF2 magic (we don't need a complete
	// valid WOFF2; we just need the magic bytes to trigger the early-exit path).
	src := append([]byte("wOF2"), make([]byte, 16)...)

	_, err := font.TranscodeToWOFF2(src)
	if err != font.ErrAlreadyWOFF2 {
		t.Fatalf("expected ErrAlreadyWOFF2, got %v", err)
	}
}

func TestTranscodeToWOFF2_UnsupportedFormat(t *testing.T) {
	src := []byte("PNG\r\n\x1a\n" + "some content here")

	_, err := font.TranscodeToWOFF2(src)
	if err != font.ErrUnsupportedFormat {
		t.Fatalf("expected ErrUnsupportedFormat, got %v", err)
	}
}

func TestTranscodeToWOFF2_TooLarge(t *testing.T) {
	src := make([]byte, font.MaxFontBytes+1)
	// Give it a valid magic so we reach the size check.
	copy(src, []byte{0x00, 0x01, 0x00, 0x00})

	_, err := font.TranscodeToWOFF2(src)
	if err != font.ErrFontTooLarge {
		t.Fatalf("expected ErrFontTooLarge, got %v", err)
	}
}

func TestTranscodeToWOFF2_EmptyInput(t *testing.T) {
	_, err := font.TranscodeToWOFF2(nil)
	if err == nil {
		t.Fatal("expected error for nil input, got nil")
	}
}

func TestTranscodeToWOFF2_WOFF(t *testing.T) {
	// Transcode goregular to WOFF2 first to get valid WOFF2 bytes, then test
	// that a WOFF input is handled correctly. We cannot produce a real WOFF here
	// without a real WOFF-writer, but we verify the code path rejects the WOFF2
	// magic and returns ErrAlreadyWOFF2 rather than a parse error for a WOFF2
	// that looks like valid WOFF2 (magic "wOF2").
	//
	// For an actual WOFF round-trip test we check the WOFF magic handling
	// separately: a 4-byte "wOFF" prefix with garbage body should fail in
	// ParseWOFF (not ErrUnsupportedFormat), confirming we reached the WOFF branch.
	src := append([]byte("wOFF"), make([]byte, 32)...)
	_, err := font.TranscodeToWOFF2(src)
	if err == nil {
		t.Fatal("expected error for malformed WOFF, got nil")
	}
	// Must NOT be ErrUnsupportedFormat (we entered the WOFF parse branch).
	if err == font.ErrUnsupportedFormat {
		t.Fatal("expected WOFF parse error, not ErrUnsupportedFormat")
	}
	// Must NOT be ErrAlreadyWOFF2 (wrong magic).
	if err == font.ErrAlreadyWOFF2 {
		t.Fatal("unexpected ErrAlreadyWOFF2 for WOFF magic input")
	}
	t.Logf("malformed WOFF correctly returned: %v", err)
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}
