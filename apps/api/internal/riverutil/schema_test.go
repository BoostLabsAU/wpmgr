package riverutil

import "testing"

// TestNormalizeSchema covers empty, default, custom, and invalid River schema
// identifiers so config parsing fails fast on values that would break SQL
// qualification.
func TestNormalizeSchema(t *testing.T) {
	tests := []struct {
		name    string
		in      string
		want    string
		wantErr bool
	}{
		{"empty", "", "", false},
		{"whitespace empty", "   ", "", false},
		{"public", "public", "public", false},
		{"media schema", "media_encoder", "media_encoder", false},
		{"custom safe", "Tenant_1", "Tenant_1", false},
		{"trim", " media_encoder ", "media_encoder", false},
		{"dotted", "media.encoder", "", true},
		{"quoted", `"media_encoder"`, "", true},
		{"dashed", "media-encoder", "", true},
		{"starts numeric", "1media", "", true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := NormalizeSchema(tt.in)
			if (err != nil) != tt.wantErr {
				t.Fatalf("NormalizeSchema(%q) err = %v, wantErr %v", tt.in, err, tt.wantErr)
			}
			if got != tt.want {
				t.Fatalf("NormalizeSchema(%q) = %q, want %q", tt.in, got, tt.want)
			}
		})
	}
}

// TestQualifiedTable verifies that River table references are left unqualified
// for the default/public schema and safely quoted only for custom schemas.
func TestQualifiedTable(t *testing.T) {
	tests := []struct {
		name    string
		schema  string
		table   string
		want    string
		wantErr bool
	}{
		{"empty schema", "", "river_job", "river_job", false},
		{"public schema", "public", "river_job", "river_job", false},
		{"media schema", "media_encoder", "river_job", `"media_encoder"."river_job"`, false},
		{"custom schema", "Tenant_1", "river_job", `"Tenant_1"."river_job"`, false},
		{"bad schema", "media.encoder", "river_job", "", true},
		{"bad table", "media_encoder", "river-job", "", true},
		{"quoted table", "media_encoder", `"river_job"`, "", true},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := QualifiedTable(tt.schema, tt.table)
			if (err != nil) != tt.wantErr {
				t.Fatalf("QualifiedTable(%q, %q) err = %v, wantErr %v", tt.schema, tt.table, err, tt.wantErr)
			}
			if got != tt.want {
				t.Fatalf("QualifiedTable(%q, %q) = %q, want %q", tt.schema, tt.table, got, tt.want)
			}
		})
	}
}

// TestIsDefaultSchema confirms that empty and public schemas keep the existing
// single-schema behavior, while any custom schema is treated as isolated.
func TestIsDefaultSchema(t *testing.T) {
	if !IsDefaultSchema("") {
		t.Fatal("empty schema should be default")
	}
	if !IsDefaultSchema("public") {
		t.Fatal("public schema should be default")
	}
	if IsDefaultSchema("media_encoder") {
		t.Fatal("media_encoder should not be default")
	}
}
