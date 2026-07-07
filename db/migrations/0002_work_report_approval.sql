-- MyInvoice.cz — schvalování výkazu práce zákazníkem
-- Spec: feature/work-report-approval
-- Doplňuje: projects.requires_work_report_approval
--           invoices.approval_status / token / timestamps / decided_by / rejection_reason
--           email_templates seed pro 'invoice_approval' (cs, en)
--
-- Idempotent přes MariaDB native `IF NOT EXISTS` guards (MariaDB 10.0.2+).
-- Project vyžaduje MariaDB 10.6+, takže to je bezpečné.

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Projects — flag, že tento projekt vyžaduje schválení výkazu zákazníkem
-- ==========================================================================

ALTER TABLE projects
  ADD COLUMN IF NOT EXISTS requires_work_report_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- ==========================================================================
-- 2. Invoices — stav schvalovacího procesu
-- ==========================================================================

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS approval_status ENUM('none','requested','approved','rejected') NOT NULL DEFAULT 'none' AFTER status;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS approval_token VARCHAR(64) NULL DEFAULT NULL AFTER approval_status;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS approval_requested_at TIMESTAMP NULL DEFAULT NULL AFTER approval_token;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS approval_decided_at TIMESTAMP NULL DEFAULT NULL AFTER approval_requested_at;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS approval_decided_by_email VARCHAR(190) NULL DEFAULT NULL AFTER approval_decided_at;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS approval_rejection_reason TEXT NULL DEFAULT NULL AFTER approval_decided_by_email;

ALTER TABLE invoices
  ADD UNIQUE KEY IF NOT EXISTS uq_inv_approval_token (approval_token);

ALTER TABLE invoices
  ADD KEY IF NOT EXISTS idx_inv_approval_status (approval_status);

-- Šablona invoice_approval (cs/en) je file-based v api/templates/email/.
-- Admin si může vytvořit override v UI Email Templates → vytvoří se řádek v email_templates.
