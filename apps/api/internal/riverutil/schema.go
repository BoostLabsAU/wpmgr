package riverutil

import (
	"context"
	"fmt"
	"regexp"
	"strings"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/riverqueue/river/riverdriver/riverpgxv5"
	"github.com/riverqueue/river/rivermigrate"
)

var safeIdentifierRE = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]*$`)

// NormalizeSchema trims and validates an optional Postgres schema identifier.
func NormalizeSchema(raw string) (string, error) {
	schema := strings.TrimSpace(raw)
	if schema == "" {
		return "", nil
	}
	if !safeIdentifierRE.MatchString(schema) {
		return "", fmt.Errorf("invalid River schema %q: use a simple Postgres identifier", raw)
	}
	return schema, nil
}

// IsDefaultSchema reports whether schema should use the connection's default
// search_path behavior.
func IsDefaultSchema(schema string) bool {
	return schema == "" || strings.EqualFold(schema, "public")
}

// QualifiedTable returns a validated table reference for raw SQL touching River
// tables. Queue names and all other values must remain SQL parameters.
func QualifiedTable(schema, table string) (string, error) {
	table = strings.TrimSpace(table)
	if !safeIdentifierRE.MatchString(table) {
		return "", fmt.Errorf("invalid River table %q: use a simple Postgres identifier", table)
	}
	schema, err := NormalizeSchema(schema)
	if err != nil {
		return "", err
	}
	if IsDefaultSchema(schema) {
		return table, nil
	}
	return pgx.Identifier{schema, table}.Sanitize(), nil
}

// EnsureSchema creates, grants, and migrates a non-default River schema. Empty
// and public schemas intentionally keep the existing single-schema behavior.
func EnsureSchema(ctx context.Context, pool *pgxpool.Pool, schema, appRole string) error {
	schema, err := NormalizeSchema(schema)
	if err != nil {
		return err
	}
	if IsDefaultSchema(schema) {
		return nil
	}
	appRole = strings.TrimSpace(appRole)
	if appRole == "" {
		return fmt.Errorf("app role is required for River schema grants")
	}

	schemaIdent := pgx.Identifier{schema}.Sanitize()
	roleIdent := pgx.Identifier{appRole}.Sanitize()
	preMigration := []string{
		fmt.Sprintf("CREATE SCHEMA IF NOT EXISTS %s", schemaIdent),
		fmt.Sprintf("GRANT USAGE ON SCHEMA %s TO %s", schemaIdent, roleIdent),
		fmt.Sprintf("ALTER DEFAULT PRIVILEGES IN SCHEMA %s GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %s", schemaIdent, roleIdent),
		fmt.Sprintf("ALTER DEFAULT PRIVILEGES IN SCHEMA %s GRANT USAGE, SELECT ON SEQUENCES TO %s", schemaIdent, roleIdent),
	}
	for _, stmt := range preMigration {
		if _, err := pool.Exec(ctx, stmt); err != nil {
			return fmt.Errorf("prepare River schema %q: %w", schema, err)
		}
	}

	migrator, err := rivermigrate.New(riverpgxv5.New(pool), &rivermigrate.Config{Schema: schema})
	if err != nil {
		return fmt.Errorf("river migrator for schema %q: %w", schema, err)
	}
	if _, err := migrator.Migrate(ctx, rivermigrate.DirectionUp, nil); err != nil {
		return fmt.Errorf("river migrate schema %q: %w", schema, err)
	}

	postMigration := []string{
		fmt.Sprintf("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA %s TO %s", schemaIdent, roleIdent),
		fmt.Sprintf("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA %s TO %s", schemaIdent, roleIdent),
	}
	for _, stmt := range postMigration {
		if _, err := pool.Exec(ctx, stmt); err != nil {
			return fmt.Errorf("grant River schema %q: %w", schema, err)
		}
	}
	return nil
}
