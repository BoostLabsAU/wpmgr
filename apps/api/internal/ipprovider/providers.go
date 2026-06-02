package ipprovider

import "strings"

// hostingType buckets a provider for the UI ("cloud" VPS/IaaS, "cdn-proxy" for
// edge networks that are NOT where files live, "shared" for shared/managed-WP
// resellers). Kept coarse on purpose.
type hostingType string

const (
	typeCloud   hostingType = "cloud"
	typeCDN     hostingType = "cdn-proxy"
	typeShared  hostingType = "shared"
	typeUnknown hostingType = ""
)

// byASN maps well-known Autonomous System numbers to a canonical provider name.
// ASN is the most reliable signal (org strings drift with acquisitions), so it
// is checked first. Small and auditable on purpose; extend as gaps surface.
var byASN = map[uint]struct {
	name  string
	htype hostingType
}{
	14061:  {"DigitalOcean", typeCloud},
	24940:  {"Hetzner", typeCloud},
	213230: {"Hetzner", typeCloud},
	212317: {"Hetzner", typeCloud},
	16276:  {"OVH", typeCloud},
	16509:  {"AWS", typeCloud},
	14618:  {"AWS", typeCloud},
	8987:   {"AWS", typeCloud},
	7224:   {"AWS", typeCloud},
	15169:  {"Google Cloud", typeCloud},
	396982: {"Google Cloud", typeCloud},
	19527:  {"Google Cloud", typeCloud},
	8075:   {"Microsoft Azure", typeCloud},
	8068:   {"Microsoft Azure", typeCloud},
	8069:   {"Microsoft Azure", typeCloud},
	12076:  {"Microsoft Azure", typeCloud},
	20473:  {"Vultr", typeCloud},
	64515:  {"Vultr", typeCloud},
	63949:  {"Akamai (Linode)", typeCloud},
	48254:  {"Linode", typeCloud},
	31898:  {"Oracle Cloud", typeCloud},
	7160:   {"Oracle Cloud", typeCloud},
	51167:  {"Contabo", typeCloud},
	12876:  {"Scaleway", typeCloud},
	60781:  {"Leaseweb", typeCloud},
	28753:  {"Leaseweb", typeCloud},
	16265:  {"Leaseweb", typeCloud},
	45102:  {"Alibaba Cloud", typeCloud},
	37963:  {"Alibaba Cloud", typeCloud},
	132203: {"Tencent Cloud", typeCloud},
	19994:  {"Rackspace", typeCloud},
	32244:  {"Liquid Web", typeCloud},
	26347:  {"DreamHost", typeShared},
	47583:  {"Hostinger", typeShared},
	204915: {"Hostinger", typeShared},
	26496:  {"GoDaddy", typeShared},
	398101: {"GoDaddy", typeShared},
	8560:   {"IONOS", typeShared},
	22612:  {"Namecheap", typeShared},
	// Edge / CDN networks: the egress may pass through here but it is not the
	// origin host, so it is surfaced honestly as a proxy network.
	13335:  {"Cloudflare", typeCDN},
	209242: {"Cloudflare", typeCDN},
	54113:  {"Fastly", typeCDN},
	20940:  {"Akamai", typeCDN},
}

// orgKeyword is the fallback when the ASN is not in byASN: a lowercase substring
// match against the registered AS organization string.
var orgKeyword = []struct {
	kw    string
	name  string
	htype hostingType
}{
	{"digitalocean", "DigitalOcean", typeCloud},
	{"hetzner", "Hetzner", typeCloud},
	{"ovh", "OVH", typeCloud},
	{"amazon", "AWS", typeCloud},
	{"google", "Google Cloud", typeCloud},
	{"microsoft", "Microsoft Azure", typeCloud},
	{"azure", "Microsoft Azure", typeCloud},
	{"vultr", "Vultr", typeCloud},
	{"constant company", "Vultr", typeCloud},
	{"choopa", "Vultr", typeCloud},
	{"linode", "Linode", typeCloud},
	{"oracle", "Oracle Cloud", typeCloud},
	{"contabo", "Contabo", typeCloud},
	{"scaleway", "Scaleway", typeCloud},
	{"leaseweb", "Leaseweb", typeCloud},
	{"alibaba", "Alibaba Cloud", typeCloud},
	{"tencent", "Tencent Cloud", typeCloud},
	{"rackspace", "Rackspace", typeCloud},
	{"liquid web", "Liquid Web", typeCloud},
	{"dreamhost", "DreamHost", typeShared},
	{"hostinger", "Hostinger", typeShared},
	{"godaddy", "GoDaddy", typeShared},
	{"ionos", "IONOS", typeShared},
	{"namecheap", "Namecheap", typeShared},
	{"hostgator", "HostGator", typeShared},
	{"bluehost", "Bluehost", typeShared},
	{"siteground", "SiteGround", typeShared},
	{"kinsta", "Kinsta", typeShared},
	{"wpengine", "WP Engine", typeShared},
	{"cloudflare", "Cloudflare", typeCDN},
	{"fastly", "Fastly", typeCDN},
	{"akamai", "Akamai", typeCDN},
}

// classify turns an (ASN, org) pair into a canonical provider, a coarse hosting
// type, and a confidence. An empty provider means "could not recognise it" and
// the caller surfaces nothing rather than a wrong guess.
func classify(asn uint, org string) (provider string, htype hostingType, confidence string) {
	if hit, ok := byASN[asn]; ok {
		return hit.name, hit.htype, "high"
	}
	lower := strings.ToLower(org)
	for _, k := range orgKeyword {
		if strings.Contains(lower, k.kw) {
			return k.name, k.htype, "medium"
		}
	}
	return "", typeUnknown, "low"
}
