-- name: InsertPasswordResetToken :one
-- Record a new reset token. Run under Pool.InAgentTx (app.agent='on').
INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, requested_ip)
VALUES (@user_id, @token_hash, @expires_at, @requested_ip)
RETURNING *;

-- name: ConsumePasswordResetToken :one
-- Atomically consume a token: mark it used IFF it exists, is unused, and is not
-- expired. Returns the row (incl. user_id) only when the consume succeeded, so
-- the caller cannot distinguish wrong/expired/used by anything but "no row".
UPDATE password_reset_tokens
SET used_at = now(), attempts = attempts + 1
WHERE token_hash = @token_hash
  AND used_at IS NULL
  AND expires_at > now()
RETURNING *;

-- name: InvalidateUserPasswordResetTokens :exec
-- Burn all outstanding reset tokens for a user (after a successful reset/change).
UPDATE password_reset_tokens
SET used_at = now()
WHERE user_id = @user_id AND used_at IS NULL;
