<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

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
