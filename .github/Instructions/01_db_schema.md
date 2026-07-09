# 01 – Databázové schéma: Cenové nabídky

## Cíl

Vytvořit migrace pro tabulky `quotes` a `quote_items` analogicky ke stávajícím tabulkám `invoices` / `invoice_items`.

---

## Tabulka `quotes`

```sql
CREATE TABLE `quotes` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `supplier_id`         INT UNSIGNED NOT NULL,         -- FK na suppliers (aktuální dodavatel/firma)
  `client_id`           INT UNSIGNED NULL,             -- FK na clients (odběratel z adresáře)
  `quote_number`        VARCHAR(30) NOT NULL,           -- generované číslo dle číselné řady
  `number_series_id`    INT UNSIGNED NULL,              -- FK na number_series (číselná řada)
  `status`              ENUM(
                          'draft',        -- Vytvořena
                          'sent',         -- Odeslána
                          'ordered',      -- Objednána
                          'invoiced',     -- Vyfakturována
                          'rejected'      -- Zamítnuta
                        ) NOT NULL DEFAULT 'draft',
  `template_id`         INT UNSIGNED NULL,             -- FK na quote_templates (pokud vznikla ze šablony)
  -- Klientské údaje (snapshot)
  `client_name`         VARCHAR(200) NULL,
  `client_street`       VARCHAR(200) NULL,
  `client_city`         VARCHAR(100) NULL,
  `client_zip`          VARCHAR(20)  NULL,
  `client_country`      VARCHAR(100) NULL,
  `client_ic`           VARCHAR(30)  NULL,
  `client_dic`          VARCHAR(30)  NULL,
  -- Dodací adresa
  `delivery_name`       VARCHAR(200) NULL,
  `delivery_street`     VARCHAR(200) NULL,
  `delivery_city`       VARCHAR(100) NULL,
  `delivery_zip`        VARCHAR(20)  NULL,
  `delivery_country`    VARCHAR(100) NULL,
  -- Obsah
  `description`         VARCHAR(255) NULL,             -- krátký popis/název nabídky
  `order_number`        VARCHAR(100) NULL,             -- číslo objednávky/smlouvy (volitelné)
  `note`                TEXT NULL,                     -- interní poznámka (nezobrazuje se na PDF)
  `text_before_items`   TEXT NULL,                     -- text před položkami (přenáší se na fakturu)
  `text_after_items`    TEXT NULL,                     -- text za položkami (přenáší se na fakturu)
  -- Platební údaje
  `payment_method`      VARCHAR(50) NULL,              -- způsob úhrady (bankovní převod, hotovost, karta…)
  `bank_account_id`     INT UNSIGNED NULL,             -- FK na bank_accounts
  `currency_code`       VARCHAR(3) NOT NULL DEFAULT 'CZK',
  `exchange_rate`       DECIMAL(15,6) NOT NULL DEFAULT 1.000000,
  -- Slevy
  `discount_percent`    DECIMAL(5,2) NULL,             -- sleva na celý doklad (%)
  -- Ceny (kalkulované, ukládané pro rychlost)
  `subtotal`            DECIMAL(15,2) NOT NULL DEFAULT 0.00,  -- součet bez DPH
  `vat_total`           DECIMAL(15,2) NOT NULL DEFAULT 0.00,  -- celkové DPH
  `total`               DECIMAL(15,2) NOT NULL DEFAULT 0.00,  -- celkem včetně DPH
  -- Datumy
  `issued_at`           DATE NOT NULL,                 -- datum vystavení
  `valid_until`         DATE NULL,                     -- platnost do
  -- Metadata
  `idoklad_id`          VARCHAR(50) NULL,              -- pro budoucí import z iDokladu
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          TIMESTAMP NULL,                -- soft delete

  INDEX `idx_supplier_status` (`supplier_id`, `status`),
  INDEX `idx_supplier_issued` (`supplier_id`, `issued_at`),
  INDEX `idx_quote_number`    (`quote_number`),
  INDEX `idx_client_id`       (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Tabulka `quote_items`

```sql
CREATE TABLE `quote_items` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quote_id`         INT UNSIGNED NOT NULL,
  `catalog_item_id`  INT UNSIGNED NULL,           -- FK na catalog_items (ceník), NULL = ruční položka
  `name`             VARCHAR(255) NOT NULL,
  `unit`             VARCHAR(30) NULL,            -- ks, hod, kg…
  `quantity`         DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit_price`       DECIMAL(15,4) NOT NULL,      -- jednotková cena (bez DPH nebo s DPH dle nastavení)
  `price_type`       ENUM('with_vat', 'without_vat') NOT NULL DEFAULT 'without_vat',
  `vat_rate`         DECIMAL(5,2) NOT NULL DEFAULT 21.00,
  `discount_percent` DECIMAL(5,2) NULL,           -- sleva na položku (%)
  `discount_note`    VARCHAR(255) NULL,           -- popis slevy (zobrazí se na PDF)
  `subtotal`         DECIMAL(15,2) NOT NULL,      -- cena bez DPH po slevě
  `vat_amount`       DECIMAL(15,2) NOT NULL,
  `total`            DECIMAL(15,2) NOT NULL,
  `sort_order`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  INDEX `idx_quote_id` (`quote_id`),
  FOREIGN KEY (`quote_id`) REFERENCES `quotes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Tabulka `quote_templates`

```sql
CREATE TABLE `quote_templates` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `supplier_id`         INT UNSIGNED NOT NULL,
  `name`                VARCHAR(100) NOT NULL,         -- název šablony (zobrazuje se v dropdown)
  `client_id`           INT UNSIGNED NULL,
  `payment_method`      VARCHAR(50) NULL,
  `bank_account_id`     INT UNSIGNED NULL,
  `currency_code`       VARCHAR(3) NOT NULL DEFAULT 'CZK',
  `text_before_items`   TEXT NULL,
  `text_after_items`    TEXT NULL,
  `note`                TEXT NULL,
  `valid_days`          SMALLINT UNSIGNED NULL,        -- přepíše globální platnost nabídky
  `fixed_variable_symbol` VARCHAR(30) NULL,
  `fixed_exchange_rate` DECIMAL(15,6) NULL,
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

```sql
CREATE TABLE `quote_template_items` (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `template_id`      INT UNSIGNED NOT NULL,
  `catalog_item_id`  INT UNSIGNED NULL,
  `name`             VARCHAR(255) NOT NULL,
  `unit`             VARCHAR(30) NULL,
  `quantity`         DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
  `unit_price`       DECIMAL(15,4) NOT NULL,
  `price_type`       ENUM('with_vat', 'without_vat') NOT NULL DEFAULT 'without_vat',
  `vat_rate`         DECIMAL(5,2) NOT NULL DEFAULT 21.00,
  `discount_percent` DECIMAL(5,2) NULL,
  `sort_order`       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (`template_id`) REFERENCES `quote_templates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Tabulka `quote_attachments`

```sql
CREATE TABLE `quote_attachments` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quote_id`   INT UNSIGNED NOT NULL,
  `filename`   VARCHAR(255) NOT NULL,     -- původní název souboru
  `path`       VARCHAR(500) NOT NULL,     -- cesta v storage
  `mime_type`  VARCHAR(100) NULL,
  `size`       INT UNSIGNED NULL,         -- velikost v bytech (max 2 MB)
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`quote_id`) REFERENCES `quotes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- max 5 příloh na jednu nabídku, max 2 MB každá (validace na aplikační vrstvě)
```

---

## Vazba nabídky na vygenerované faktury

```sql
-- Přidej do tabulky invoices (nebo invoice_quotes_links):
ALTER TABLE `invoices`
  ADD COLUMN `source_quote_id` INT UNSIGNED NULL COMMENT 'Quote ze které vznikla tato faktura',
  ADD INDEX `idx_source_quote` (`source_quote_id`);
```

---

## Copilot pokyny

- Vytvoř Laravel migration soubory pro každou tabulku výše.
- Respektuj existující naming convention v projektu (snake_case tabulky).
- Použij soft delete (`deleted_at`) jen u `quotes`, ne u položek.
- Přidej `down()` metody pro rollback.
- FK constraints přidej jen pokud existující schéma jiných tabulek je má.
