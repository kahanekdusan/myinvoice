-- 0128: Public invoice links (tokenized read-only invoice access)
--
-- Variant A: email delivery moves from PDF attachment to public token link.
--
-- Adds immutable per-invoice public token metadata for:
--   - /api/public/invoice/{token}         (detail)
--   - /api/public/invoice/{token}/pdf     (PDF stream)
--   - /api/public/invoice/{token}/heartbeat (view telemetry heartbeat)
--
-- Idempotent: ALTER TABLE ... ADD COLUMN/KEY IF NOT EXISTS.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS public_invoice_token CHAR(48) NULL DEFAULT NULL
        COMMENT 'Public invoice link token (bin2hex(random_bytes(24)))',
    ADD COLUMN IF NOT EXISTS public_invoice_token_expires_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Public link expiration timestamp',
    ADD COLUMN IF NOT EXISTS public_invoice_sent_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'When public invoice link was first sent by email',
    ADD COLUMN IF NOT EXISTS public_invoice_last_viewed_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Last successful public link open (detail/pdf)',
    ADD COLUMN IF NOT EXISTS public_invoice_last_heartbeat_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Last public heartbeat ping',
    ADD COLUMN IF NOT EXISTS public_invoice_view_count INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Public link view counter (detail/pdf opens)';

ALTER TABLE invoices
    ADD UNIQUE KEY IF NOT EXISTS uq_inv_public_invoice_token (public_invoice_token);

ALTER TABLE invoices
    ADD KEY IF NOT EXISTS idx_inv_public_invoice_token_exp (public_invoice_token_expires_at);

ALTER TABLE invoices
    ADD KEY IF NOT EXISTS idx_inv_public_invoice_seen (public_invoice_last_viewed_at);