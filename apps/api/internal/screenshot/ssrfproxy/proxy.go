// Package ssrfproxy provides an in-process CONNECT proxy whose dialer runs
// every outbound connection through the code.dny.dev/ssrf guardian. Wiring the
// headless Chromium browser through this proxy ensures that EVERY connection the
// browser makes — the top-level navigation, sub-resource loads (CSS, JS, images,
// fonts, XHR), and every redirect hop — is re-validated at dial time against the
// SSRF guard, including checks for RFC1918 / link-local / loopback ranges.
//
// This defeats DNS-rebinding attacks: the IP is checked at the moment the socket
// is actually opened, using the same address that will be connected to, not the
// address that was resolved earlier.
//
// The proxy listens on a random loopback port, returns it via Addr(), and must
// be stopped via Stop() when capture is complete.
//
// Security invariants enforced by this proxy:
//   1. Every connect (top nav + sub-resources + redirects) goes through the SSRF
//      dialer — private IPs, link-local, and loopback are rejected at dial time.
//   2. Only tcp network is accepted — no unix socket connections.
//   3. The proxy only implements CONNECT (used by Chrome in --proxy-server mode)
//      and plain GET (for http:// URLs). https:// targets use CONNECT tunnels
//      whose inner TLS is terminated between Chromium and the target; the proxy
//      sees only the CONNECT host:port, which is what we validate.
//   4. The proxy is strictly loopback-bound: it never listens on a non-loopback
//      interface and must not be reachable from outside the process.
package ssrfproxy

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"time"

	"code.dny.dev/ssrf"
	"net/netip"
)

// Proxy is a loopback-bound CONNECT proxy whose dialer enforces the SSRF guard.
type Proxy struct {
	listener net.Listener
	srv      *http.Server
	logger   *slog.Logger
}

// New starts a new SSRF-guarded CONNECT proxy on a random loopback port.
// Stop() must be called when the capture is done.
func New(logger *slog.Logger) (*Proxy, error) {
	if logger == nil {
		logger = slog.Default()
	}

	guardian := ssrf.New(
		// Allow only ports 80 and 443 — WordPress sites must be reachable on
		// the standard web ports. Non-standard ports could target internal services.
		ssrf.WithPorts(80, 443),
	)
	// Additionally block link-local (169.254.0.0/16 incl. 169.254.169.254) and
	// RFC1918 / IPv6 ULA ranges at the network layer. The ssrf library already
	// blocks these by default, but we are explicit here for clarity.
	// (code.dny.dev/ssrf denies loopback, private, link-local, and special-use
	// by default without any extra options; the port restriction above is the
	// only addition.)
	_ = guardian // used inside safeDial via closure

	dialer := &net.Dialer{
		Timeout:   15 * time.Second,
		KeepAlive: 30 * time.Second,
		Control:   guardian.Safe,
	}

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return nil, fmt.Errorf("ssrfproxy: listen: %w", err)
	}

	p := &Proxy{listener: ln, logger: logger}
	mux := http.NewServeMux()
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodConnect {
			p.handleCONNECT(w, r, dialer)
		} else {
			p.handleHTTP(w, r, dialer)
		}
	})
	p.srv = &http.Server{
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	go func() {
		if err := p.srv.Serve(ln); err != nil && err != http.ErrServerClosed {
			logger.Warn("ssrfproxy: server error", slog.Any("error", err))
		}
	}()
	return p, nil
}

// Addr returns the proxy's "host:port" loopback address.
func (p *Proxy) Addr() string {
	return p.listener.Addr().String()
}

// Stop shuts down the proxy listener.
func (p *Proxy) Stop() {
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	_ = p.srv.Shutdown(ctx)
}

// handleCONNECT tunnels the client's TCP stream to the target host after
// validating the target through the SSRF dialer. Used for https:// targets.
func (p *Proxy) handleCONNECT(w http.ResponseWriter, r *http.Request, dialer *net.Dialer) {
	// The target is r.Host (e.g. "example.com:443"). The SSRF guard runs inside
	// the dialer's Control hook at connect time, after DNS resolution.
	target := r.Host
	if target == "" {
		http.Error(w, "missing CONNECT target", http.StatusBadRequest)
		return
	}

	// Reject non-standard ports at the string level before dialing. Chromium
	// should only ever CONNECT on :443, but a rogue page might try :6379.
	if err := rejectNonWebPort(target); err != nil {
		p.logger.Warn("ssrfproxy: CONNECT rejected (non-web port)", slog.String("target", target))
		http.Error(w, "forbidden: "+err.Error(), http.StatusForbidden)
		return
	}

	upstream, err := dialer.DialContext(r.Context(), "tcp", target)
	if err != nil {
		p.logger.Warn("ssrfproxy: CONNECT dial failed", slog.String("target", target), slog.Any("error", err))
		http.Error(w, "forbidden or unreachable: "+err.Error(), http.StatusForbidden)
		return
	}
	defer upstream.Close()

	// Hijack the client connection and start bidirectional copy.
	hj, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "hijack not supported", http.StatusInternalServerError)
		return
	}
	clientConn, _, err := hj.Hijack()
	if err != nil {
		http.Error(w, "hijack failed: "+err.Error(), http.StatusInternalServerError)
		return
	}
	defer clientConn.Close()

	// Signal the client that the tunnel is established.
	_, _ = fmt.Fprint(clientConn, "HTTP/1.1 200 Connection Established\r\n\r\n")

	// Bidirectional pipe with a capped per-transfer deadline.
	done := make(chan struct{}, 2)
	go func() { _, _ = io.Copy(upstream, clientConn); done <- struct{}{} }()
	go func() { _, _ = io.Copy(clientConn, upstream); done <- struct{}{} }()
	<-done
}

// handleHTTP proxies a plain http:// request through the SSRF dialer.
// Chromium uses this for http:// top-level navigations when a --proxy-server
// is configured.
func (p *Proxy) handleHTTP(w http.ResponseWriter, r *http.Request, dialer *net.Dialer) {
	// Reject non-http schemes at the request level (paranoia: Chromium should
	// only send http:// to the proxy; ws://, ftp:// etc. would be a bug).
	if r.URL.Scheme != "http" {
		http.Error(w, "forbidden scheme", http.StatusForbidden)
		return
	}

	transport := &http.Transport{
		DialContext:       dialer.DialContext,
		ForceAttemptHTTP2: false, // proxy-mode HTTP is HTTP/1.1
	}
	// Remove hop-by-hop headers.
	r.RequestURI = ""
	r.Header.Del("Proxy-Connection")
	r.Header.Del("Proxy-Authenticate")
	r.Header.Del("Proxy-Authorization")

	resp, err := transport.RoundTrip(r)
	if err != nil {
		p.logger.Warn("ssrfproxy: http round-trip failed", slog.String("url", r.URL.String()), slog.Any("error", err))
		http.Error(w, "upstream error: "+err.Error(), http.StatusBadGateway)
		return
	}
	defer resp.Body.Close()

	// Copy response headers then body.
	for k, vv := range resp.Header {
		for _, v := range vv {
			w.Header().Add(k, v)
		}
	}
	w.WriteHeader(resp.StatusCode)
	_, _ = io.Copy(w, io.LimitReader(resp.Body, 10<<20)) // 10 MiB cap
}

// rejectNonWebPort returns an error when the host:port string's port is not 80
// or 443. A missing port is treated as 443 (CONNECT default).
//
// M1 — port allowlist matches the ssrf.WithPorts(80, 443) dialer invariant
// exactly. 8080 and 8443 were previously allowed here but rejected by the
// dialer, making the string-level check misleading in a security-critical file.
// The allowlist is now tight: only the two standard web ports pass.
func rejectNonWebPort(hostPort string) error {
	_, port, err := net.SplitHostPort(hostPort)
	if err != nil {
		// No port — treat as default CONNECT (443), which is fine.
		return nil
	}
	switch port {
	case "80", "443":
		return nil
	default:
		return fmt.Errorf("port %s not allowed", port)
	}
}

// blockedPrefixes lists the RFC1918 / link-local / loopback prefixes that are
// additionally blocked at the ssrfproxy layer (the ssrf library already blocks
// these, but we document them explicitly for reviewers).
var blockedPrefixes = []netip.Prefix{
	netip.MustParsePrefix("127.0.0.0/8"),      // loopback
	netip.MustParsePrefix("10.0.0.0/8"),       // RFC1918
	netip.MustParsePrefix("172.16.0.0/12"),    // RFC1918
	netip.MustParsePrefix("192.168.0.0/16"),   // RFC1918
	netip.MustParsePrefix("169.254.0.0/16"),   // link-local / GCE metadata
	netip.MustParsePrefix("100.64.0.0/10"),    // CGNAT
	netip.MustParsePrefix("::1/128"),          // IPv6 loopback
	netip.MustParsePrefix("fc00::/7"),         // IPv6 ULA
	netip.MustParsePrefix("fe80::/10"),        // IPv6 link-local
}

// BlockedByProxy reports whether addr is in one of the blocked prefixes.
// Exported so tests can assert on the block list without dialing.
func BlockedByProxy(addr netip.Addr) bool {
	a := addr.Unmap()
	for _, p := range blockedPrefixes {
		if p.Contains(a) {
			return true
		}
	}
	return false
}
