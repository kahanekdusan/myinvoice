<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InvoiceQuoteEmailTemplateTest extends TestCase
{
    public function testCzechEmailUsesQuoteLabelsAndHidesPaymentControls(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates/email'));
        $vars = $this->vars('cs');

        $html = $twig->render('invoice_send.cs.html.twig', $vars);
        $text = $twig->render('invoice_send.cs.txt.twig', $vars);

        foreach (['V příloze zasíláme cenovou nabídku', 'Číslo nabídky', 'Platnost', 'Cena celkem'] as $expected) {
            self::assertStringContainsString($expected, $html);
            self::assertStringContainsString($expected, $text);
        }
        self::assertStringContainsString('7 161,02 Kč', $html);
        self::assertStringContainsString('7 161,02 Kč', $text);
        self::assertStringNotContainsString('7 161,02 CZK', $html);
        self::assertStringNotContainsString('7 161,02 CZK', $text);
        foreach (['zálohovou fakturu', 'Variabilní symbol', 'Splatnost', 'K úhradě', 'QR pro platbu', 'Otevřít doklad'] as $unexpected) {
            self::assertStringNotContainsString($unexpected, $html);
            self::assertStringNotContainsString($unexpected, $text);
        }
    }

    public function testEnglishEmailUsesQuoteLabelsAndHidesPaymentControls(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates/email'));
        $vars = $this->vars('en');
        $vars['greeting'] = 'Hello,';
        $vars['intro_prefix'] = 'Here is your price quote';
        $vars['intro_plain'] = 'Here is your price quote No. 226002.';

        $html = $twig->render('invoice_send.en.html.twig', $vars);
        $text = $twig->render('invoice_send.en.txt.twig', $vars);

        foreach (['Here is your price quote', 'Quote number', 'Validity', 'Total price'] as $expected) {
            self::assertStringContainsString($expected, $html);
            self::assertStringContainsString($expected, $text);
        }
        self::assertStringContainsString('7,161.02 Kč', $html);
        self::assertStringContainsString('7,161.02 Kč', $text);
        foreach (['Variable symbol', 'Due date', 'Amount due', 'QR payment', 'Open document'] as $unexpected) {
            self::assertStringNotContainsString($unexpected, $html);
            self::assertStringNotContainsString($unexpected, $text);
        }
    }

    private function vars(string $locale): array
    {
        return [
            'locale'           => $locale,
            'subject'          => 'Cenová nabídka 226002',
            'greeting'         => 'Dobrý den,',
            'intro_prefix'     => 'V příloze zasíláme cenovou nabídku',
            'intro_plain'      => 'V příloze zasíláme cenovou nabídku č. 226002.',
            'invoice'          => [
                'varsymbol'  => '226002',
                'issue_date' => '2026-05-27',
                'due_date'   => '2026-06-26',
                'currency'   => 'CZK',
            ],
            'supplier'         => null,
            'accent'           => '#3B2D83',
            'accent_soft'      => '#EFEAFF',
            'amount_to_pay'    => 7161.02,
            'document_total'   => 7161.02,
            'is_quote'         => true,
            'is_paid'          => false,
            'is_test'          => false,
            'payment_method'   => 'bank_transfer',
            // I kdyby některý caller omylem předal platební data nebo veřejný odkaz,
            // šablona je u nabídky nesmí zobrazit.
            'qr_data_uri'      => 'cid:qr_payment',
            'invoice_view_url' => 'https://example.test/public/legacy-quote-link',
            'note_lines'       => [],
            'note_text'        => '',
        ];
    }
}
