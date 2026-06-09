package rum

import (
	"context"
	"fmt"
	"time"

	"github.com/google/uuid"
)

// StoreClickHouse is a future opt-in RUM backend backed by ClickHouse, selected
// at boot when WPMGR_CLICKHOUSE_ADDR is set (mirroring the internal/metrics
// dual-backend pattern). Phase 1 ships only the Postgres backend; this stub
// satisfies the Store interface and returns ErrClickHouseNotImplemented for every
// call so that a misconfigured boot fails fast rather than silently dropping data.
//
// TODO: ClickHouse implementation (Phase 2+):
//   - WriteEvent: INSERT INTO rum_events (tenant_id, site_id, ...) via ClickHouse driver.
//   - FoldHourly/FoldDaily: run INSERT INTO rum_rollup_hourly SELECT … GROUP BY …
//     (ClickHouse AggregatingMergeTree or simple batch insert — TBD).
//   - GetHourlyRollups/GetDailyRollups: SELECT from the rollup table.
//   - Prune*: ALTER TABLE … DELETE WHERE (mutation) or TTL-based expiry.
type StoreClickHouse struct {
	addr string
}

// NewStoreClickHouse constructs a StoreClickHouse stub for the given address.
func NewStoreClickHouse(addr string) *StoreClickHouse {
	return &StoreClickHouse{addr: addr}
}

// Compile-time assertion: StoreClickHouse implements Store.
var _ Store = (*StoreClickHouse)(nil)

// ErrClickHouseNotImplemented is returned by every StoreClickHouse method until
// the ClickHouse backend is implemented. Boot wires in Postgres by default.
var ErrClickHouseNotImplemented = fmt.Errorf("rum: ClickHouse backend not yet implemented (addr=%q)", "")

func (s *StoreClickHouse) err() error {
	return fmt.Errorf("rum: ClickHouse backend not yet implemented (addr=%q); use Postgres (unset WPMGR_CLICKHOUSE_ADDR)", s.addr)
}

func (s *StoreClickHouse) WriteEvent(_ context.Context, _ IngestParams) error {
	return s.err()
}

func (s *StoreClickHouse) FoldHourly(_ context.Context, _, _ uuid.UUID, _ time.Time) error {
	return s.err()
}

func (s *StoreClickHouse) FoldDaily(_ context.Context, _, _ uuid.UUID, _ time.Time) error {
	return s.err()
}

func (s *StoreClickHouse) GetHourlyRollups(_ context.Context, _, _ uuid.UUID, _ time.Time) ([]HourlyRollup, error) {
	return nil, s.err()
}

func (s *StoreClickHouse) GetDailyRollups(_ context.Context, _, _ uuid.UUID, _ time.Time) ([]DailyRollup, error) {
	return nil, s.err()
}

func (s *StoreClickHouse) ComputeP75(rollups []HourlyRollup, minSampleCount int) []P75Result {
	// ComputeP75 is pure computation — no backend required. Delegate to the
	// shared implementation.
	return computeP75(rollups, minSampleCount)
}

func (s *StoreClickHouse) PruneRawEvents(_ context.Context, _ time.Time) (int64, error) {
	return 0, s.err()
}

func (s *StoreClickHouse) PruneHourlyRollups(_ context.Context, _ time.Time) (int64, error) {
	return 0, s.err()
}

func (s *StoreClickHouse) PruneDailyRollups(_ context.Context, _ time.Time) (int64, error) {
	return 0, s.err()
}
