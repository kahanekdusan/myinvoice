-- Public invoice link tracking (secure token + telemetry)
--
-- Link-only delivery: klient dostane unikátní tokenizovaný odkaz místo PDF přílohy.
-- Token se ukládá pouze jako SHA-256 hash (nikdy plaintext), aby leak DB
-- neumožnil přímý přístup k fakturám.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN public_view_token_hash CHAR(64) NULL AFTER approval_reminder_count,
    ADD COLUMN public_view_token_created_at TIMESTAMP NULL AFTER public_view_token_hash,
    ADD COLUMN public_link_sent_at TIMESTAMP NULL AFTER public_view_token_created_at,
    ADD COLUMN public_first_opened_at TIMESTAMP NULL AFTER public_link_sent_at,
    ADD COLUMN public_last_opened_at TIMESTAMP NULL AFTER public_first_opened_at,
    ADD COLUMN public_open_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER public_last_opened_at,
    ADD COLUMN public_first_viewed_at TIMESTAMP NULL AFTER public_open_count,
    ADD COLUMN public_last_viewed_at TIMESTAMP NULL AFTER public_first_viewed_at,
    ADD COLUMN public_view_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER public_last_viewed_at,
    ADD COLUMN public_viewed_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER public_view_count;

CREATE UNIQUE INDEX uq_invoices_public_view_token_hash ON invoices (public_view_token_hash);
