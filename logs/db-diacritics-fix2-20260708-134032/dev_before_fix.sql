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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `supplier` WRITE;
/*!40000 ALTER TABLE `supplier` DISABLE KEYS */;
INSERT INTO `supplier` VALUES
(1,'DuÔö╝├şan KahÔöť├şnek','DuÔö╝├şan KahÔöť├şnek','Pod BÔöť┼člou horou 942/8','KopÔö╝├ľivnice','74221',1,'87290952',NULL,0,0,'infio@dusankahanek.cz','+420 732 211 675','www.dusankahanek.cz',NULL,1,'#3B2D83',0,NULL,1,1,14,'days',1500.00,1,3,0,0,0,0,NULL,1,1,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-24 20:31:14','2026-06-01 07:06:19',NULL,NULL,NULL,NULL,NULL,'1{CCC}{YY}',NULL,NULL,'month',NULL,NULL,NULL,NULL,NULL,'claude-haiku-4-5',0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'none',0,NULL,NULL),
(2,'Datixo.com, s.r.o.','Datixo.com, s.r.o.','KorunnÔöť┼č 2569/108','Praha','101 00',1,'21414319','CZ21414319',0,0,'dusan@datixo.com','+420 732 211 675','www.datixo.cz',NULL,1,'#8b5cf6',0,'SpisovÔöť├ş znaÔöÇ┼╣ka C 400973/MSPH MÔöÇ┼ĄstskÔöť┼╗ soud v Praze',3,1,14,'days',2690.00,1,3,0,0,0,0,NULL,1,0,'storage/supplier-logos/sup-2.png',NULL,NULL,NULL,NULL,NULL,'2026-05-27 16:57:02','2026-05-27 17:35:29',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'month',NULL,NULL,NULL,NULL,NULL,'claude-haiku-4-5',0,'po',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'none',0,NULL,NULL);
/*!40000 ALTER TABLE `supplier` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clients`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `clients` WRITE;
/*!40000 ALTER TABLE `clients` DISABLE KEYS */;
INSERT INTO `clients` VALUES
(1,1,'AIR&HEAT s.r.o.','Roman','BartoÔö╝├ş','09407499','CZ09407499',NULL,'ZÔöť├şhumennÔöť┼č 493/8','KopÔö╝├ľivnice','74221',1,'r.bartos@airunit.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: https://airunit.cz/',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(2,1,'Akusolar s.r.o.','Karel','Ôö╝├íebesta','06273271','CZ06273271',NULL,'8. pÔöÇ┼ĄÔö╝├şÔöť┼čho pluku 2380','FrÔöť┼╗dek-MÔöť┼čstek','73801',1,'sebesta@akusolar.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.akusolar.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(3,1,'Alfastream s.r.o.','Radek','Gertner','03039854','CZ03039854',NULL,'LipovÔöť├ş 317','MutÔöÇ┼Ąnice','69611',1,'gertner.r@seznam.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(4,1,'arachis s.r.o.','Lucie','TobolkovÔöť├ş','05249881','CZ05249881',NULL,'Ôö╝├ítÔöÇ┼ĄrboholskÔöť├ş 1434/102a','Praha','10200',1,'lucie@bigboy.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.bigboy.cz | titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(5,1,'B - HoÔö╝├ľÔöť├şk s.r.o.','AlÔö╝┼╝bÔöÇ┼Ąta','BendovÔöť├ş','02489562','CZ02489562',NULL,'Za BrÔöť├şnou 130','Neslovice','66491',1,'alz.bendova@seznam.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(6,1,'BTL zdravotnickÔöť├ş technika, a.s.','Josef','Machanec','26884143','CZ26884143',NULL,'MakovskÔöť─Öho nÔöť├şmÔöÇ┼ĄstÔöť┼č 3147/2','Brno','61600',1,'machanec@btl.cz','+420 777 920 286','cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.btl.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(7,1,'BYDO development s.r.o.','LukÔöť├şÔö╝├ş','Vyvial','26872781','CZ26872781',NULL,'Pernerova 702/39','Praha','18600',1,'vyvial@by-do.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.by-do.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(8,1,'Cofado coffee, s.r.o.','JiÔö╝├ľÔöť┼č','Sillik','22054855',NULL,NULL,'KorunnÔöť┼č 2569/108','Praha','10100',1,'jiri@cofado.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.cofado.com | titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(9,1,'Datixo.com, s.r.o.','JiÔö╝├ľÔöť┼č','Sillik','21414319',NULL,NULL,'KorunnÔöť┼č 2569/108','Praha','10100',1,'jiri@datixo.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(10,1,'Domat Control System s.r.o.','Aida','Hasymova','27189465','CZ27189465',NULL,'U Panasonicu 376','Pardubice','53006',1,'pavel.rataj@domat.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.domat.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(11,1,'DuÔö╝├şan KahÔöť├şnek',NULL,NULL,'87290952','08259356',NULL,'Pod BÔöť┼člou horou 942/8','KopÔö╝├ľivnice','74221',1,'import+dusan.kahanek@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(12,1,'EDU CREW s.r.o.',NULL,NULL,'06188001','CZ06188001',NULL,'U BechyÔö╝┼éskÔöť─Ö drÔöť├şhy 2932','TÔöť├şbor','39002',1,'import+edu.crew.s.r.o@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(13,1,'EFG CZ spol. s r.o.','LubomÔöť┼čr','Ôö╝├ímÔöť┼čd','25649876','CZ25649876',NULL,'ZelenÔöť┼╗ pruh 1560/99','Praha','14000',1,'uctarna@efg.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.efg.cz | dalÔö╝├şÔöť┼č pÔö╝├ľÔöť┼čjemci: smid@efg.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(14,1,'Envato Pty Ltd',NULL,NULL,NULL,'EU372009975',NULL,'├ö├ç├Â','├ö├ç├Â','00000',1,'import+envato.pty.ltd@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(15,1,'GenerÔöť├şlnÔöť┼č finanÔöÇ┼╣nÔöť┼č Ôö╝├ľeditelstvÔöť┼č',NULL,NULL,'72080043','CZ72080043',NULL,'LazarskÔöť├ş 15/7','Praha','11000',1,'import+generalni.financni.reditelstvi@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,0,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(16,1,'GIGA FACTORY s.r.o.','TomÔöť├şÔö╝├ş','Jorpalidis','06576737','CZ06576737',NULL,'RybnÔöť├ş 716/24','Praha','11000',1,'jorpalidis@gmail.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(17,1,'Ing. Babeta LinhartovÔöť├ş','Babeta','LinhartovÔöť├ş','87477769',NULL,NULL,'Jana ÔöÇ├«apka 3089','FrÔöť┼╗dek-MÔöť┼čstek','73801',1,'info@babetalinhartova.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(18,1,'Internet Info, s.r.o.',NULL,NULL,'25648071','CZ25648071',NULL,'Milady HorÔöť├şkovÔöť─Ö 116/109','Praha','16000',1,'import+internet.info.s.r.o@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,0,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(19,1,'JetBrains s.r.o.',NULL,NULL,'26502275','CZ26502275',NULL,'Na hÔö╝├ľebenech II 1718/8','Praha','14000',1,'import+jetbrains.s.r.o@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(20,1,'JiÔö╝├ľÔöť┼č BryndaÔöÇ┼╣','Michal','Nehera','88576809','CZ9103255678',NULL,'PalackÔöť─Öho 515/12','KopÔö╝├ľivnice','74221',1,'jiri.bryndac@gmail.com','JiÔö╝├ľÔöť┼č','cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: wwwlogima.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(21,1,'JiÔö╝├ľÔöť┼č Noga','JiÔö╝├ľÔöť┼č','Noga','61964981','CZ7607254930',NULL,'PolnÔöť┼č 537','TÔö╝├ľinec','73961',1,'jirkanoga@gmail.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(22,1,'KabelovÔöť├ş televize KopÔö╝├ľivnice, s.r.o.',NULL,NULL,'60318988','CZ60318988',NULL,'ZÔöť├şhumennÔöť┼č 1152/4a','KopÔö╝├ľivnice','74221',1,'import+kabelova.televize.koprivnice.s.r.o@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(23,1,'KLAR Fashion, s. r. o.','LuboÔö╝├ş','GrufÔöť┼čk','24211575','CZ699006714',NULL,'UniverzitnÔöť┼č 684/6','Praha','10800',1,'pepejeans@centrum.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.jeans-store.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(24,1,'KOLLERT ELEKTRO s.r.o.','Petr','Kollert','25464787','CZ25464787',NULL,'SvÔöť├şrovskÔöť├ş 108','Liberec','46010',1,'priprava@kollert.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(25,1,'KOMPEK, kombinÔöť├şt pekaÔö╝├ľskÔöť─Ö a cukrÔöť├şÔö╝├ľskÔöť─Ö vÔöť┼╗roby, spol. s r.o.','Monika','DvoÔö╝├ľÔöť├şkovÔöť├ş','49900501','CZ49900501',NULL,'J. Hory 671','Kladno','27201',1,'monika.dvorakova@kompek.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.kompek.cz | titul: Bc.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(26,1,'Laporte s.r.o.','Jan','Hrabiec','11833564','CZ11833564',NULL,'Ke Statku 1018','StudÔöť─Önka','74213',1,'hrabiec@laporte.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.laporte.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(27,1,'Lenka HoÔö╝├ľÔöť├şkovÔöť├ş',NULL,NULL,'87340356',NULL,NULL,'OkrouhlÔöť├ş 372/18','Brno','62500',1,'lenka108@gmail.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(28,1,'LOGIMA Automatizace s.r.o.','Michal','Nehere','21137552','CZ21137552',NULL,'Kpt. NÔöť├şlepky 1150/2b','KopÔö╝├ľivnice','74221',1,'nehera@logima.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.logima.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(29,1,'Lucie StrakoÔö╝├şovÔöť├ş',NULL,NULL,'87289245',NULL,NULL,'MniÔö╝├şÔöť┼č 76','KopÔö╝├ľivnice','74221',1,'info@strakafe.cz','+420 775 189 032,','cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.strakafe.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(30,1,'LukÔöť├şÔö╝├ş Tejral',NULL,NULL,'05900557','CZ9301274356',NULL,'OstrÔöť├ş 2826/22','Brno','61600',1,'admin@megacomics.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.megacomics.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(31,1,'Martin Eichenbaum','Martin','Eichenbaum','68197781','CZ8004285542',NULL,'MorÔöť├şvka 291','MorÔöť├şvka','73904',1,'barman-ostrava@seznam.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,14,NULL,0.00,'web: www.alkohol-online.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(32,1,'MICROSOFT s.r.o.',NULL,NULL,'47123737','CZ47123737',NULL,'VyskoÔöÇ┼╣ilova 1561/4a','Praha','14000',1,'import+microsoft.s.r.o@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(33,1,'OHLA Ôö╝┼╗S, a.s.','Sandra','MatulovÔöť├ş','46342796','CZ46342796',NULL,'TuÔö╝├ľanka 1554/115b','Brno','62700',1,'matulovas@ohla-zs.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(34,1,'Patron finance, s.r.o.','JiÔö╝├ľÔöť┼č','Sillik','03909824','CZ03909824',NULL,'MakovskÔöť─Öho 1177/1','Praha','16300',1,'info@financnipatron.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,14,NULL,0.00,'web: www.financnipatron.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(35,1,'Patron financial services s.r.o.',NULL,NULL,'13974068',NULL,NULL,'KorunnÔöť┼č 2569/108','Praha','10100',1,'jiri@financnipatron.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(36,1,'PDI a.s.','Michal','BoÔö╝├şka','25758292','CZ25758292',NULL,'NÔöť├şrodnÔöť┼č 364/39','Praha','11000',1,'boska@pdi.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.pdi.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(37,1,'Petr Ôö╝├ípaÔöÇ┼╣ek','Petr','Ôö╝├ípaÔöÇ┼╣ek','03851877',NULL,NULL,'Cidlina 31','Cidlina','67544',1,'obchod@vcelarstvispacek.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: https://www.vcelarstvispacek.cz/',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(38,1,'PROFILY, s. r. o.','Monika','PitrunovÔöť├ş','64618005','CZ64618005',NULL,'ÔöÇ├«ermenskÔöť├ş 279','VÔöť┼čtkov','74901',1,'m.pitrunova@profily.cz','+420 556 771 502','cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: https://www.profily.cz/',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(39,1,'PUDIS a.s.','Petr','KlouÔöÇ┼╣ek','45272891','CZ45272891',NULL,'PodbabskÔöť├ş 1014/20','Praha','16000',1,'petr.kloucek@pudis.cz','+420 267 004 111','cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.pudis.cz | dalÔö╝├şÔöť┼č pÔö╝├ľÔöť┼čjemci: libor.sila@pudis.cz;petr.vorlicek@pudis.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(40,1,'RemÔöť─Ödio Digital s.r.o.','Jan','Warneke','10704388','CZ10704388',NULL,'SokolovskÔöť├ş 428/130','Praha','18600',1,'faktury@remedio.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.remedio.cz | dalÔö╝├şÔöť┼č pÔö╝├ľÔöť┼čjemci: jan.warneke@remedio.cz',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(41,1,'ROMANTICK s.r.o.','Monika','ChvojkovÔöť├ş','63278928','CZ63278928',NULL,'RudolfovskÔöť├ş tÔö╝├ľ. 64/34','ÔöÇ├«eskÔöť─Ö BudÔöÇ┼Ąjovice','37001',1,'monika@romantick.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.romantick.cz | titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(42,1,'RSBP spol. s r.o.','Petr','Grygar','45196508','CZ45196508',NULL,'PikartskÔöť├ş 1337/7','Ostrava','71600',1,'grygar@rsbp.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.rsbp.cz e | titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(43,1,'SoÔö╝┼éa StÔöť┼čskalovÔöť├ş','SoÔö╝┼éa','StÔöť┼čskalovÔöť├ş','48442542','CZ6254151563',NULL,'PstruÔö╝┼╝Ôöť┼č 257','PstruÔö╝┼╝Ôöť┼č','73911',1,'sonabusiness1@gmail.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(44,1,'STD DONIVO s.r.o.','Martina','MaÔöÇ┼╣koviÔöÇ┼╣ovÔöť├ş','05976073','CZ05976073',NULL,'KrakovskÔöť├ş 583/9','Praha','11000',1,'mackovicova@std.sk',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.std.sk | titul: Mgr.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(45,1,'Synapse investments, s.r.o.',NULL,NULL,'07641842',NULL,NULL,'KorunnÔöť┼č 2569/108','Praha','10100',1,'jiri@financnipatron.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(46,1,'Taste Academy, s.r.o.',NULL,NULL,'08259356','CZ08259356',NULL,'HybernskÔöť├ş 1271/32','Praha','11000',1,'import+taste.academy.s.r.o@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(47,1,'T-Mobile Czech Republic a.s.',NULL,NULL,'64949681','CZ64949681',NULL,'TomÔöť┼čÔöÇ┼╣kova 2144/1','Praha','14800',1,'import+t.mobile.czech.republic.a.s@import.local',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(48,1,'TOOTS Limited s.r.o.','JiÔö╝├ľÔöť┼č','Vontroba','09765905','CZ09765905',NULL,'Ôö╝├ítramberskÔöť├ş 515/45','Ostrava','70300',1,'info@toots.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: www.toots.cz | titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(49,1,'VÔöť├şclav VaraÔöÇ─ća','VÔöť├şclav','VaraÔöÇ─ća','86985302','CZ7604265867',NULL,'SokolovskÔöť├ş 404/2','KopÔö╝├ľivnice','74221',1,'vac.varada@gmail.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(50,1,'Veetec3D s.r.o.','TomÔöť├şÔö╝├ş','HusÔöť├şk','07762992','CZ07762992',NULL,'VrÔö╝├şovickÔöť├ş 796/37','Praha','10100',1,'tomas.husak@veetec3d.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: veetec3d.com',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(51,1,'WENFIN CONSULTING s.r.o.','Josef','Wenzl','24790028',NULL,NULL,'TopolovÔöť├ş 381','Statenice','25262',1,'jwenzl@seznam.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: http://www.wenfin.cz/',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(52,1,'ZEPHYR FrantiÔö╝├şkovy LÔöť├şznÔöÇ┼Ą, s.r.o.','Martin','Vacek','00871427','CZ00871427',NULL,'Klest 19','Cheb','35002',1,'mavacek@izephyr.cz',NULL,'cs',1,NULL,0,1,0,0,NULL,1,NULL,NULL,0.00,'web: https://www.farmapoustka.cz/ | titul: Ing.',NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-12 18:47:48','2026-05-12 18:47:48',NULL,1),
(66,1,'DALADU s.r.o.',NULL,NULL,'21291284','CZ21291284',NULL,'ZÔöť├şmostnÔöť┼č 1155/27','Ostrava','710 00',1,'kahanek.dusan@gmail.com',NULL,'cs',1,NULL,0,1,0,0,NULL,1,7,NULL,0.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-26 15:43:51','2026-05-26 15:43:51',NULL,1),
(67,2,'Furniqo s.r.o.',NULL,NULL,'24503991',NULL,NULL,'Jaurisova 515/4','Praha','140 00',1,'hurdalek@3dvo.cz','+420 774 129 370','cs',3,NULL,0,1,0,0,NULL,1,14,NULL,2690.00,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-05-27 17:04:50','2026-05-27 17:04:50',NULL,1);
/*!40000 ALTER TABLE `clients` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
INSERT INTO `currencies` VALUES
(1,1,'CZK','CZK - vychozi','Kc','Ceska koruna','Czech Koruna',2,1,1,'6147140339','0800','CSAS','CZ62 0800 0000 0061 4714 0339','GIBACZPX'),
(2,1,'EUR','EUR - vychozi','EUR','Euro','Euro',2,1,1,NULL,NULL,NULL,NULL,NULL),
(3,2,'CZK','CZK ├ö├ç├Â vÔöť┼╗chozÔöť┼č','KÔöÇ┼╣','ÔöÇ├«eskÔöť├ş koruna','Czech Koruna',2,1,1,NULL,NULL,NULL,NULL,NULL),
(4,2,'EUR','EUR ├ö├ç├Â vÔöť┼╗chozÔöť┼č','├ö├ę─î','Euro','Euro',2,1,1,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
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

-- Dump completed on 2026-07-08 13:40:33
