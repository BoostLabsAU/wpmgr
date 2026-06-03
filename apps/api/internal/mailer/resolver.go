package mailer

import (
	"context"
	"errors"
	"strings"

	"github.com/jackc/pgx/v5"

	"github.com/mosamlife/wpmgr/apps/api/internal/cryptbox"
	"github.com/mosamlife/wpmgr/apps/api/internal/db"
	"github.com/mosamlife/wpmgr/apps/api/internal/db/sqlc"
)

// EnvSMTP is the static env-configured relay (config.SMTPConfig), used only as a
// bootstrap fallback before a DB row exists. The DB row always wins.
type EnvSMTP struct {
	Host     string
	Port     int
	Username string
	Password string
	From     string
	TLSMode  string
}

func (e EnvSMTP) configured() bool { return strings.TrimSpace(e.Host) != "" }

// DBResolver loads the SMTP transport: the singleton smtp_settings row first
// (age-decrypting the stored password), falling back to the env relay when no
// enabled row exists. Reads run under app.agent='on' (Pool.InAgentTx) because
// smtp_settings is instance-global and the resolve path is pre-tenant.
type DBResolver struct {
	pool *db.Pool
	age  *cryptbox.AgeIdentity
	env  EnvSMTP
}

// NewDBResolver builds the resolver. env may be the zero value (no fallback).
func NewDBResolver(pool *db.Pool, age *cryptbox.AgeIdentity, env EnvSMTP) *DBResolver {
	return &DBResolver{pool: pool, age: age, env: env}
}

// Resolve returns the active transport. ok is false when neither a DB row nor
// the env fallback is configured.
func (r *DBResolver) Resolve(ctx context.Context) (Transport, bool, error) {
	var row sqlc.SmtpSetting
	var found bool
	err := r.pool.InAgentTx(ctx, func(tx pgx.Tx) error {
		s, err := sqlc.New(tx).GetSMTPSettings(ctx)
		if err != nil {
			if errors.Is(err, pgx.ErrNoRows) {
				return nil // no row yet -> fall back to env
			}
			return err
		}
		row, found = s, true
		return nil
	})
	if err != nil {
		return Transport{}, false, err
	}

	if found && row.Enabled && strings.TrimSpace(row.Host) != "" {
		password := ""
		if len(row.PasswordEnc) > 0 {
			plain, derr := r.age.Decrypt(row.PasswordEnc)
			if derr != nil {
				return Transport{}, false, derr
			}
			password = string(plain)
		}
		return Transport{
			Host:             row.Host,
			Port:             int(row.Port),
			Username:         row.Username,
			Password:         password,
			From:             row.FromAddress,
			FromName:         row.FromName,
			TLSMode:          row.TlsMode,
			AllowInsecureTLS: row.AllowInsecureTls,
		}, true, nil
	}

	// Env fallback (bootstrap before any DB row is configured).
	if r.env.configured() {
		mode := r.env.TLSMode
		if mode == "" {
			mode = "starttls"
		}
		port := r.env.Port
		if port == 0 {
			port = 587
		}
		return Transport{
			Host:     r.env.Host,
			Port:     port,
			Username: r.env.Username,
			Password: r.env.Password,
			From:     r.env.From,
			TLSMode:  mode,
		}, true, nil
	}

	return Transport{}, false, nil
}
