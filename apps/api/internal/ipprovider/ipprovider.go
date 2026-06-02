// Package ipprovider infers the hosting/infrastructure provider of a managed
// site from the public egress IP the control plane observes on the agent's
// signed requests. It is a FULLY OFFLINE lookup: an embedded DB-IP IP-to-ASN
// Lite database (CC BY 4.0) maps the IP to an Autonomous System, and a small
// in-repo alias table maps the AS organization to a canonical provider name.
//
// No customer IP ever leaves the operator's control plane, and no per-customer
// API key is required, which keeps the result consistent with the product's
// "your data stays on infrastructure you own" posture.
//
// The result is a best-effort HINT, never authoritative: the egress IP is the
// network the server talks OUT through, which is usually but not always where
// the files live (NAT, egress gateways, and managed-WP control planes can
// differ). Callers must let a positive PHP defined()-based HostFlag win and
// only fall back to this inference when no managed host matched.
//
// Attribution (required by DB-IP's CC BY 4.0 licence): "IP data by DB-IP.com".
package ipprovider

import (
	_ "embed"
	"net"
	"strings"

	"github.com/oschwald/maxminddb-golang"
)

//go:embed data/dbip-asn-lite.mmdb
var mmdbBytes []byte

//go:embed data/RELEASE.txt
var releaseRaw string

// Attribution is the licence-required credit string the UI must render.
const Attribution = "IP data by DB-IP.com"

// Result is the outcome of a lookup. Provider == "" means the IP could not be
// confidently attributed to a known provider (the honest "unrecognized" case).
type Result struct {
	IP          string
	IPVersion   string // "v4" | "v6" | ""
	ASN         uint
	ASOrg       string
	Provider    string // canonical name, e.g. "DigitalOcean"; "" when unknown
	HostingType string // "cloud" | "cdn-proxy" | "shared" | ""
	Confidence  string // "high" | "medium" | "low"
	Source      string // "offline-dbip"
	DBRelease   string // e.g. "2026-06"
}

// asnRecord is the subset of the DB-IP ASN record we read.
type asnRecord struct {
	ASN   uint   `maxminddb:"autonomous_system_number"`
	ASOrg string `maxminddb:"autonomous_system_organization"`
}

// Resolver performs offline IP -> provider lookups. Construct with New. A nil
// *Resolver (or one with no database) is safe to call and always returns an
// empty Result, so the feature self-disables cleanly when the data is missing.
type Resolver struct {
	db        *maxminddb.Reader
	dbRelease string
}

// New opens the embedded database. It returns a disabled (but safe) resolver
// and a nil error when the embedded file is empty, so a build without the data
// file degrades to "no inference" rather than failing to boot.
func New() (*Resolver, error) {
	release := strings.TrimSpace(releaseRaw)
	if len(mmdbBytes) == 0 {
		return &Resolver{dbRelease: release}, nil
	}
	rdr, err := maxminddb.FromBytes(mmdbBytes)
	if err != nil {
		return nil, err
	}
	return &Resolver{db: rdr, dbRelease: release}, nil
}

// Enabled reports whether the database loaded, for diagnostics/logging.
func (r *Resolver) Enabled() bool { return r != nil && r.db != nil }

// Resolve looks up the provider for an IP string. It never errors: an
// unparseable IP, a database miss, or a disabled resolver all yield a Result
// with an empty Provider.
func (r *Resolver) Resolve(ipStr string) Result {
	rel := ""
	if r != nil {
		rel = r.dbRelease
	}
	out := Result{IP: ipStr, Source: "offline-dbip", DBRelease: rel, Confidence: "low"}
	if r == nil || r.db == nil {
		return out
	}
	ip := net.ParseIP(strings.TrimSpace(ipStr))
	if ip == nil {
		return out
	}
	if ip.To4() != nil {
		out.IPVersion = "v4"
	} else {
		out.IPVersion = "v6"
	}
	var rec asnRecord
	if err := r.db.Lookup(ip, &rec); err != nil {
		return out
	}
	out.ASN = rec.ASN
	out.ASOrg = rec.ASOrg
	provider, htype, confidence := classify(rec.ASN, rec.ASOrg)
	out.Provider = provider
	out.HostingType = string(htype)
	out.Confidence = confidence
	return out
}
