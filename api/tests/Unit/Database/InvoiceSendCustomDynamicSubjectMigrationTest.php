<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceSendCustomDynamicSubjectMigrationTest extends TestCase
{
    public function testMakesDocumentTypeDynamicWithoutRemovingCustomPrefix(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE email_templates (
                code TEXT NOT NULL,
                locale TEXT NOT NULL,
                subject TEXT NOT NULL
            )'
        );
        $pdo->exec(
            "INSERT INTO email_templates (code, locale, subject) VALUES
                ('invoice_send', 'cs', '{{ supplier.display_name }} | Faktura {{ invoice.varsymbol }}'),
                ('invoice_send', 'en', '{{ supplier.display_name }} | Invoice {{ invoice.varsymbol }}'),
                ('invoice_send', 'cs', '{{ supplier.display_name }} | Vlastní text'),
                ('invoice_reminder', 'cs', 'Faktura {{ invoice.varsymbol }}')"
        );

        $migration = (string) file_get_contents(
            dirname(__DIR__, 4) . '/db/migrations/0154_invoice_send_custom_dynamic_subject.sql'
        );

        $pdo->exec($migration);
        $pdo->exec($migration);

        $rows = $pdo->query(
            'SELECT code, locale, subject FROM email_templates ORDER BY rowid'
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame([
            ['code' => 'invoice_send', 'locale' => 'cs', 'subject' => '{{ supplier.display_name }} | {{ document_type_label }} {{ invoice.varsymbol }}'],
            ['code' => 'invoice_send', 'locale' => 'en', 'subject' => '{{ supplier.display_name }} | {{ document_type_label }} {{ invoice.varsymbol }}'],
            ['code' => 'invoice_send', 'locale' => 'cs', 'subject' => '{{ supplier.display_name }} | Vlastní text'],
            ['code' => 'invoice_reminder', 'locale' => 'cs', 'subject' => 'Faktura {{ invoice.varsymbol }}'],
        ], $rows);
    }
}
