<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;

final class InvoiceSendDynamicSubjectMigrationTest extends TestCase
{
    public function testReplacesOnlyLegacyInvoiceSendSubjectsAndIsIdempotent(): void
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
                ('invoice_send', 'cs', 'Faktura {{ invoice.varsymbol }}'),
                ('invoice_send', 'en', 'Invoice {{ invoice.varsymbol }}'),
                ('invoice_send', 'cs', 'Vlastní předmět {{ invoice.varsymbol }}'),
                ('invoice_reminder', 'cs', 'Faktura {{ invoice.varsymbol }}')"
        );

        $migration = (string) file_get_contents(
            dirname(__DIR__, 4) . '/db/migrations/0153_invoice_send_dynamic_subject.sql'
        );

        $pdo->exec($migration);
        $pdo->exec($migration);

        $rows = $pdo->query(
            'SELECT code, locale, subject FROM email_templates ORDER BY rowid'
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame([
            ['code' => 'invoice_send', 'locale' => 'cs', 'subject' => '{{ subject }}'],
            ['code' => 'invoice_send', 'locale' => 'en', 'subject' => '{{ subject }}'],
            ['code' => 'invoice_send', 'locale' => 'cs', 'subject' => 'Vlastní předmět {{ invoice.varsymbol }}'],
            ['code' => 'invoice_reminder', 'locale' => 'cs', 'subject' => 'Faktura {{ invoice.varsymbol }}'],
        ], $rows);
    }
}
