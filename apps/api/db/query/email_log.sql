-- name: InsertEmailLog :one
-- Record a queued email before enqueuing the send_email job. Run under
-- Pool.InAgentTx (app.agent='on'); tenant_id may be NULL for auth mail.
INSERT INTO email_log (tenant_id, to_addresses, subject, template, status)
VALUES (@tenant_id, @to_addresses, @subject, @template, 'pending')
RETURNING *;

-- name: MarkEmailSent :exec
UPDATE email_log
SET status = 'sent', sent_at = now(), attempts = attempts + 1, error = NULL
WHERE id = @id;

-- name: MarkEmailFailed :exec
UPDATE email_log
SET status = 'failed', attempts = attempts + 1, error = @error
WHERE id = @id;

-- name: IncrEmailAttempts :exec
UPDATE email_log SET attempts = attempts + 1 WHERE id = @id;
