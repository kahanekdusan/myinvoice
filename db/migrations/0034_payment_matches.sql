-- MyInvoice.cz — fáze 3: payment_matches (bank matching pro přijaté faktury)
--
-- N:N model pro párování bankovních transakcí na faktury. Doplňuje legacy 1:1
-- model `bank_transactions.matched_invoice_id` (jen pro vystavené faktury).
--
-- Důvod N:N: jedna platba může pokrývat vícero faktur (souhrnná úhrada),
-- jedna faktura může být uhrazena více splátkami (zatím nepoužito, ale schema
-- to umožní bez další migrace).
--
-- ROW XOR: invoice_id NEBO purchase_invoice_id, ne oboje, ne ani jedno.
-- (MariaDB 10.6+ enforced CHECK constraint.)
--
-- Pozn.: Tato migrace měla být součástí commitu c540d46 (feat(phase-3): bank
-- matching pro přijaté faktury), ale soubor se omylem nedostal do gitu —
-- doplněno ex-post 2026-05-22. Tabulka byla na lokálním dev DB vytvořena
-- ručně, tato migrace ji idempotentně vytvoří i na produkci.
--
-- Idempotence: CREATE TABLE IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

CREATE TABLE IF NOT EXISTS payment_matches (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Multi-tenant scope — konzistentní s ostatními tabulkami (TINYINT).
    supplier_id           TINYINT UNSIGNED NOT NULL,

    bank_transaction_id   BIGINT UNSIGNED NOT NULL,

    -- XOR: jedna z těchto je NULL, druhá ne (vynuceno CHECK constraintem).
    invoice_id            BIGINT UNSIGNED NULL,
    purchase_invoice_id   BIGINT UNSIGNED NULL,

    -- Částka přiřazená této faktuře (pro splátky < amount transakce).
    -- Pro běžný full-match = abs(bank_transactions.amount).
    amount                DECIMAL(15,2) NOT NULL,

    match_type            ENUM('auto', 'manual') NOT NULL DEFAULT 'auto',

    -- 0-100 (jen pro auto matche, NULL pro manual).
    match_confidence      TINYINT UNSIGNED NULL,

    -- Audit pro manual matche — kdo to spároval.
    matched_by_user_id    BIGINT UNSIGNED NULL,

    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_pm_supplier      (supplier_id),
    KEY idx_pm_tx            (bank_transaction_id),
    KEY idx_pm_invoice       (invoice_id),
    KEY idx_pm_purchase      (purchase_invoice_id),
    KEY idx_pm_user          (matched_by_user_id),

    CONSTRAINT fk_pm_supplier  FOREIGN KEY (supplier_id)
        REFERENCES supplier(id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_tx        FOREIGN KEY (bank_transaction_id)
        REFERENCES bank_transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_invoice   FOREIGN KEY (invoice_id)
        REFERENCES invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_purchase  FOREIGN KEY (purchase_invoice_id)
        REFERENCES purchase_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_pm_user      FOREIGN KEY (matched_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL,

    CONSTRAINT chk_pm_target_xor CHECK (
        (invoice_id IS NOT NULL AND purchase_invoice_id IS NULL)
        OR (invoice_id IS NULL AND purchase_invoice_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
