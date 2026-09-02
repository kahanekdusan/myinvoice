<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InvoiceStandardEmailTemplateTest extends TestCase
{
    #[DataProvider('locales')]
    public function testStandardInvoiceContainsOnlyTrackedButtonAndNoPaymentSummary(
        string $locale,
        string $buttonLabel,
        array $forbidden,
    ): void {
        $url = 'https://example.test/invoice/' . str_repeat('a', 64) . '?utm_source=invoice_email';
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates/email'));
        $vars = $this->vars($locale, $url);

        $html = $twig->render("invoice_send.{$locale}.html.twig", $vars);
        $text = $twig->render("invoice_send.{$locale}.txt.twig", $vars);

        self::assertStringContainsString(">{$buttonLabel}</a>", $html);
        self::assertStringContainsString('role="presentation"', $html);
        self::assertStringContainsString('mso-padding-alt', $html);
        self::assertSame(1, substr_count($html, $url), 'URL smí být v HTML pouze v href tlačítka.');
        self::assertSame(1, substr_count($text, $url), 'Textový fallback má obsahovat jedinou přímou URL.');

        foreach ($forbidden as $needle) {
            self::assertStringNotContainsString($needle, $html);
            self::assertStringNotContainsString($needle, $text);
        }
        self::assertStringContainsString($locale === 'en' ? 'Please check the delivery address.' : 'Prosím zkontrolujte dodací adresu.', $html);
    }

    public function testOtherDocumentTypesKeepPaymentControls(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates/email'));
        $vars = $this->vars('cs', 'https://example.test/invoice/credit-note');
        $vars['invoice']['invoice_type'] = 'credit_note';
        $vars['intro_prefix'] = 'Zasíláme vám opravný daňový doklad';
        $vars['intro_plain'] = 'Zasíláme vám opravný daňový doklad č. 20260042.';
        $vars['is_standard_invoice'] = false;
        $vars['qr_data_uri'] = 'cid:qr_payment';

        $html = $twig->render('invoice_send.cs.html.twig', $vars);

        foreach (['Variabilní symbol', 'Splatnost', 'K úhradě', 'QR pro platbu', 'Otevřít doklad'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    public static function locales(): iterable
    {
        yield 'cs' => ['cs', 'Zobrazit fakturu', [
            'Variabilní symbol', 'Datum vystavení', 'Splatnost', 'K úhradě',
            'Uhrazeno', 'QR pro platbu', 'QR kód', 'Otevřít doklad',
        ]];
        yield 'en' => ['en', 'View invoice', [
            'Variable symbol', 'Issue date', 'Due date', 'Amount due',
            'Paid', 'QR payment', 'QR code', 'Open document',
        ]];
    }

    private function vars(string $locale, string $url): array
    {
        return [
            'locale'              => $locale,
            'subject'             => 'Invoice 20260042',
            'greeting'            => $locale === 'en' ? 'Hello,' : 'Dobrý den,',
            'intro_prefix'        => $locale === 'en' ? 'Here is your invoice' : 'Zasíláme vám fakturu',
            'intro_plain'         => $locale === 'en' ? 'Here is your invoice No. 20260042.' : 'Zasíláme vám fakturu č. 20260042.',
            'invoice'             => [
                'invoice_type' => 'invoice',
                'varsymbol'    => '20260042',
                'issue_date'   => '2026-09-01',
                'due_date'     => '2026-09-15',
                'currency'     => 'CZK',
            ],
            'supplier'            => null,
            'accent'              => '#3B2D83',
            'accent_soft'         => '#EFEAFF',
            'amount_to_pay'       => 12100.0,
            'document_total'      => 12100.0,
            'is_quote'            => false,
            'is_standard_invoice' => true,
            'is_paid'             => false,
            'is_test'             => false,
            'payment_method'      => 'bank_transfer',
            'qr_data_uri'         => null,
            'invoice_view_url'    => $url,
            'note_lines'          => [$locale === 'en' ? 'Please check the delivery address.' : 'Prosím zkontrolujte dodací adresu.'],
            'note_text'           => $locale === 'en' ? 'Please check the delivery address.' : 'Prosím zkontrolujte dodací adresu.',
        ];
    }
}
