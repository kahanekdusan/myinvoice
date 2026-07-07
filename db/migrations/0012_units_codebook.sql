-- MyInvoice.cz — Číselník měrných jednotek (units).
--
-- Globální (NE per-supplier) tabulka pro výběr jednotek v editoru položek
-- faktury. Nahrazuje volný text input dropdown z číselníku.
--
-- Default: jedna položka označená is_default=1 se použije jako pre-filled
-- hodnota při přidání nové běžné položky faktury. Default je 'h' (hodina) —
-- nová položka přebírá hodinovou sazbu z projektu/klienta, takže jednotka
-- musí být kompatibilní (h × Kč/h). Pro non-hodinové položky uživatel
-- jednotku přepne ručně.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS units (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20) NOT NULL,
  label_cs      VARCHAR(60) NOT NULL,
  label_en      VARCHAR(60) NOT NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  display_order INT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_units_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO units (code, label_cs, label_en, is_default, display_order) VALUES
  ('h',  'hodina', 'hour',  1, 10),
  ('ks', 'kus',    'piece', 0, 20)
ON DUPLICATE KEY UPDATE code = VALUES(code);
