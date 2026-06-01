-- MyInvoice.cz — dedikovaná šablona číslování cenových nabídek.
--
-- Cenové nabídky používají invoice_type='proforma'. Pro oddělené nastavení
-- číselné řady přidáváme per-supplier sloupec `quote_number_format`.
-- Pokud je NULL/prázdný, backend fallbackuje na `proforma_number_format`.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS quote_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro varsymbol cenové nabídky (invoice_type=proforma). NULL = fallback na proforma_number_format/cfg.';
