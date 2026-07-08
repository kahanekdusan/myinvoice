-- 0129: Microsoft SMTP OAuth connection storage (admin integration scaffold)
--
-- Stores per-supplier OAuth metadata for Microsoft SMTP modern auth.
-- This migration introduces storage only; runtime mailer wiring can evolve
-- independently while the connection flow is already available in admin UI.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS smtp_oauth_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  provider ENUM('microsoft') NOT NULL DEFAULT 'microsoft',
  tenant_id VARCHAR(120) NOT NULL DEFAULT 'common',
  client_id VARCHAR(190) NULL,
  client_secret_enc VARCHAR(512) NULL,
  refresh_token_enc TEXT NULL,
  access_token_enc TEXT NULL,
  access_token_expires_at DATETIME NULL,
  mailbox VARCHAR(190) NULL,
  scope VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  connected_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_smtp_oauth_supplier_provider (supplier_id, provider),
  KEY idx_smtp_oauth_connected (provider, connected_at),
  CONSTRAINT fk_smtp_oauth_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_smtp_oauth_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;