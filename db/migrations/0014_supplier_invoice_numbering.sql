-- MyInvoice.cz — Per-supplier konfigurace formátu čísla faktury (varsymbol).
--
-- Inspirováno NstyInvoice forkem — uživatel si v Nastavení → Číslování faktur
-- definuje vlastní šablonu (např. 'JD{YYYY}-{CC}' → 'JD2026-01') a kdy se
-- counter resetuje (year/month/none). Pokud per-supplier sloupec je NULL,
-- generator padá na fallback z cfg.varsymbol.templates.{type} (zachová zpětnou
-- kompatibilitu pro stávající instalaci, kde supplier zatím nic nemá nastaveno).
--
-- Druhá novinka: ruční override čísla faktury per-doklad — pole 'varsymbol'
-- v editoru. Sloupec `invoices.varsymbol` už existuje (VARCHAR(20)), takže
-- migrace tady nic nemění; jen rozšiřujeme `invoice_counters.period`
-- z CHAR(6) na VARCHAR(10), aby pojal 'YYYY' (year), 'YYYYMM' (month, legacy)
-- a 'ALL' (none) period keys.
--
-- Idempotent přes MariaDB native `IF NOT EXISTS` / `IF EXISTS` guards.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro varsymbol (typ invoice). NULL = fallback na cfg.varsymbol.templates.invoice.';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS proforma_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro varsymbol (typ proforma). NULL = fallback na cfg.';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS credit_note_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro varsymbol (typ credit_note). NULL = fallback na cfg.';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS invoice_number_period ENUM('year','month','none') NOT NULL DEFAULT 'month'
    COMMENT 'Reset countru: year = 1.1., month = 1. dne v měsíci, none = nikdy.';

-- Rozšiř period column z CHAR(6) na VARCHAR(10) pro podporu year/none scope.
-- MODIFY COLUMN IF EXISTS = no-op pokud sloupec nemá tu definici (MariaDB 10.0.2+).
-- Při opakovaném runu jen reapply, MariaDB ho ignoruje když type matchuje.
ALTER TABLE invoice_counters
  MODIFY COLUMN IF EXISTS period VARCHAR(10) NOT NULL;
