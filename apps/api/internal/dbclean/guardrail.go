package dbclean

// SafeStatementCheck validates that sql is exactly ONE statement matching a
// safe cleanup shape from the agent's action set. It returns nil if the
// statement is safe, or a descriptive error if it is not.
//
// Accepted shapes (one table, one statement):
//   - DELETE FROM <t> WHERE <non-empty> [LIMIT n]
//   - TRUNCATE TABLE <t>
//   - DROP TABLE [IF EXISTS] <t>
//   - OPTIMIZE TABLE <t>
//   - ANALYZE TABLE <t>
//   - REPAIR TABLE <t>               (pre-screened by regex; TiDB cannot parse it)
//   - ALTER TABLE <t> ENGINE=InnoDB
//
// Rejected (returns error):
//   - Multiple/stacked statements (includes semicolon-hidden second statement)
//   - DELETE with no WHERE clause
//   - DELETE with multi-table join (IsMultiTable == true)
//   - UPDATE, INSERT, REPLACE, SELECT, GRANT, REVOKE, CREATE, SET, …
//   - OPTIMIZE / ANALYZE / TRUNCATE / DROP over multiple tables
//   - ALTER TABLE with anything other than a single ENGINE=InnoDB option
//   - Anything the TiDB parser cannot parse
//
// Driver note: the TiDB parser requires a value-expression driver to be
// registered before it can parse SQL that contains literals (string or numeric).
// This package registers the test_driver via a blank import in guardrail.go.
// The test_driver is the canonical self-contained driver shipped with the
// parser module itself and is correct for production use when no TiDB server
// types are needed — it is exactly the driver used by the parser's own tests.

import (
	"fmt"
	"regexp"
	"strings"

	tidbparser "github.com/pingcap/tidb/pkg/parser"
	"github.com/pingcap/tidb/pkg/parser/ast"
	_ "github.com/pingcap/tidb/pkg/parser/test_driver" // registers value-expression driver
)

// repairTableRE matches a syntactically valid REPAIR TABLE <single-table>
// statement (with optional backtick quoting and optional trailing semicolon).
// TiDB parser does not support REPAIR TABLE (it is MyISAM-only in MySQL),
// so we pre-screen it with this regexp before handing to the parser.
var repairTableRE = regexp.MustCompile(
	`(?i)^\s*REPAIR\s+TABLE\s+` + "`?" + `[a-zA-Z_][a-zA-Z0-9_]*` + "`?" + `\s*;?\s*$`,
)

// SafeStatementCheck parses sql and returns nil only when it is exactly one
// statement belonging to the safe cleanup shape set. Any deviation returns a
// non-nil error with a human-readable description.
func SafeStatementCheck(sql string) error {
	// -----------------------------------------------------------------------
	// Pre-screen REPAIR TABLE via regexp because TiDB parser cannot parse it.
	// The regex is conservative: single table name (alphanumeric/underscore),
	// optional backtick quoting, no additional clauses allowed.
	// -----------------------------------------------------------------------
	trimmed := strings.TrimSpace(sql)
	if repairTableRE.MatchString(trimmed) {
		// Ensure no second statement is hiding after a semicolon inside the
		// non-regexp part. The regex anchors on $ so this is already covered,
		// but we double-check by rejecting any semicolon not at the very end.
		withoutTrailingSemi := strings.TrimRight(trimmed, " \t\r\n;")
		if strings.ContainsRune(withoutTrailingSemi, ';') {
			return fmt.Errorf("guardrail: stacked statements rejected")
		}
		return nil
	}

	// -----------------------------------------------------------------------
	// Parse via TiDB parser. The test_driver (imported above) registers the
	// value-expression callbacks needed for literal handling; without it Parse
	// panics on any SQL containing string or numeric literals.
	// -----------------------------------------------------------------------
	p := tidbparser.New()
	stmts, _, err := p.Parse(sql, "", "")
	if err != nil {
		return fmt.Errorf("guardrail: parse error: %w", err)
	}

	if len(stmts) == 0 {
		return fmt.Errorf("guardrail: empty statement")
	}
	if len(stmts) > 1 {
		return fmt.Errorf("guardrail: multiple statements (%d) rejected", len(stmts))
	}

	stmt := stmts[0]
	return checkStatement(stmt)
}

// checkStatement validates the single parsed statement against the safe set.
func checkStatement(stmt ast.StmtNode) error {
	switch s := stmt.(type) {
	case *ast.DeleteStmt:
		return checkDelete(s)

	case *ast.TruncateTableStmt:
		// TRUNCATE TABLE <single table> — always safe.
		return nil

	case *ast.DropTableStmt:
		// DROP TABLE [IF EXISTS] — must target exactly one table, not a view.
		if s.IsView {
			return fmt.Errorf("guardrail: DROP VIEW is not allowed; only DROP TABLE is permitted")
		}
		if len(s.Tables) != 1 {
			return fmt.Errorf("guardrail: DROP TABLE must target exactly one table, got %d", len(s.Tables))
		}
		return nil

	case *ast.OptimizeTableStmt:
		if len(s.Tables) != 1 {
			return fmt.Errorf("guardrail: OPTIMIZE TABLE must target exactly one table, got %d", len(s.Tables))
		}
		return nil

	case *ast.AnalyzeTableStmt:
		if len(s.TableNames) != 1 {
			return fmt.Errorf("guardrail: ANALYZE TABLE must target exactly one table, got %d", len(s.TableNames))
		}
		return nil

	case *ast.AlterTableStmt:
		return checkAlterTable(s)

	default:
		return fmt.Errorf("guardrail: statement type %T is not in the safe cleanup set", stmt)
	}
}

// checkDelete validates a DELETE statement:
//   - Must NOT be multi-table (join DELETE).
//   - Must have a non-nil WHERE clause.
//   - LIMIT is allowed.
func checkDelete(s *ast.DeleteStmt) error {
	if s.IsMultiTable {
		return fmt.Errorf("guardrail: multi-table DELETE is not allowed")
	}
	if s.Where == nil {
		return fmt.Errorf("guardrail: DELETE without WHERE clause is rejected")
	}
	return nil
}

// checkAlterTable validates an ALTER TABLE statement:
//   - Must have exactly one spec.
//   - That spec must be AlterTableOption with a single TableOptionEngine option
//     whose value is "InnoDB" (case-insensitive).
func checkAlterTable(s *ast.AlterTableStmt) error {
	if len(s.Specs) != 1 {
		return fmt.Errorf("guardrail: ALTER TABLE must have exactly one spec, got %d", len(s.Specs))
	}
	spec := s.Specs[0]
	if spec.Tp != ast.AlterTableOption {
		return fmt.Errorf("guardrail: ALTER TABLE spec type %v is not permitted; only ENGINE=InnoDB is allowed", spec.Tp)
	}
	if len(spec.Options) != 1 {
		return fmt.Errorf("guardrail: ALTER TABLE ENGINE=InnoDB must have exactly one option, got %d", len(spec.Options))
	}
	opt := spec.Options[0]
	if opt.Tp != ast.TableOptionEngine {
		return fmt.Errorf("guardrail: ALTER TABLE option %v is not permitted; only ENGINE is allowed", opt.Tp)
	}
	if !strings.EqualFold(opt.StrValue, "InnoDB") {
		return fmt.Errorf("guardrail: ALTER TABLE ENGINE=%q is not permitted; only ENGINE=InnoDB is allowed", opt.StrValue)
	}
	return nil
}
