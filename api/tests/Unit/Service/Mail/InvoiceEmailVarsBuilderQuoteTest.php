<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\InvoicePublicLinkService;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Qr\QrPaymentGenerator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceEmailVarsBuilderQuoteTest extends TestCase
{
    #[DataProvider('czechDocumentPhrases')]
    public function testCzechDocumentPhrasesUseCorrectCase(
        string $invoiceType,
        string $numberingType,
        string $expectedPrefix,
        string $expectedSubjectLabel,
    ): void {
        $vars = $this->builder()->build(
            $this->invoice($invoiceType, $numberingType),
            false,
            'cs',
        );

        self::assertSame($expectedPrefix, $vars['intro_prefix']);
        self::assertSame("{$expectedPrefix} č. 226002.", $vars['intro_plain']);
        self::assertSame($expectedSubjectLabel, $vars['document_type_label']);
        self::assertSame("{$expectedSubjectLabel} 226002 — Testovací dodavatel s.r.o.", $vars['subject']);
    }

    public function testPriceQuoteHasOfferSemanticsAndNoPaymentQr(): void
    {
        $vars = $this->builder()->build(
            $this->invoice('proforma', 'quote'),
            false,
            'cs',
        );

        self::assertTrue($vars['is_quote']);
        self::assertSame(7161.02, $vars['document_total']);
        self::assertNull($vars['qr_data_uri']);
    }

    public function testStandardInvoiceEmailNeverBuildsPaymentQr(): void
    {
        // QrPaymentGenerator je záměrně vytvořen bez konstruktoru. Kdyby ho
        // build() pro běžnou fakturu zavolal, test spadne dřív, než by mohl
        // Mailer QR přibalit jako skrytou CID přílohu.
        $vars = $this->builder()->build(
            $this->invoice('invoice', 'default'),
            false,
            'cs',
        );

        self::assertTrue($vars['is_standard_invoice']);
        self::assertNull($vars['qr_data_uri']);
    }

    public static function czechDocumentPhrases(): iterable
    {
        yield 'faktura' => ['invoice', 'default', 'Zasíláme vám fakturu', 'Faktura'];
        yield 'zálohová faktura' => ['proforma', 'default', 'Zasíláme vám zálohovou fakturu', 'Zálohová faktura'];
        yield 'cenová nabídka' => ['proforma', 'quote', 'V příloze zasíláme cenovou nabídku', 'Cenová nabídka'];
        yield 'opravný daňový doklad' => ['credit_note', 'default', 'Zasíláme vám opravný daňový doklad', 'Opravný daňový doklad'];
        yield 'daňový doklad k platbě' => ['tax_document', 'default', 'Zasíláme vám daňový doklad k přijaté platbě', 'Daňový doklad k přijaté platbě'];
    }

    private function builder(): InvoiceEmailVarsBuilder
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE supplier (
                id INTEGER PRIMARY KEY,
                branding_profiles_enabled INTEGER NOT NULL,
                email_branding_enabled INTEGER NOT NULL,
                email_accent_color TEXT NULL,
                logo_path TEXT NULL
            )'
        );
        $pdo->exec(
            "INSERT INTO supplier
                (id, branding_profiles_enabled, email_branding_enabled, email_accent_color, logo_path)
             VALUES (1, 0, 0, '#3B2D83', NULL)"
        );

        $connection = new Connection($this->createStub(Config::class));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);

        return new InvoiceEmailVarsBuilder(
            $connection,
            (new \ReflectionClass(QrPaymentGenerator::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(InvoicePublicLinkService::class))->newInstanceWithoutConstructor(),
        );
    }

    private function invoice(string $invoiceType, string $numberingType): array
    {
        return [
            'supplier_id'      => 1,
            'supplier_snapshot' => json_encode([
                'company_name' => 'Testovací dodavatel s.r.o.',
                'display_name' => 'Testovací dodavatel',
                'street'       => 'Testovací 1',
                'city'         => 'Praha',
                'zip'          => '100 00',
                'country_name_cs' => 'Česko',
                'email'        => 'supplier@example.test',
            ], JSON_THROW_ON_ERROR),
            'invoice_type'     => $invoiceType,
            'numbering_type'   => $numberingType,
            'varsymbol'        => '226002',
            'status'           => 'issued',
            'amount_to_pay'    => 7161.02,
            'total_with_vat'   => 7161.02,
            'paid_total'       => 0,
            'currency'         => 'CZK',
            'payment_method'   => 'bank_transfer',
        ];
    }
}
