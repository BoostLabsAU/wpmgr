-- name: GetSMTPSettings :one
-- Fetch the single instance SMTP row. Run under Pool.InAgentTx (app.agent='on').
SELECT * FROM smtp_settings WHERE singleton = true;

-- name: UpsertSMTPSettings :one
-- Insert-or-update the singleton row. password_enc uses a nil-sentinel:
-- when @set_password is false the existing ciphertext is preserved, so editing
-- other fields without re-entering the password keeps the stored secret.
INSERT INTO smtp_settings (
    singleton, enabled, host, port, username, password_enc,
    from_address, from_name, tls_mode, allow_insecure_tls, updated_by, updated_at
) VALUES (
    true, @enabled, @host, @port, @username,
    -- ::bytea so Postgres can infer the param type (both CASE branches are
    -- otherwise untyped: a bare param + NULL -> "could not determine data type").
    CASE WHEN @set_password::boolean THEN @password_enc::bytea ELSE NULL END,
    @from_address, @from_name, @tls_mode, @allow_insecure_tls, @updated_by, now()
)
ON CONFLICT (singleton) DO UPDATE SET
    enabled            = EXCLUDED.enabled,
    host               = EXCLUDED.host,
    port               = EXCLUDED.port,
    username           = EXCLUDED.username,
    password_enc       = CASE WHEN @set_password::boolean THEN EXCLUDED.password_enc
                              ELSE smtp_settings.password_enc END,
    from_address       = EXCLUDED.from_address,
    from_name          = EXCLUDED.from_name,
    tls_mode           = EXCLUDED.tls_mode,
    allow_insecure_tls = EXCLUDED.allow_insecure_tls,
    updated_by         = EXCLUDED.updated_by,
    updated_at         = now()
RETURNING *;
