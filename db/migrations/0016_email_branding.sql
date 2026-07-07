-- MyInvoice.cz — Per-supplier email branding (issue #5 follow-up)
--
-- Přidává sloupce pro custom branding hlavičky odchozích emailů:
--   - email_branding_enabled  TINYINT(1) — opt-in toggle (default 0 = MyInvoice branding)
--   - email_accent_color      VARCHAR(7) — hex barva napříč emailem (default fialová MyInvoice)
--
-- Reuse existujících polí: display_name (brand name), tagline (subtitle), logo_path (logo file).
--
-- Idempotent přes MariaDB native `IF NOT EXISTS` guards.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS email_branding_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER tagline;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS email_accent_color VARCHAR(7) NOT NULL DEFAULT '#3B2D83' AFTER email_branding_enabled;
