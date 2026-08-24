-- Kahanek fork: secure public invoice link telemetry.
--
-- Custom migrations intentionally use the 9000+ namespace so regular
-- upstream migrations can continue to be merged without filename clashes.
-- Re-running is safe on databases that already received the historical
-- 0049_invoice_public_link_tracking.sql migration.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS public_view_token_hash CHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS public_view_token_created_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS public_link_sent_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS public_first_opened_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS public_last_opened_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS public_open_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS public_first_viewed_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS public_last_viewed_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS public_view_count INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS public_viewed_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    ADD UNIQUE KEY IF NOT EXISTS uq_invoices_public_view_token_hash (public_view_token_hash);
