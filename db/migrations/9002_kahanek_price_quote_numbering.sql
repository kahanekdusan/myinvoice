-- Kahanek fork: price quotes stored as proformas with an independent number
-- series. Re-running is safe on databases that already received historical
-- migrations 0057, 0058 and 0059.

SET NAMES utf8mb4;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS quote_number_format VARCHAR(60) NULL DEFAULT NULL
        COMMENT 'Per-supplier quote number template; NULL falls back to proforma format.';

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS numbering_type ENUM('default','quote') NOT NULL DEFAULT 'default'
        COMMENT 'Proforma variant: default advance invoice or quote.';

ALTER TABLE invoice_counters
    MODIFY COLUMN invoice_type ENUM('invoice','proforma','credit_note','quote') NOT NULL;
