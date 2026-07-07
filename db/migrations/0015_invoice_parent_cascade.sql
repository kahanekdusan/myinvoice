-- MyInvoice.cz — ON DELETE CASCADE pro invoices.parent_invoice_id
--
-- Bez CASCADE jakékoliv `cancellation` nebo `credit_note` blokuje smazání původní
-- faktury (FK violation). Po feat-extension delete vystavených/stornovaných faktur
-- (admin only) chceme, aby smazání rodičovské faktury automaticky odstranilo
-- i navázané doklady (storno, dobropis, jejich items + work_reports — vše už
-- má CASCADE směrem dolů).
--
-- Smazání samotného storno / dobropisu rodičovskou fakturu neovlivní
-- (FK je směrem child → parent).
--
-- Idempotent přes MariaDB native `DROP FOREIGN KEY IF EXISTS` + ADD: na
-- opakovaný run FK znovu re-creates, výsledný stav je vždy stejný (CASCADE).

SET NAMES utf8mb4;

ALTER TABLE invoices
  DROP FOREIGN KEY IF EXISTS fk_inv_parent;

ALTER TABLE invoices
  ADD CONSTRAINT fk_inv_parent
    FOREIGN KEY (parent_invoice_id) REFERENCES invoices(id) ON DELETE CASCADE;
