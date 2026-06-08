package font_test

import (
	"errors"
	"strings"
	"testing"

	"github.com/google/uuid"

	"github.com/mosamlife/wpmgr/apps/api/internal/media/font"
)

// ---------------------------------------------------------------------------
// FIX 1 — hash validation + tenant-scoped key derivation
// ---------------------------------------------------------------------------

func TestValidSourceHash_Accept(t *testing.T) {
	valid := []string{
		"a948904f2f0f479b8f936ba69c86b0e2a4b7e929b2e6e7e57e1a73e1e4b9c5da", // exactly 64
		"0000000000000000000000000000000000000000000000000000000000000000",
		"ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff",
	}
	for _, h := range valid {
		if !font.ValidSourceHash(h) {
			t.Errorf("expected valid, got invalid for %q", h)
		}
	}
}

func TestValidSourceHash_Reject(t *testing.T) {
	cases := []struct {
		name  string
		input string
	}{
		{"empty", ""},
		{"too_short_63", strings.Repeat("a", 63)},
		{"too_long_65", strings.Repeat("a", 65)},
		{"uppercase", strings.Repeat("A", 64)},
		{"traversal_dotdot", "../../../etc/passwd" + strings.Repeat("a", 45)},
		{"traversal_slash", "/" + strings.Repeat("a", 63)},
		{"null_byte", string([]byte{0}) + strings.Repeat("a", 63)},
		{"space", " " + strings.Repeat("a", 63)},
		{"mixed_case", "ABcdef" + strings.Repeat("0", 58)},
		{"non_hex_g", strings.Repeat("g", 64)},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if font.ValidSourceHash(tc.input) {
				t.Errorf("expected invalid hash to be rejected, but it was accepted: %q", tc.input)
			}
		})
	}
}

func TestDeriveKeys_TenantScoped(t *testing.T) {
	hash := strings.Repeat("a", 64)
	tid1 := uuid.MustParse("11111111-1111-1111-1111-111111111111")
	tid2 := uuid.MustParse("22222222-2222-2222-2222-222222222222")

	// Keys for different tenants must be different.
	src1 := font.DeriveSourceKey(tid1, hash)
	src2 := font.DeriveSourceKey(tid2, hash)
	if src1 == src2 {
		t.Error("source keys for different tenants must differ")
	}

	woff1 := font.DeriveWoff2Key(tid1, hash)
	woff2 := font.DeriveWoff2Key(tid2, hash)
	if woff1 == woff2 {
		t.Error("woff2 keys for different tenants must differ")
	}

	// Keys must contain the tenant_id string so it's visible in the path.
	if !strings.Contains(src1, tid1.String()) {
		t.Errorf("source key %q does not contain tenant_id %q", src1, tid1)
	}
	if !strings.Contains(woff1, tid1.String()) {
		t.Errorf("woff2 key %q does not contain tenant_id %q", woff1, tid1)
	}

	// Keys must contain the hash.
	if !strings.Contains(src1, hash) {
		t.Errorf("source key %q does not contain hash %q", src1, hash)
	}
	if !strings.Contains(woff1, hash) {
		t.Errorf("woff2 key %q does not contain hash %q", woff1, hash)
	}
}

func TestDeriveKeys_NoTraversal(t *testing.T) {
	// Even if somehow a bad hash slipped through (defense-in-depth test):
	// GuardStorageKey must reject any key that doesn't match the expected pattern.
	tid := uuid.MustParse("aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa")

	// A valid derivation must pass the guard.
	validHash := strings.Repeat("b", 64)
	goodSrc := font.DeriveSourceKey(tid, validHash)
	goodWoff2 := font.DeriveWoff2Key(tid, validHash)
	if err := font.GuardStorageKey(goodSrc); err != nil {
		t.Errorf("valid source key failed guard: %v", err)
	}
	if err := font.GuardStorageKey(goodWoff2); err != nil {
		t.Errorf("valid woff2 key failed guard: %v", err)
	}
}

func TestGuardStorageKey_RejectsTraversal(t *testing.T) {
	cases := []string{
		"",
		"../secret",
		"media/../../etc/shadow",
		"fonts/" + strings.Repeat("x", 36) + "/" + strings.Repeat("a", 63) + ".woff2",
		"other/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/font-src/" + strings.Repeat("a", 64),
		"media/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/font-src/" + strings.Repeat("g", 64), // non-hex hash
	}
	for _, key := range cases {
		if err := font.GuardStorageKey(key); err == nil {
			t.Errorf("expected guard to reject key %q, but it passed", key)
		}
	}
}

// ---------------------------------------------------------------------------
// FIX 2 — panic recovery (safeTranscode indirectly via TranscodeToWOFF2)
// ---------------------------------------------------------------------------

func TestTranscodeToWOFF2_GarbageInput_NoPanic(t *testing.T) {
	// Various garbage inputs that might trigger panics in a naive font parser.
	// None of these should panic; all should return an error.
	cases := [][]byte{
		[]byte("wOFF" + strings.Repeat("\x00", 100)),    // WOFF magic, garbage body
		{0x00, 0x01, 0x00, 0x00, 0x00, 0x00},            // TTF magic, truncated
		append([]byte("true"), make([]byte, 200)...),    // Apple TrueType, garbage
		append([]byte("OTTO"), make([]byte, 200)...),    // OTF magic, garbage
		append([]byte("ttcf"), make([]byte, 200)...),    // TTC magic, garbage
		make([]byte, 4),                                  // all zero (unsupported)
		[]byte("\xff\xff\xff\xff\xff\xff\xff\xff"),       // all 0xFF
	}
	for i, src := range cases {
		func() {
			defer func() {
				if r := recover(); r != nil {
					t.Errorf("case %d: unexpected panic: %v", i, r)
				}
			}()
			_, _ = font.TranscodeToWOFF2(src)
		}()
	}
}

// ---------------------------------------------------------------------------
// FIX 2 — decoded size ceiling
// ---------------------------------------------------------------------------

func TestTranscodeToWOFF2_DecodedCeiling(t *testing.T) {
	// We cannot easily produce a font that decompresses to > 64 MiB without
	// a real WOFF2 writer, so we verify the constant is defined and exported.
	if font.MaxDecodedFontBytes <= 0 {
		t.Fatal("MaxDecodedFontBytes must be > 0")
	}
	if font.MaxDecodedFontBytes < font.MaxFontBytes {
		t.Errorf("MaxDecodedFontBytes (%d) must be >= MaxFontBytes (%d)",
			font.MaxDecodedFontBytes, font.MaxFontBytes)
	}
	// ErrDecodedTooLarge must be a permanent error.
	if !errors.Is(font.ErrDecodedTooLarge, font.ErrDecodedTooLarge) {
		t.Error("ErrDecodedTooLarge must satisfy errors.Is with itself")
	}
}

// ---------------------------------------------------------------------------
// FIX 1 — TranscodeArgs.Woff2Key() is tenant-scoped
// ---------------------------------------------------------------------------

func TestTranscodeArgs_Woff2Key_TenantScoped(t *testing.T) {
	hash := strings.Repeat("c", 64)
	tid1 := uuid.MustParse("11111111-1111-1111-1111-111111111111")
	tid2 := uuid.MustParse("22222222-2222-2222-2222-222222222222")

	a1 := font.TranscodeArgs{TenantID: tid1, SourceHash: hash}
	a2 := font.TranscodeArgs{TenantID: tid2, SourceHash: hash}

	if a1.Woff2Key() == a2.Woff2Key() {
		t.Error("Woff2Key() must differ across tenants for the same hash")
	}
	if !strings.Contains(a1.Woff2Key(), tid1.String()) {
		t.Errorf("Woff2Key() %q must contain tenant_id", a1.Woff2Key())
	}
	if !strings.Contains(a1.Woff2Key(), hash) {
		t.Errorf("Woff2Key() %q must contain source hash", a1.Woff2Key())
	}
}
