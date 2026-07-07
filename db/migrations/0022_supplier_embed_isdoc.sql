-- MyInvoice.cz — Per-supplier přepínač vkládání ISDOC přílohy do PDF.
--
-- Když je 1, InvoicePdfRenderer přidá do generovaného PDF embedded
-- soubor `invoice.isdoc` (XML dle ISDOC 6.0.2) jako PDF/A-3 attachment +
-- FileAttachment annotation s paperclip ikonou pod variabilním symbolem
-- (klik = stáhnout/extrahovat ISDOC v podporovaných PDF viewerech).
-- Default 1 = vkládat (CZ účetní SW si vytáhne ISDOC přímo z faktury).
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS embed_isdoc TINYINT(1) NOT NULL DEFAULT 1 AFTER auto_send_reminders;
