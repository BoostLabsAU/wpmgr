package perf

import (
	"context"
	"io"
	"net/http"
	"strings"
	"testing"
)

type recordingDoer struct {
	reqs   []*http.Request
	bodies []string
	status int
}

func (d *recordingDoer) Do(req *http.Request) (*http.Response, error) {
	d.reqs = append(d.reqs, req)
	if req.Body != nil {
		b, _ := io.ReadAll(req.Body)
		d.bodies = append(d.bodies, string(b))
	} else {
		d.bodies = append(d.bodies, "")
	}
	st := d.status
	if st == 0 {
		st = 200
	}
	return &http.Response{StatusCode: st, Body: io.NopCloser(strings.NewReader(`{"success":true}`))}, nil
}

func TestCloudflarePurgeEverything(t *testing.T) {
	d := &recordingDoer{}
	p := NewCDNPurger(d)
	err := p.Purge(context.Background(), CDNCredentials{Provider: "cloudflare", APIToken: "tok", ZoneID: "z1"}, "https://site.example.com", nil)
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	if len(d.reqs) != 1 {
		t.Fatalf("expected 1 request, got %d", len(d.reqs))
	}
	if !strings.Contains(d.reqs[0].URL.String(), "/zones/z1/purge_cache") {
		t.Fatalf("unexpected cloudflare url: %s", d.reqs[0].URL.String())
	}
	if d.reqs[0].Header.Get("Authorization") != "Bearer tok" {
		t.Fatalf("expected bearer auth")
	}
	if !strings.Contains(d.bodies[0], "purge_everything") {
		t.Fatalf("expected purge_everything body, got %s", d.bodies[0])
	}
}

func TestCloudflarePurgeFilesAbsolutizesPaths(t *testing.T) {
	d := &recordingDoer{}
	p := NewCDNPurger(d)
	err := p.Purge(context.Background(), CDNCredentials{Provider: "cloudflare", APIToken: "tok", ZoneID: "z1"}, "https://site.example.com", []string{"/about", "https://other.example.com/x"})
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	body := d.bodies[0]
	if !strings.Contains(body, "https://site.example.com/about") {
		t.Fatalf("expected relative path absolutized, got %s", body)
	}
	if !strings.Contains(body, "https://other.example.com/x") {
		t.Fatalf("expected absolute url preserved, got %s", body)
	}
}

func TestBunnyWholeZonePurge(t *testing.T) {
	d := &recordingDoer{}
	p := NewCDNPurger(d)
	err := p.Purge(context.Background(), CDNCredentials{Provider: "bunny", APIToken: "key", Zone: "12345"}, "https://site.example.com", nil)
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	if !strings.Contains(d.reqs[0].URL.String(), "/pullzone/12345/purgeCache") {
		t.Fatalf("unexpected bunny url: %s", d.reqs[0].URL.String())
	}
	if d.reqs[0].Header.Get("AccessKey") != "key" {
		t.Fatalf("expected AccessKey header")
	}
}

func TestKeyCDNURLPurge(t *testing.T) {
	d := &recordingDoer{}
	p := NewCDNPurger(d)
	err := p.Purge(context.Background(), CDNCredentials{Provider: "keycdn", APIToken: "apikey", Zone: "99"}, "https://site.example.com", []string{"https://site.example.com/a"})
	if err != nil {
		t.Fatalf("purge: %v", err)
	}
	if !strings.Contains(d.reqs[0].URL.String(), "/zones/purgeurl/99.json") {
		t.Fatalf("unexpected keycdn url: %s", d.reqs[0].URL.String())
	}
	user, _, ok := d.reqs[0].BasicAuth()
	if !ok || user != "apikey" {
		t.Fatalf("expected basic auth with api key username")
	}
}

func TestUnsupportedProvider(t *testing.T) {
	p := NewCDNPurger(&recordingDoer{})
	if err := p.Purge(context.Background(), CDNCredentials{Provider: "akamai"}, "https://x", nil); err == nil {
		t.Fatal("expected error for unsupported provider")
	}
}

func TestPurgeNon2xxIsError(t *testing.T) {
	d := &recordingDoer{status: 403}
	p := NewCDNPurger(d)
	if err := p.Purge(context.Background(), CDNCredentials{Provider: "cloudflare", APIToken: "t", ZoneID: "z"}, "https://x", nil); err == nil {
		t.Fatal("expected error on non-2xx")
	}
}
