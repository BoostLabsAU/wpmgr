-- name: InsertEmailVerificationToken :one
-- Run under Pool.InAgentTx (app.agent='on').
INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
VALUES (@user_id, @token_hash, @expires_at)
RETURNING *;

-- name: ConsumeEmailVerificationToken :one
-- Atomically consume an unused, unexpired verification token.
UPDATE email_verification_tokens
SET used_at = now()
WHERE token_hash = @token_hash
  AND used_at IS NULL
  AND expires_at > now()
RETURNING *;

-- name: InvalidateUserEmailVerificationTokens :exec
UPDATE email_verification_tokens
SET used_at = now()
WHERE user_id = @user_id AND used_at IS NULL;

-- name: SetUserPending :exec
UPDATE users SET status = 'pending', updated_at = now() WHERE id = $1;

-- name: MarkUserEmailVerified :exec
-- Activate + mark verified (used on self-serve activation and trusted bootstrap).
UPDATE users SET status = 'active', email_verified_at = now(), updated_at = now() WHERE id = $1;
