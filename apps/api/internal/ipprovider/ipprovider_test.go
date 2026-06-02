package ipprovider

import "testing"

func TestResolveKnownProviders(t *testing.T) {
	r, err := New()
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	if !r.Enabled() {
		t.Fatal("resolver not enabled: embedded MMDB missing or unreadable")
	}

	cases := []struct {
		ip       string
		provider string
	}{
		{"8.8.8.8", "Google Cloud"},
		{"159.65.0.1", "DigitalOcean"},
		{"5.9.0.1", "Hetzner"},
		{"51.38.0.1", "OVH"},
		{"18.130.0.1", "AWS"},
		{"45.32.0.1", "Vultr"},
		{"104.16.0.1", "Cloudflare"},
		{"13.107.0.1", "Microsoft Azure"},
	}
	for _, c := range cases {
		got := r.Resolve(c.ip)
		if got.Provider != c.provider {
			t.Errorf("Resolve(%s) provider = %q (asn=%d org=%q), want %q",
				c.ip, got.Provider, got.ASN, got.ASOrg, c.provider)
		}
		if got.ASN == 0 {
			t.Errorf("Resolve(%s) returned ASN 0; expected a hit", c.ip)
		}
	}
}

func TestResolveEdgeCases(t *testing.T) {
	r, _ := New()

	// Private and bogus IPs resolve to an empty provider, never a wrong guess.
	for _, ip := range []string{"10.0.0.1", "192.168.1.1", "127.0.0.1", "not-an-ip", ""} {
		got := r.Resolve(ip)
		if got.Provider != "" {
			t.Errorf("Resolve(%q) provider = %q, want empty", ip, got.Provider)
		}
	}

	// A nil resolver is safe and inert.
	var nilR *Resolver
	if got := nilR.Resolve("8.8.8.8"); got.Provider != "" {
		t.Errorf("nil resolver provider = %q, want empty", got.Provider)
	}
}
