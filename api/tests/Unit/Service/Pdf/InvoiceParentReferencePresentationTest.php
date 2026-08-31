<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PDO;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class InvoiceParentReferencePresentationTest extends TestCase
{
    public function testQuoteParentUsesPriceQuoteLabel(): void
    {
        self::assertSame('k CN 226003', $this->renderParentReference(true));
    }

    public function testOtherParentKeepsInvoiceLabel(): void
    {
        self::assertSame('k faktuře 2607005', $this->renderParentReference(false, '2607005'));
    }

    public function testParentReferenceLoadsParentInvoiceFromDatabase(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, varsymbol TEXT, invoice_type TEXT, numbering_type TEXT)');
        $pdo->exec("INSERT INTO invoices VALUES (7, '226003', 'proforma', 'quote')");

        $connection = new Connection(new Config([]));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);

        $renderer = (new \ReflectionClass(InvoicePdfRenderer::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(InvoicePdfRenderer::class, 'db'))->setValue($renderer, $connection);

        $method = new \ReflectionMethod(InvoicePdfRenderer::class, 'parentReference');

        self::assertSame([
            'varsymbol' => '226003',
            'is_quote'  => true,
        ], $method->invoke($renderer, ['parent_invoice_id' => 7]));
    }

    private function renderParentReference(bool $isQuote, string $varsymbol = '226003'): string
    {
        $twig = new Environment(new ArrayLoader([
            'reference' => "{{ parent_is_quote ? t('k CN', 'to quote') : t('k faktuře', 'to invoice') }} {{ parent_varsymbol }}",
        ]));
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $cs));

        return $twig->render('reference', [
            'parent_is_quote'  => $isQuote,
            'parent_varsymbol' => $varsymbol,
        ]);
    }
}
