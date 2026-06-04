# corpus-gen — Plugin Signatures Corpus Builder

Offline tool that builds the `plugin_signatures` corpus for the WPMgr
Database Cleaner classifier. It queries the wordpress.org plugin API, downloads
plugin ZIP files, scans PHP source for known call-sites, and emits a SQL seed
migration.

## IMPORTANT: This tool lives in its own Go module

`tools/corpus-gen/go.mod` is a **separate module** from `apps/api`. This
means `go build ./...` inside `apps/api` never includes this tool. The
generated seed SQL is a **committed artifact** read by the API server through
the migration runner.

## Safety properties

- **ZIP-SLIP guard**: every extracted ZIP entry path is checked to not escape the
  destination directory. Entries with `..` components or absolute paths are
  silently skipped with a WARN log.
- **SSRF guard**: only `https://api.wordpress.org` and
  `https://downloads.wordpress.org` are allowed as outbound hosts. Any other
  host causes an immediate error.
- **Rate limit**: ~2 req/s shared across all workers; max 2 concurrent downloads
  by default (configurable up to 4); exponential backoff on 429.
- **Pattern validation**: every emitted pattern is compiled via
  `regexp.MustCompile` before being written. Invalid RE2 patterns are discarded
  with a WARN log.
- **Never executes PHP**: downloaded plugin code is only read as text. No eval,
  no exec, no import.
- **No source committed**: only extracted name-pattern strings are committed to
  the seed migration. Plugin ZIPs are downloaded to a temp directory only.

## How to regenerate the corpus

### Prerequisites

- Go 1.22+
- Network access to `api.wordpress.org` and `downloads.wordpress.org`

### Quick run (top 300 slugs)

```sh
cd tools/corpus-gen
go run . -n 300 -version 2 -out ../../apps/api/migrations/seeds/plugin_signatures_v2.sql
```

### Full top-3000 run

```sh
go run . -n 3000 -version 2 -workers 2 \
    -out ../../apps/api/migrations/seeds/plugin_signatures_v2.sql
```

### Flags

| Flag | Default | Description |
|------|---------|-------------|
| `-n` | 300 | Number of popular slugs to fetch |
| `-out` | `migrations/seeds/plugin_signatures_v{N}.sql` | Output file path |
| `-version` | 1 | `corpus_version` integer stamped in SQL |
| `-manifest` | `manifest.json` | Resumability manifest path |
| `-dry-run` | false | List slugs only; no download or SQL emit |
| `-workers` | 2 | Concurrent downloads (max 4) |
| `-input` | `input/plugins.yaml` | YAML config with extra slugs and skip list |

### Resumability

The tool writes `manifest.json` tracking which slugs have been extracted. If
the run is interrupted, re-running the same command resumes from where it left
off without re-downloading already-processed slugs.

### Deploying a new corpus version

1. Run corpus-gen to produce `plugin_signatures_v{N}.sql`.
2. Copy it to `apps/api/migrations/20260605010000_m40_1_plugin_signatures_seed.sql`
   (the original seed migration) or create a new migration file with a higher
   timestamp for incremental updates.
3. Run `go build ./...` in `apps/api` to verify the API still compiles.
4. Deploy; the migration runner will apply the seed on startup.

## Input YAML

`input/plugins.yaml` lets you add premium plugins not in the public API
(`extra_slug_versions`) or skip known-broken slugs (`skip_slugs`). The YAML
is optional; the tool falls back to API-only mode if it is absent.

## Pattern extraction logic

For each PHP file in a plugin ZIP, the tool applies three regexp passes:

1. **Call-site pass**: matches `add_option`, `update_option`, `get_option`,
   `delete_option`, `register_setting`, `wp_schedule_event`,
   `wp_schedule_single_event`, `wp_next_scheduled`,
   `wp_clear_scheduled_hook`, `wp_unschedule_hook` calls where the first
   argument is a quoted string literal. Captures the literal as an exact
   pattern.

2. **Prefix concat pass**: matches `'my_prefix_' . $variable` patterns and
   emits an anchored prefix regexp `^my_prefix_`.

3. **Table name pass**: matches `$wpdb->prefix . 'my_suffix'` and emits a
   table pattern `^wp_my_suffix`.

## Suppression

- **Blocklist**: generic literals (`settings`, `version`, `active`, `cache`,
  `options`, `data`, `config`, etc.) are never emitted.
- **Document-frequency suppression**: patterns appearing in 3 or more unrelated
  slugs are dropped to prevent false attributions.

## Licensing note

The corpus stores extracted factual identifiers (option/hook/table name
strings). Short functional strings are not protectable by copyright (see
*Feist Publications, Inc. v. Rural Telephone Service Co.*). No plugin source
code is committed. A per-slug source provenance record is maintained in
`manifest.json`.
