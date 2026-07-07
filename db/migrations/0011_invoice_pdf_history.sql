-- MyInvoice.cz — Historie PDF souborů faktury.
--
-- Každý záznam = archivovaná verze PDF (přesunutá z aktivního cache do
-- _archive/ složky). Důvody archivace:
--   * 'sent'                  — PDF bylo odesláno emailem klientovi (audit trail)
--   * 'invalidate_update'     — faktura byla editována, staré PDF zmizelo z cache
--   * 'invalidate_issue'      — draft → issued, nový varsymbol/snapshoty
--   * 'invalidate_workreport' — výkaz práce změněn/smazán
--   * 'invalidate_currency'   — bank fields v měně změněny (drafty + bez snapshotu)
--   * 'invalidate_manual'     — explicit invalidate (admin akce)
--
-- was_sent = 1 značí, že tato verze byla skutečně odeslaná klientovi
-- (důkaz fakturace, nelze mazat). Cron-cleanup PDF nemaže.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS invoice_pdfs (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id   BIGINT UNSIGNED NOT NULL,
  filename     VARCHAR(255) NOT NULL,
  size_bytes   INT UNSIGNED NOT NULL,
  sha256       CHAR(64) NOT NULL,
  was_sent     TINYINT(1) NOT NULL DEFAULT 0,
  sent_to      JSON NULL,
  reason       VARCHAR(40) NOT NULL,
  archived_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_invpdf_invoice (invoice_id, archived_at DESC),
  KEY idx_invpdf_sha     (invoice_id, sha256),
  CONSTRAINT fk_invpdf_invoice FOREIGN KEY (invoice_id)
    REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
