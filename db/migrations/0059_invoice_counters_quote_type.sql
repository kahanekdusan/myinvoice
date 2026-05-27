-- MyInvoice.cz — povolení quote typu v invoice_counters.
--
-- Cenová nabídka používá vlastní číselnou řadu (type=quote). Bez této úpravy
-- INSERT do invoice_counters padá na enum truncation (SQLSTATE 01000/1265).

SET NAMES utf8mb4;

ALTER TABLE invoice_counters
  MODIFY COLUMN invoice_type ENUM('invoice','proforma','credit_note','quote') NOT NULL;
