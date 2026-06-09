package rum

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
)

// ErrBeaconKeyNotFound is returned when LookupBeaconKey finds no matching hash.
var ErrBeaconKeyNotFound = errors.New("rum: beacon key not found")

// BeaconKeyLength is the number of random bytes in a beacon key (128 bits).
const BeaconKeyLength = 16

// GenerateBeaconKey generates a 128-bit random beacon key and returns:
//   - plaintext: the raw random bytes (base64url-encoded for the caller to
//     pass to the agent; never stored in the DB)
//   - keyHash: sha256(plaintext) stored in beacon_key_hash
//
// The plaintext MUST be returned to the agent in the perf-config response and
// MUST NOT be logged or stored anywhere else. After the response is sent, only
// the hash remains.
func GenerateBeaconKey() (plaintext string, keyHash []byte, err error) {
	raw := make([]byte, BeaconKeyLength)
	if _, err := rand.Read(raw); err != nil {
		return "", nil, fmt.Errorf("generate beacon key: %w", err)
	}
	pt := base64.RawURLEncoding.EncodeToString(raw)
	h := hashBeaconKey(raw)
	return pt, h, nil
}

// HashBeaconKeyFromPlaintext hashes the base64url-decoded plaintext beacon key.
// The ingest handler calls this to convert the presented plaintext into the
// stored sha256 hash for the LookupBeaconKey query.
func HashBeaconKeyFromPlaintext(plaintext string) ([]byte, error) {
	raw, err := base64.RawURLEncoding.DecodeString(plaintext)
	if err != nil {
		return nil, fmt.Errorf("decode beacon key: %w", err)
	}
	return hashBeaconKey(raw), nil
}

// hashBeaconKey computes sha256 of the raw key bytes.
func hashBeaconKey(raw []byte) []byte {
	h := sha256.Sum256(raw)
	return h[:]
}

// BeaconKeyRepo is the persistence layer for beacon-key operations. It wraps the
// two sqlc-generated beacon-key mutations (SetBeaconKeyHash, ClearBeaconKeyHashPrev).
// Dashboard reads (LookupBeaconKey) run under InRumIngestLookupTx and are called
// directly from the ingest handler's Repo, not here.
type BeaconKeyRepo struct {
	pool *db.Pool
}

// NewBeaconKeyRepo constructs a BeaconKeyRepo.
func NewBeaconKeyRepo(pool *db.Pool) *BeaconKeyRepo {
	return &BeaconKeyRepo{pool: pool}
}

// LookupBeaconKey resolves sha256(presented_key) to the site_id and tenant_id.
// Runs under InRumIngestLookupTx (SELECT-only, no tenant GUC required).
// Returns ErrBeaconKeyNotFound when no matching row exists.
func (r *BeaconKeyRepo) LookupBeaconKey(ctx context.Context, keyHash []byte) (sqlc.LookupRumBeaconKeyRow, error) {
	var out sqlc.LookupRumBeaconKeyRow
	err := r.pool.InRumIngestLookupTx(ctx, func(tx pgx.Tx) error {
		row, qerr := sqlc.New(tx).LookupRumBeaconKey(ctx, keyHash)
		if qerr != nil {
			if errors.Is(qerr, pgx.ErrNoRows) {
				return ErrBeaconKeyNotFound
			}
			return qerr
		}
		out = row
		return nil
	})
	return out, err
}

// RotateBeaconKey rotates the beacon key for a site: the existing
// beacon_key_hash becomes beacon_key_hash_prev (grace window) and newKeyHash
// becomes the current hash. Runs under InTenantTx (operator write path).
func (r *BeaconKeyRepo) RotateBeaconKey(ctx context.Context, tenantID, siteID uuid.UUID, newKeyHash []byte) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		return sqlc.New(tx).SetBeaconKeyHash(ctx, sqlc.SetBeaconKeyHashParams{
			BeaconKeyHash: newKeyHash,
			SiteID:        siteID,
		})
	})
}

// ClearPrevBeaconKey clears the grace-window previous hash once the rotation
// grace period expires. Runs under InTenantTx.
func (r *BeaconKeyRepo) ClearPrevBeaconKey(ctx context.Context, tenantID, siteID uuid.UUID) error {
	return r.pool.InTenantTx(ctx, tenantID, func(tx pgx.Tx) error {
		return sqlc.New(tx).ClearBeaconKeyHashPrev(ctx, siteID)
	})
}
