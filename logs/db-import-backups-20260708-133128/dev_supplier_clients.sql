/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19-11.8.8-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: myinvoice
-- ------------------------------------------------------
-- Server version	11.8.8-MariaDB-ubu2404

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

--
-- Table structure for table `supplier`
--

DROP TABLE IF EXISTS `supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(190) NOT NULL,
  `display_name` varchar(190) DEFAULT NULL,
  `street` varchar(190) NOT NULL,
  `city` varchar(120) NOT NULL,
  `zip` varchar(10) NOT NULL,
  `country_id` smallint(5) unsigned NOT NULL,
  `ic` varchar(20) DEFAULT NULL,
  `dic` varchar(20) DEFAULT NULL,
  `is_vat_payer` tinyint(1) NOT NULL DEFAULT 1,
  `is_identified` tinyint(1) NOT NULL DEFAULT 0,
  `email` varchar(190) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `web` varchar(190) DEFAULT NULL,
  `tagline` varchar(190) DEFAULT NULL,
  `email_branding_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `email_accent_color` varchar(7) NOT NULL DEFAULT '#3B2D83',
  `pdf_logo_show_name` tinyint(1) NOT NULL DEFAULT 0,
  `commercial_register` varchar(255) DEFAULT NULL,
  `default_currency_id` int(10) unsigned NOT NULL,
  `default_vat_rate_id` int(10) unsigned NOT NULL,
  `default_payment_due_days` int(10) unsigned NOT NULL DEFAULT 7,
  `default_payment_due_unit` enum('days','month') NOT NULL DEFAULT 'days' COMMENT 'Jednotka v├Żchoz├ş splatnosti. days = default_payment_due_days dn├ş, month = tolik kalend├í┼Ön├şch m─Ťs├şc┼» (overflow Ôćĺ posledn├ş den m─Ťs├şce).',
  `default_hourly_rate` decimal(10,2) NOT NULL DEFAULT 1500.00,
  `auto_send_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `reminder_days_after_due` int(11) NOT NULL DEFAULT 3,
  `payment_thanks_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `payment_thanks_auto_send` tinyint(1) NOT NULL DEFAULT 0,
  `payment_thanks_default_checked` tinyint(1) NOT NULL DEFAULT 0,
  `payment_thanks_attach_paid_pdf` tinyint(1) NOT NULL DEFAULT 0,
  `self_copy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`self_copy`)),
  `embed_isdoc` tinyint(1) NOT NULL DEFAULT 1,
  `auto_generate_recurring` tinyint(1) NOT NULL DEFAULT 1,
  `logo_path` varchar(255) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `pohoda_account_code` varchar(20) DEFAULT NULL COMMENT 'K├│d ├║─Źtu v Pohoda ─Ź├şseln├şku (nap┼Ö. KB, 1010)',
  `pohoda_centre_code` varchar(20) DEFAULT NULL COMMENT 'St┼Öedisko (k├│d v Pohod─Ť)',
  `pohoda_activity_code` varchar(20) DEFAULT NULL COMMENT '─îinnost (k├│d v Pohod─Ť)',
  `pohoda_contract_code` varchar(20) DEFAULT NULL COMMENT 'Zak├ízka (k├│d v Pohod─Ť)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `idoklad_client_id` varchar(128) DEFAULT NULL COMMENT 'iDoklad API v3 client_id (plain ÔÇö public identifier)',
  `idoklad_client_secret_enc` varbinary(512) DEFAULT NULL COMMENT 'iDoklad API v3 client_secret ┼íifrovan├Ż AES-256-GCM p┼Öes app.pepper',
  `idoklad_access_token` text DEFAULT NULL COMMENT 'Cache bearer tokenu (kratk├í TTL ~1h); refresh na expires_at',
  `idoklad_token_expires_at` timestamp NULL DEFAULT NULL COMMENT 'Expirace cached access_token',
  `idoklad_last_imported_at` timestamp NULL DEFAULT NULL COMMENT 'Posledn├ş ├║sp─Ť┼ín├Ż import ÔÇö bookmark pro incremental sync',
  `invoice_number_format` varchar(60) DEFAULT NULL COMMENT 'Per-supplier template pro varsymbol (typ invoice). NULL = fallback na cfg.varsymbol.templates.invoice.',
  `proforma_number_format` varchar(60) DEFAULT NULL COMMENT 'Per-supplier template pro varsymbol (typ proforma). NULL = fallback na cfg.',
  `credit_note_number_format` varchar(60) DEFAULT NULL COMMENT 'Per-supplier template pro varsymbol (typ credit_note). NULL = fallback na cfg.',
  `invoice_number_period` enum('year','month','none') NOT NULL DEFAULT 'month' COMMENT 'Reset countru: year = 1.1., month = 1. dne v m─Ťs├şci, none = nikdy.',
  `fakturoid_slug` varchar(64) DEFAULT NULL COMMENT 'Fakturoid account slug (nap┼Ö. "moje-firma")',
  `fakturoid_email` varchar(255) DEFAULT NULL COMMENT 'Fakturoid account email pro BasicAuth username',
  `fakturoid_api_key_enc` varbinary(512) DEFAULT NULL COMMENT 'Fakturoid personal API token (BasicAuth password) ┼íifrovan├Ż',
  `fakturoid_last_imported_at` timestamp NULL DEFAULT NULL COMMENT 'Bookmark ÔÇö posledn├ş ├║sp─Ť┼ín├Ż import (pro incremental sync filter)',
  `anthropic_api_key_enc` varbinary(512) DEFAULT NULL COMMENT 'Anthropic API key (sk-ant-...) ┼íifrovan├Ż AES-256-GCM',
  `anthropic_default_model` varchar(64) DEFAULT 'claude-haiku-4-5' COMMENT 'Default Claude model pro AI extrakci',
  `anthropic_extractions_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Po─Ź├ştadlo successful AI extrakc├ş (pro telemetry/billing transparency)',
  `taxpayer_type` enum('fo','po') DEFAULT NULL COMMENT 'fo = fyzick├í osoba (OSV─î), po = pr├ívnick├í osoba (s.r.o., a.s.)',
  `vat_period` enum('monthly','quarterly') DEFAULT NULL COMMENT 'Periodicita DPH p┼Öizn├ín├ş. NULL = nepl├ítce.',
  `financial_office_code` varchar(8) DEFAULT NULL COMMENT 'K├│d finan─Źn├şho ├║┼Öadu (nap┼Ö. 451 = Praha 1)',
  `workplace_code` varchar(8) DEFAULT NULL COMMENT 'K├│d ├║zemn├şho pracovi┼ít─Ť (├ÜzP)',
  `cz_nace_code` varchar(8) DEFAULT NULL COMMENT 'CZ-NACE klasifikace ─Źinnosti',
  `data_box_type` varchar(8) DEFAULT NULL COMMENT 'Typ datov├ę schr├ínky (e.g. OVM, PO, FO)',
  `data_box_id` varchar(16) DEFAULT NULL COMMENT 'ID datov├ę schr├ínky pro doru─Źov├ín├ş',
  `sest_jmeno` varchar(100) DEFAULT NULL,
  `sest_prijmeni` varchar(100) DEFAULT NULL,
  `sest_telefon` varchar(40) DEFAULT NULL,
  `sest_email` varchar(120) DEFAULT NULL,
  `sest_funkce` varchar(80) DEFAULT NULL,
  `street_number_pop` varchar(20) DEFAULT NULL COMMENT '─î├şslo popisn├ę (c_pop) ÔÇö vypl┼łuje se v DPH/KH XML samostatn─Ť',
  `street_number_orient` varchar(20) DEFAULT NULL COMMENT '─î├şslo orienta─Źn├ş (c_orient) ÔÇö vypl┼łuje se v DPH/KH XML samostatn─Ť',
  `opr_jmeno` varchar(60) DEFAULT NULL COMMENT 'Jm├ęno osoby opr├ívn─Ťn├ę k podpisu (typicky jednatel u s.r.o.)',
  `opr_prijmeni` varchar(60) DEFAULT NULL COMMENT 'P┼Ö├şjmen├ş osoby opr├ívn─Ťn├ę k podpisu',
  `opr_postaveni` varchar(60) DEFAULT NULL COMMENT 'Postaven├ş opr├ívn─Ťn├ę osoby (nap┼Ö. "jednatel", "majitel")',
  `fakturoid_client_id` varchar(190) DEFAULT NULL COMMENT 'Fakturoid OAuth2 client_id (plain ÔÇö public identifier)',
  `fakturoid_client_secret_enc` text DEFAULT NULL COMMENT 'Fakturoid OAuth2 client_secret ┼íifrovan├Ż AES-256-GCM',
  `fakturoid_access_token_enc` text DEFAULT NULL COMMENT 'Cache OAuth2 bearer tokenu (TTL ~2h) ┼íifrovan├Ż AES-256-GCM',
  `fakturoid_access_token_expires_at` timestamp NULL DEFAULT NULL COMMENT 'Expirace cached OAuth2 access_token',
  `flat_tax_band` enum('none','band1','band2','band3') NOT NULL DEFAULT 'none' COMMENT 'Pau┼í├íln├ş da┼ł p├ísmo. none = klasick├Ż re┼żim, band1/2/3 = pau┼í├íl s limitem p┼Ö├şjm┼» 1M/1.5M/2M K─Ź/rok.',
  `default_prices_include_vat` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'V├Żchoz├ş re┼żim cen u nov├Żch faktur (0 = bez DPH, 1 = s DPH)',
  `purchase_invoice_number_format` varchar(60) DEFAULT NULL COMMENT 'Per-supplier ┼íablona intern├şho ─Ź├şsla p┼Öijat├ę faktury. NULL = vestav─Ťn├Ż default {PP}{YY}{MM}{CCC}.',
  `abo_client_number` varchar(10) DEFAULT NULL COMMENT '─î├şslo klienta do hlavi─Źky ABO/KPC (override; jinak odvozeno z ├║─Źtu pl├ítce)',
  PRIMARY KEY (`id`),
  KEY `fk_sup_country` (`country_id`),
  KEY `fk_sup_vat` (`default_vat_rate_id`),
  KEY `fk_sup_currency` (`default_currency_id`),
  CONSTRAINT `fk_sup_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`),
  CONSTRAINT `fk_sup_currency` FOREIGN KEY (`default_currency_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_sup_vat` FOREIGN KEY (`default_vat_rate_id`) REFERENCES `vat_rates` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `supplier` WRITE;
/*!40000 ALTER TABLE `supplier` DISABLE KEYS */;
INSERT INTO `supplier` VALUES
(1,'Dusan Kahanek','Dusan Kahanek','Nezadano 1','Praha','11000',1,NULL,NULL,1,0,'info@dusankahanek.cz',NULL,NULL,NULL,0,'#3B2D83',0,NULL,1,1,14,'days',1500.00,1,3,0,0,0,0,NULL,1,1,NULL,NULL,NULL,NULL,NULL,NULL,'2026-07-08 10:42:16','2026-07-08 10:42:16',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'month',NULL,NULL,NULL,NULL,NULL,'claude-haiku-4-5',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'none',0,NULL,NULL);
/*!40000 ALTER TABLE `supplier` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) unsigned NOT NULL,
  `code` char(3) NOT NULL,
  `label` varchar(60) NOT NULL,
  `symbol` varchar(8) NOT NULL,
  `name_cs` varchar(60) NOT NULL,
  `name_en` varchar(60) NOT NULL,
  `decimals` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `account_number` varchar(30) DEFAULT NULL,
  `bank_code` char(4) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `iban` varchar(34) DEFAULT NULL,
  `bic` varchar(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_currencies_code` (`code`),
  KEY `idx_currencies_active` (`is_active`),
  KEY `idx_currencies_supplier` (`supplier_id`),
  CONSTRAINT `fk_cur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
INSERT INTO `currencies` VALUES
(1,1,'CZK','CZK ÔÇö v├Żchoz├ş','K─Ź','─îesk├í koruna','Czech Koruna',2,1,1,NULL,NULL,NULL,NULL,NULL),
(2,1,'EUR','EUR ÔÇö v├Żchoz├ş','ÔéČ','Euro','Euro',2,1,1,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` int(10) unsigned NOT NULL,
  `company_name` varchar(190) NOT NULL,
  `first_name` varchar(60) DEFAULT NULL,
  `last_name` varchar(60) DEFAULT NULL,
  `ic` varchar(20) DEFAULT NULL,
  `dic` varchar(20) DEFAULT NULL,
  `tax_number` varchar(30) DEFAULT NULL,
  `street` varchar(190) NOT NULL,
  `city` varchar(120) NOT NULL,
  `zip` varchar(10) NOT NULL,
  `country_id` smallint(5) unsigned NOT NULL,
  `main_email` varchar(190) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `language` enum('cs','en') NOT NULL DEFAULT 'cs',
  `currency_default_id` int(10) unsigned NOT NULL,
  `vat_rate_default_id` int(10) unsigned DEFAULT NULL,
  `reverse_charge` tinyint(1) NOT NULL DEFAULT 0,
  `is_customer` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'True pokud klientovi vystavujeme faktury (default pro existuj├şc├ş z├íznamy)',
  `is_vendor` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'True pokud od n─Ťj p┼Öij├şm├íme faktury (dodavatel)',
  `is_fuel_station` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Dodavatel je benz├şnka (pro automatick├ę rozpozn├ív├ín├ş tankov├ín├ş)',
  `idoklad_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Contact.Id z iDoklad API v3 (dedup pro re-import)',
  `auto_send_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `payment_due_default` int(10) unsigned DEFAULT NULL,
  `payment_due_unit` enum('days','month') DEFAULT NULL COMMENT 'Per-client override jednotky splatnosti. NULL = d─Ťdit ze supplier.default_payment_due_unit.',
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `note` text DEFAULT NULL,
  `default_expense_category_id` int(10) unsigned DEFAULT NULL COMMENT 'V├Żchoz├ş kategorie n├íkladu pro p┼Öijat├ę faktury tohoto dodavatele. NULL = bez defaultu.',
  `default_revenue_category_id` int(10) unsigned DEFAULT NULL COMMENT 'V├Żchoz├ş kategorie tr┼żby pro vydan├ę faktury tohoto z├íkazn├şka. NULL = bez defaultu.',
  `invoice_number_format` varchar(60) DEFAULT NULL COMMENT 'Per-client template pro vydanou fakturu. NULL = d─Ťdit ze supplieru.',
  `proforma_number_format` varchar(60) DEFAULT NULL COMMENT 'Per-client template pro proformu. NULL = d─Ťdit ze supplieru.',
  `credit_note_number_format` varchar(60) DEFAULT NULL COMMENT 'Per-client template pro dobropis. NULL = d─Ťdit ze supplieru.',
  `invoice_number_period` enum('year','month','none') DEFAULT NULL COMMENT 'Per-client obdob├ş counteru. NULL = d─Ťdit ze supplieru.',
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fakturoid_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Subject.id z Fakturoid API v3',
  `is_vat_payer` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Protistrana je pl├ítce DPH (z ARES/VIES). U dodavatele ┼Ö├şd├ş n├írok na odpo─Źet.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_idoklad` (`supplier_id`,`idoklad_id`),
  UNIQUE KEY `uq_clients_fakturoid` (`supplier_id`,`fakturoid_id`),
  KEY `idx_clients_company` (`company_name`),
  KEY `idx_clients_ic` (`ic`),
  KEY `idx_clients_archived` (`archived_at`),
  KEY `idx_clients_supplier` (`supplier_id`),
  KEY `fk_cli_country` (`country_id`),
  KEY `fk_cli_vat` (`vat_rate_default_id`),
  KEY `fk_cli_currency` (`currency_default_id`),
  KEY `idx_clients_customer` (`supplier_id`,`is_customer`),
  KEY `idx_clients_vendor` (`supplier_id`,`is_vendor`),
  KEY `idx_clients_fuel_station` (`supplier_id`,`is_fuel_station`),
  CONSTRAINT `fk_cli_country` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`),
  CONSTRAINT `fk_cli_currency` FOREIGN KEY (`currency_default_id`) REFERENCES `currencies` (`id`),
  CONSTRAINT `fk_cli_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`),
  CONSTRAINT `fk_cli_vat` FOREIGN KEY (`vat_rate_default_id`) REFERENCES `vat_rates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clients`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `clients` WRITE;
/*!40000 ALTER TABLE `clients` DISABLE KEYS */;
/*!40000 ALTER TABLE `clients` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

-- Dump completed on 2026-07-08 13:31:28
