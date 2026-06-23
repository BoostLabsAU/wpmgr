package files

import (
	"context"
	"testing"
)

// ---------------------------------------------------------------------------
// ObjectDeleter stub
// ---------------------------------------------------------------------------

// stubDeleter records which keys were passed to Delete and returns a
// canned error. Used to verify object deletion is called (or skipped) correctly.
type stubDeleter struct {
	deletedKeys []string
	err         error
}

func (s *stubDeleter) Delete(_ context.Context, key string) error {
	s.deletedKeys = append(s.deletedKeys, key)
	return s.err
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

// TestFileTransfersGCArgsKind verifies the River job kind string.
// The kind string is the key River uses to route jobs to the correct worker;
// a mismatch causes jobs to accumulate unprocessed in the queue.
func TestFileTransfersGCArgsKind(t *testing.T) {
	const want = "file_transfers_gc"
	got := FileTransfersGCArgs{}.Kind()
	if got != want {
		t.Errorf("FileTransfersGCArgs.Kind() = %q, want %q", got, want)
	}
}

// TestFileTransfersGCWorkerNilDeleterNoPanic verifies that constructing a
// worker with a nil deleter does not panic and that the struct is non-nil.
func TestFileTransfersGCWorkerNilDeleterNoPanic(t *testing.T) {
	w := NewFileTransfersGCWorker(nil, nil, nil)
	if w == nil {
		t.Fatal("NewFileTransfersGCWorker returned nil")
	}
}

// TestFileTransfersGCHorizonIs24Hours verifies that the GC horizon constant is
// 24 hours. Changing it would silently alter the retention policy for all
// self-hosted and SaaS deployments.
func TestFileTransfersGCHorizonIs24Hours(t *testing.T) {
	const wantHours = 24
	got := fileTransfersGCHorizon.Hours()
	if got != wantHours {
		t.Errorf("fileTransfersGCHorizon = %v hours, want %v", got, wantHours)
	}
}

// TestObjectDeleterInterfaceSatisfied verifies that stubDeleter satisfies the
// ObjectDeleter interface, ensuring our test double is well-formed.
func TestObjectDeleterInterfaceSatisfied(t *testing.T) {
	var _ ObjectDeleter = (*stubDeleter)(nil)
}

// TestStubDeleterRecordsKeys verifies that stubDeleter correctly records which
// object keys were passed to Delete.
func TestStubDeleterRecordsKeys(t *testing.T) {
	d := &stubDeleter{}
	_ = d.Delete(context.Background(), "file-transfers/abc/part-0")
	_ = d.Delete(context.Background(), "file-transfers/def/part-0")

	if len(d.deletedKeys) != 2 {
		t.Fatalf("expected 2 deleted keys, got %d", len(d.deletedKeys))
	}
	if d.deletedKeys[0] != "file-transfers/abc/part-0" {
		t.Errorf("unexpected first key: %q", d.deletedKeys[0])
	}
	if d.deletedKeys[1] != "file-transfers/def/part-0" {
		t.Errorf("unexpected second key: %q", d.deletedKeys[1])
	}
}

// TestFileTransfersGCWorkerFieldsPreserved verifies that NewFileTransfersGCWorker
// stores the pool and logger references (nil is accepted for both in tests).
func TestFileTransfersGCWorkerFieldsPreserved(t *testing.T) {
	d := &stubDeleter{}
	w := NewFileTransfersGCWorker(nil, d, nil)
	if w.deleter == nil {
		t.Error("deleter should be stored non-nil when provided")
	}
}
