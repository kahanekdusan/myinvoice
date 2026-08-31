<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TemplateWrapper;
use Twig\TwigFunction;

final class InvoiceTwigSyntaxTest extends TestCase
{
    public function testInvoiceTemplateCompiles(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates'));
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $cs));

        $template = $twig->load('invoice/invoice.twig');

        self::assertInstanceOf(TemplateWrapper::class, $template);
    }
}
