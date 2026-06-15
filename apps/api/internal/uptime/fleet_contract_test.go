package uptime

import (
	"encoding/json"
	"testing"
)

// TestFleetStatusItemJSONContract locks the JSON field names of the fleet
// status item to the web contract (apps/web/src/features/fleet/fleet-types.ts
// FleetStatusItem). A silent rename here (e.g. site_name instead of name) blanks
// the Site column and produces "NaN ms" latency in the dashboard, which shipped
// once in 0.47.0. This test fails fast on any such drift.
func TestFleetStatusItemJSONContract(t *testing.T) {
	b, err := json.Marshal(FleetStatusItem{})
	if err != nil {
		t.Fatalf("marshal FleetStatusItem: %v", err)
	}
	var m map[string]json.RawMessage
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	// Required keys the web client reads. Additive backend-only keys are allowed.
	for _, k := range []string{
		"site_id", "name", "url", "connection_state", "health_status",
		"status", "up", "last_probe_at", "uptime_pct_7d", "avg_latency_ms",
		"tls_expiry", "latency_sparkline",
	} {
		if _, ok := m[k]; !ok {
			t.Errorf("FleetStatusItem JSON missing contract key %q (got keys %v)", k, keysOf(m))
		}
	}
}

func keysOf(m map[string]json.RawMessage) []string {
	ks := make([]string, 0, len(m))
	for k := range m {
		ks = append(ks, k)
	}
	return ks
}
