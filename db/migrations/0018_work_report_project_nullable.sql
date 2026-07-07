-- MyInvoice.cz — work_reports.project_id NULL
--
-- Výkaz víceprací byl dosud povinně vázán na zakázku, ale faktury samy
-- zakázku nevyžadují (project_id na invoices je nullable). Při zakládání
-- faktury bez zakázky nešel přidat výkaz — SaveWorkReportAction padal na
-- "Chybí ID zakázky.".
--
-- Migrace uvolňuje FK na nullable a sjednocuje sémantiku s invoices.

SET NAMES utf8mb4;

-- MariaDB 10.0.2+: IF EXISTS guard, takže opakovaný run je no-op.
ALTER TABLE work_reports
  MODIFY COLUMN IF EXISTS project_id BIGINT UNSIGNED NULL;
