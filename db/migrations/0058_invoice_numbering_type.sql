-- MyInvoice.cz — rozlišení číslování/varianty dokladu u proformy.
--
-- Cenová nabídka je ukládána jako invoice_type='proforma', ale pro PDF texty
-- a číslování potřebujeme odlišit variantu "quote".

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS numbering_type ENUM('default','quote') NOT NULL DEFAULT 'default'
    COMMENT 'Varianta dokladu pro proformu: default=zálohová faktura, quote=cenová nabídka.';
