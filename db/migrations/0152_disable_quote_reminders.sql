-- Cenová nabídka není platební doklad a nesmí být nikdy upomínána.
-- ADD COLUMN je zde záměrně idempotentně: fork historicky přidal numbering_type
-- až migrací 9002, takže čistá instalace potřebuje sloupec už před tímto UPDATE.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS numbering_type ENUM('default','quote') NOT NULL DEFAULT 'default'
        AFTER payment_method;

UPDATE invoices
   SET auto_send_reminders = 0
 WHERE invoice_type = 'proforma'
   AND numbering_type = 'quote'
   AND auto_send_reminders <> 0;
