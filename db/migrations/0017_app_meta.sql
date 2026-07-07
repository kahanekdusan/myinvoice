-- MyInvoice.cz — app_meta key/value cache
--
-- Generic key/value bucket pro infrastrukturní cache, kterou nechceme řešit
-- přes Redis (musí přežít flush) ani per-supplier (je globální). První
-- use-case: cache poslední dostupné verze z GitHub Releases API a release
-- notes — denně refreshuje cron-version-check.php.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS app_meta (
  k          VARCHAR(64) NOT NULL PRIMARY KEY,
  v          MEDIUMTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
