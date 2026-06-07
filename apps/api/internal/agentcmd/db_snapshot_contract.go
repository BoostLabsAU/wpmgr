package agentcmd

// db_snapshot_contract.go — CP->agent contract for the db_snapshot command (#189).
//
// The agent mirrors these shapes in
// apps/agent/includes/commands/class-db-snapshot-command.php.
// Field names are JSON wire names — do not rename without updating both sides.
//
// Transport: POST {site_url}/wp-json/wpmgr/v1/command/db_snapshot
//   Header:  Authorization: Bearer <minted EdDSA JWT, cmd="db_snapshot">
//   Body:    DbSnapshotRequest (application/json)
//   Response: DbSnapshotResult

// DbSnapshotRequest is the POST body for the `db_snapshot` command.
//
//	action       One of "create", "list", "revert", "delete".
//	label        Human-readable label attached to the snapshot (create only).
//	retention    How many snapshots to keep after this create (1–20, default 5).
//	snapshot_id  Identifier of the target snapshot (revert, delete).
//	confirm      Must equal "REVERT" for the revert action (destructive-gate).
type DbSnapshotRequest struct {
	Action     string `json:"action"`
	Label      string `json:"label,omitempty"`
	Retention  int    `json:"retention,omitempty"`
	SnapshotID string `json:"snapshot_id,omitempty"`
	Confirm            string `json:"confirm,omitempty"`
	SkipSafetySnapshot bool   `json:"skip_safety_snapshot,omitempty"`
}

// DbSnapshotEntry is one row in the list returned by action=list.
//
//	id          Unique snapshot identifier (snap_<24 hex chars>).
//	label       Operator-supplied label, may be empty.
//	created_at  Unix timestamp of when the snapshot was created.
//	size        Compressed SQL file size in bytes.
//	table_count Number of database tables captured.
type DbSnapshotEntry struct {
	ID         string `json:"id"`
	Label      string `json:"label"`
	CreatedAt  int64  `json:"created_at"`
	Size       int64  `json:"size"`
	TableCount int    `json:"table_count"`
}

// DbSnapshotResult is the agent's synchronous ACK for `db_snapshot`.
//
// ok=false with detail means the agent refused or the operation failed.
//
//	snapshot   Present on a successful create; contains the new snapshot entry.
//	snapshots  Present on a successful list; all entries, newest first.
//	detail     Human-readable result or error description.
//	safety_id  Present on a successful revert; the auto-safety snapshot taken
//	           immediately before the import (may be "" if the safety snapshot
//	           failed non-fatally).
type DbSnapshotResult struct {
	OK        bool              `json:"ok"`
	Snapshot  *DbSnapshotEntry  `json:"snapshot,omitempty"`
	Snapshots []DbSnapshotEntry `json:"snapshots,omitempty"`
	Detail    string            `json:"detail,omitempty"`
	SafetyID  string            `json:"safety_id,omitempty"`
}
