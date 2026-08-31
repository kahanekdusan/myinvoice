-- Cenová nabídka může být ručně označena jako propadnutá.
-- Opakované spuštění zachová stejnou definici sloupce.

SET NAMES utf8mb4;

ALTER TABLE invoices
    MODIFY COLUMN IF EXISTS approval_status
        ENUM('none','requested','approved','expired','rejected') NOT NULL DEFAULT 'none';
