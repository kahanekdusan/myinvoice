-- MyInvoice.cz — Cenové nabídky (quotes) — nový typ dokladu
--
-- Samostatný doklad postavený podle vzoru vydaných faktur (invoices), ale bez vazby
-- na DPH výkazy (cenová nabídka NENÍ daňový doklad — nikdy nejde přes VatLedgerService).
-- Z nabídky lze vygenerovat vydanou fakturu (stav ? 'invoiced') nebo zálohovou fakturu
-- (proforma; stav ? 'ordered', lze vystavit více proform z jedné nabídky).
--
-- Konvence sdílené s fakturami:
--   • client_snapshot / supplier_snapshot / bank_snapshot v JSON (zafixování údajů)
--   • per-položkové total_* + header total_* (sumace řádků, přepočet QuoteCalculator)
--   • sleva na doklad: quotes.discount_percent je zdroj pravdy, materializuje se do
--     ZÁPORNÝCH slevových položek quote_items.item_kind='discount' (jako u faktur) ?
--     konzistentní přenos slevy při konverzi na fakturu.
--   • prices_include_vat (režim brutto) se přenáší na vygenerovanou fakturu.
--
-- Číslování: SAMOSTATNÁ řada mimo invoice_counters (quote_counters + supplier.quote_number_format),
-- generuje QuoteNumberGenerator při vytvoření nabídky (ne až při „vystavení" jako varsymbol).
--
-- Idempotence: MariaDB-native CREATE TABLE / ADD COLUMN / ADD ... IF [NOT] EXISTS. Re-run safe.

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. quotes — hlavička cenové nabídky
-- ==========================================================================
CREATE TABLE IF NOT EXISTS quotes (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,                    -- multi-tenant denorm; číslovací counter scoped
  quote_number        VARCHAR(30) NULL,                         -- generuje QuoteNumberGenerator při vytvoření
  client_id           BIGINT UNSIGNED NOT NULL,
  project_id          BIGINT UNSIGNED NULL,
  status              ENUM('draft','sent','ordered','invoiced','rejected') NOT NULL DEFAULT 'draft',
  issue_date          DATE NOT NULL,                            -- datum vystavení
  valid_until         DATE NULL,                                -- platnost do
  currency_id         INT UNSIGNED NOT NULL,                    -- nese i bankovní účet (jako u faktur)
  exchange_rate       DECIMAL(15,6) NULL,
  exchange_rate_date  DATE NULL,
  reverse_charge      TINYINT(1) NOT NULL DEFAULT 0,
  prices_include_vat  TINYINT(1) NOT NULL DEFAULT 0,            -- brutto režim — přenáší se na fakturu
  language            ENUM('cs','en') NOT NULL DEFAULT 'cs',
  payment_method      ENUM('bank_transfer','card','cash','other') NOT NULL DEFAULT 'bank_transfer',
  order_number        VARCHAR(100) NULL,                        -- číslo objednávky/smlouvy
  description         VARCHAR(255) NULL,                        -- krátký popis nabídky
  note                TEXT NULL,                                -- interní poznámka (nezobrazuje se na dokladu)
  note_above_items    TEXT NULL,                                -- text před položkami (přenáší se na fakturu)
  note_below_items    TEXT NULL,                                -- text za položkami (přenáší se na fakturu)
  discount_percent    DECIMAL(5,2) NOT NULL DEFAULT 0,          -- sleva na doklad (zdroj pravdy)
  client_snapshot     JSON NULL,
  supplier_snapshot   JSON NULL,
  bank_snapshot       JSON NULL,
  total_without_vat   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_vat           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_with_vat      DECIMAL(12,2) NOT NULL DEFAULT 0,
  rounding            DECIMAL(6,2) NOT NULL DEFAULT 0,
  created_by          BIGINT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          TIMESTAMP NULL,                           -- soft delete
  UNIQUE KEY uq_quote_supplier_number (supplier_id, quote_number),
  KEY idx_quote_client  (client_id, issue_date DESC),
  KEY idx_quote_status  (supplier_id, status),
  KEY idx_quote_issued  (supplier_id, issue_date),
  KEY idx_quote_valid   (valid_until),
  KEY idx_quote_deleted (deleted_at),
  CONSTRAINT fk_quote_client   FOREIGN KEY (client_id)   REFERENCES clients(id),
  CONSTRAINT fk_quote_project  FOREIGN KEY (project_id)  REFERENCES projects(id),
  CONSTRAINT fk_quote_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_quote_user     FOREIGN KEY (created_by)  REFERENCES users(id),
  CONSTRAINT fk_quote_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 2. quote_items — položky (zrcadlí invoice_items + item_kind slevové položky)
-- ==========================================================================
CREATE TABLE IF NOT EXISTS quote_items (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id                 BIGINT UNSIGNED NOT NULL,
  description              TEXT NOT NULL,
  quantity                 DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit                     VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price_without_vat   DECIMAL(12,2) NOT NULL,
  vat_rate_id              INT UNSIGNED NOT NULL,
  vat_rate_snapshot        DECIMAL(5,2) NOT NULL,
  total_without_vat        DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_vat                DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_with_vat           DECIMAL(12,2) NOT NULL DEFAULT 0,
  order_index              INT NOT NULL DEFAULT 0,
  item_kind                ENUM('standard','discount') NOT NULL DEFAULT 'standard',
  KEY idx_qi_quote (quote_id, order_index),
  CONSTRAINT fk_qi_quote FOREIGN KEY (quote_id)    REFERENCES quotes(id) ON DELETE CASCADE,
  CONSTRAINT fk_qi_vat   FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 3. quote_counters — samostatný číselník (scope supplier_id + period)
-- ==========================================================================
CREATE TABLE IF NOT EXISTS quote_counters (
  supplier_id  INT UNSIGNED NOT NULL,
  period       VARCHAR(10) NOT NULL,             -- 'YYYY' (year), 'YYYYMM' (month), 'ALL' (none)
  last_number  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (supplier_id, period),
  CONSTRAINT fk_qc_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 4. supplier — nastavení číslování + platnosti nabídek
-- ==========================================================================
ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS quote_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier template pro číslo nabídky. NULL = fallback CN{YYYY}{CCCC}.';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS quote_number_period ENUM('year','month','none') NOT NULL DEFAULT 'year'
    COMMENT 'Reset counteru nabídek: year = 1.1., month = 1. dne v měsíci, none = nikdy.';

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS quote_validity_days INT UNSIGNED NOT NULL DEFAULT 14
    COMMENT 'Výchozí počet dní platnosti nové cenové nabídky (issue_date + N).';

-- ==========================================================================
-- 5. invoices.source_quote_id — vazba faktura ? zdrojová nabídka
-- ==========================================================================
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS source_quote_id BIGINT UNSIGNED NULL
    COMMENT 'Cenová nabídka, ze které tato faktura vznikla (konverze quote ? invoice/proforma).';

ALTER TABLE invoices
  ADD INDEX IF NOT EXISTS idx_inv_source_quote (source_quote_id);

ALTER TABLE invoices
  ADD CONSTRAINT fk_inv_source_quote FOREIGN KEY IF NOT EXISTS (source_quote_id)
    REFERENCES quotes(id) ON DELETE SET NULL;
