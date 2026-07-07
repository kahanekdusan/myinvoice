-- MyInvoice.cz — Pravidelné (opakované) faktury
--
-- Šablona drží konfiguraci periodicity + položky; cron každý den
-- (bin/cron-generate-recurring-invoices.php) projde aktivní šablony a u těch,
-- kde next_run_date <= today, vygeneruje fakturu (klon šablony) a posune
-- next_run_date o jeden cyklus.
--
-- Periodicity: monthly / quarterly / semi_annually / annually.
-- Den vystavení: buď konkrétní den 1-28 (day_of_month), nebo flag end_of_month
-- = 1 (poslední den měsíce, dynamicky 28/29/30/31).
--
-- auto_issue=1 → cron rovnou vystaví (přidělí varsymbol + snapshot, jinak draft).
-- auto_send_email=1 → po vystavení rovnou odešle klientovi.
-- status='paused' → cron přeskočí; 'expired' → next_run_date prošel end_date.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS,
-- ADD KEY IF NOT EXISTS, FK přes DROP IF EXISTS + ADD (MariaDB neumí
-- ADD CONSTRAINT IF NOT EXISTS, viz vzor v 0001/0015).

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Šablona pravidelné faktury
-- ==========================================================================

CREATE TABLE IF NOT EXISTS recurring_invoice_templates (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     TINYINT UNSIGNED NOT NULL,
  client_id       BIGINT UNSIGNED NOT NULL,
  project_id      BIGINT UNSIGNED NULL,
  name            VARCHAR(200) NOT NULL,                  -- display name v adminu, např. "Hosting Acme s.r.o."

  -- Periodicita
  frequency       ENUM('monthly','quarterly','semi_annually','annually') NOT NULL,
  day_of_month    TINYINT UNSIGNED NULL,                  -- 1-28; aplikuje se jen pokud end_of_month=0
  end_of_month    TINYINT(1) NOT NULL DEFAULT 0,          -- 1 = poslední den měsíce (dynamicky 28/29/30/31)

  -- Harmonogram
  anchor_date     DATE NOT NULL,                          -- první faktura
  end_date        DATE NULL,                              -- poslední možné next_run (inclusive); NULL = bez konce
  next_run_date   DATE NOT NULL,                          -- cron filter: status='active' AND next_run_date <= CURDATE()
  last_run_date   DATE NULL,                              -- audit kdy naposled cron generoval

  -- Faktura — kopíruje se 1:1 na vygenerovanou fakturu
  invoice_type    ENUM('invoice','proforma') NOT NULL DEFAULT 'invoice',
  currency_id     INT UNSIGNED NOT NULL,
  language        ENUM('cs','en') NOT NULL DEFAULT 'cs',
  payment_method  ENUM('bank_transfer','card','cash','other') NOT NULL DEFAULT 'bank_transfer',
  reverse_charge  TINYINT(1) NOT NULL DEFAULT 0,
  payment_due_days INT UNSIGNED NOT NULL DEFAULT 14,
  note_above_items TEXT NULL,
  note_below_items TEXT NULL,
  increment_month_in_descriptions TINYINT(1) NOT NULL DEFAULT 1,  -- regex M/YYYY → posun o 1 (jako BulkReissueAction)

  -- Cron chování — per-šablona přepínače (default oba ON = full automation)
  auto_issue      TINYINT(1) NOT NULL DEFAULT 1,          -- 1 = po generování rovnou vystavit; 0 = nechat draft
  auto_send_email TINYINT(1) NOT NULL DEFAULT 1,          -- 1 = po vystavení rovnou odeslat klientovi (vyžaduje auto_issue=1)

  status          ENUM('active','paused','expired') NOT NULL DEFAULT 'active',

  created_by      BIGINT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_rit_next_run (status, next_run_date),
  KEY idx_rit_supplier (supplier_id),
  KEY idx_rit_client   (client_id),
  KEY idx_rit_project  (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK přes DROP IF EXISTS + ADD (MariaDB neumí ADD CONSTRAINT IF NOT EXISTS)
ALTER TABLE recurring_invoice_templates
  DROP FOREIGN KEY IF EXISTS fk_rit_supplier,
  DROP FOREIGN KEY IF EXISTS fk_rit_client,
  DROP FOREIGN KEY IF EXISTS fk_rit_project,
  DROP FOREIGN KEY IF EXISTS fk_rit_currency,
  DROP FOREIGN KEY IF EXISTS fk_rit_user;

ALTER TABLE recurring_invoice_templates
  ADD CONSTRAINT fk_rit_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id),
  ADD CONSTRAINT fk_rit_client   FOREIGN KEY (client_id)   REFERENCES clients(id),
  ADD CONSTRAINT fk_rit_project  FOREIGN KEY (project_id)  REFERENCES projects(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rit_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  ADD CONSTRAINT fk_rit_user     FOREIGN KEY (created_by)  REFERENCES users(id);

-- ==========================================================================
-- 2. Položky šablony
-- ==========================================================================

CREATE TABLE IF NOT EXISTS recurring_invoice_template_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_id     BIGINT UNSIGNED NOT NULL,
  description     VARCHAR(500) NOT NULL,
  quantity        DECIMAL(10,3) NOT NULL DEFAULT 1,
  unit            VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price_without_vat DECIMAL(12,2) NOT NULL,
  vat_rate_id     INT UNSIGNED NOT NULL,
  order_index     INT NOT NULL DEFAULT 0,
  KEY idx_ritm_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recurring_invoice_template_items
  DROP FOREIGN KEY IF EXISTS fk_ritm_template,
  DROP FOREIGN KEY IF EXISTS fk_ritm_vat;

ALTER TABLE recurring_invoice_template_items
  ADD CONSTRAINT fk_ritm_template FOREIGN KEY (template_id) REFERENCES recurring_invoice_templates(id) ON DELETE CASCADE,
  ADD CONSTRAINT fk_ritm_vat      FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id);

-- ==========================================================================
-- 3. Vazba z vygenerované faktury zpět na šablonu (audit + UI badge)
-- ==========================================================================

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS recurring_template_id BIGINT UNSIGNED NULL AFTER parent_invoice_id;

ALTER TABLE invoices
  ADD KEY IF NOT EXISTS idx_inv_recurring (recurring_template_id);

ALTER TABLE invoices
  DROP FOREIGN KEY IF EXISTS fk_inv_recurring;

ALTER TABLE invoices
  ADD CONSTRAINT fk_inv_recurring FOREIGN KEY (recurring_template_id)
    REFERENCES recurring_invoice_templates(id) ON DELETE SET NULL;

-- ==========================================================================
-- 4. Per-supplier kill-switch (analogie auto_send_reminders)
-- ==========================================================================

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS auto_generate_recurring TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_send_reminders;
