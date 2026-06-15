package perf

import (
	"encoding/json"
	"testing"
)

// TestFleetRumResponseJSONContract locks the JSON shape of the fleet RUM
// aggregate to the web contract (apps/web/src/features/fleet/fleet-types.ts
// FleetRumResponse). The 0.47.0 ship emitted a "metrics" map and no "trend",
// while the dashboard read "per_metric" and "trend", white-screening the page on
// trend.length. This test fails fast on any such drift.
func TestFleetRumResponseJSONContract(t *testing.T) {
	b, err := json.Marshal(FleetRumResponse{})
	if err != nil {
		t.Fatalf("marshal FleetRumResponse: %v", err)
	}
	var m map[string]json.RawMessage
	if err := json.Unmarshal(b, &m); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	for _, k := range []string{
		"sites_reporting", "sites_total", "fleet_pass_pct",
		"per_metric", "worst_offenders", "trend",
	} {
		if _, ok := m[k]; !ok {
			t.Errorf("FleetRumResponse JSON missing contract key %q", k)
		}
	}

	// per_metric must carry the five fixed CWV keys the dashboard indexes.
	var pm map[string]json.RawMessage
	if err := json.Unmarshal(m["per_metric"], &pm); err != nil {
		t.Fatalf("per_metric is not an object: %v", err)
	}
	for _, k := range []string{"lcp", "inp", "cls", "fcp", "ttfb"} {
		if _, ok := pm[k]; !ok {
			t.Errorf("per_metric JSON missing CWV key %q", k)
		}
	}
}
