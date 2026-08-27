<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class InvoiceQuotePresentationTest extends TestCase
{
    public function testQuotePdfHtmlHasNoPaymentBlockOrProformaWordingBeforeAndAfterPayment(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates'));
        $twig->addFunction(new TwigFunction('t', static fn (string $cs, string $en): string => $cs));

        foreach ([
            ['status' => 'issued', 'is_paid' => false, 'paid_at' => null, 'paid_total' => 0],
            ['status' => 'paid', 'is_paid' => true, 'paid_at' => '2026-05-28', 'paid_total' => 500],
        ] as $paymentState) {
            $vars = $this->vars();
            $vars['is_paid'] = $paymentState['is_paid'];
            $vars['invoice']['status'] = $paymentState['status'];
            $vars['invoice']['paid_at'] = $paymentState['paid_at'];
            $vars['invoice']['paid_total'] = $paymentState['paid_total'];
            $html = $twig->render('invoice/invoice.twig', $vars);

            foreach (['Cenová nabídka', 'Platnost', 'Tento dokument je cenová nabídka', 'ISDOC', 'class="right quote-right"'] as $expected) {
                self::assertStringContainsString($expected, $html);
            }
            self::assertStringContainsString('1 210,00 Kč', $html);
            self::assertStringNotContainsString('1 210,00 CZK', $html);
            foreach (['class="left"', 'class="pay-panel', 'Bankovní spojení', 'Zálohová faktura', 'Splatnost', 'K úhradě', 'UHRAZENO', 'Zbývá uhradit', 'Odečet zálohy'] as $unexpected) {
                self::assertStringNotContainsString($unexpected, $html);
            }
        }
    }

    private function vars(): array
    {
        return [
            'css'                  => '',
            'locale'               => 'cs',
            'date_format'          => 'j. n. Y',
            'decimal_sep'          => ',',
            'thousand_sep'         => "\u{00A0}",
            'doc_type_label'       => 'Cenová nabídka',
            'doc_title'            => 'Cenová nabídka 226002',
            'quote_number_display' => '226002',
            'payment_varsymbol'    => '226002',
            'parent_varsymbol'     => null,
            'payment_method'       => 'bank_transfer',
            'is_paid'              => true,
            'qr_data_uri'          => 'data:image/png;base64,AAAA',
            'logo_path'            => null,
            'logo_show_name'       => false,
            'isdoc_attachment'     => true,
            'work_report'          => null,
            'bank'                 => [
                'account_number' => '1000000005',
                'bank_code'      => '0100',
                'bank_name'      => 'Testovací banka',
            ],
            'supplier'             => [
                'company_name'    => 'Test s.r.o.',
                'display_name'    => 'Test',
                'street'          => 'Testovací 1',
                'zip'             => '100 00',
                'city'            => 'Praha',
                'country_name_cs' => 'Česká republika',
                'country_name_en' => 'Czech Republic',
                'country_iso2'    => 'CZ',
                'ic'              => '12345678',
                'dic'             => 'CZ12345678',
                'is_vat_payer'    => true,
                'commercial_register' => null,
            ],
            'client'               => [
                'company_name'    => 'Odběratel s.r.o.',
                'first_name'      => '',
                'last_name'       => '',
                'street'          => 'Syntetická 2',
                'zip'             => '110 00',
                'city'            => 'Praha',
                'country_name_cs' => 'Česká republika',
                'country_name_en' => 'Czech Republic',
                'country_iso2'    => 'CZ',
                'ic'              => '87654321',
                'dic'             => 'CZ87654321',
                'tax_number'      => null,
            ],
            'invoice'              => [
                'id'                  => 1,
                'invoice_type'        => 'proforma',
                'numbering_type'      => 'quote',
                'varsymbol'           => '226002',
                'issue_date'          => '2026-05-27',
                'due_date'            => '2026-06-26',
                'tax_date'            => null,
                'currency'            => 'CZK',
                'status'              => 'paid',
                'paid_at'             => '2026-05-28',
                'payment_method'      => 'bank_transfer',
                'reverse_charge'      => false,
                'prices_include_vat'  => false,
                'project_name'        => null,
                'project_number'      => null,
                'contract_number'     => null,
                'parent_invoice_id'   => null,
                'note_above_items'    => 'Syntetická poznámka k nabídce.',
                'note_below_items'    => null,
                'advance_paid_amount' => 100,
                'amount_to_pay'       => 1110,
                'paid_total'          => 500,
                'czk_recap'           => null,
                'vat_breakdown'       => [],
                'totals'              => [
                    'without_vat' => 1000,
                    'vat'         => 210,
                    'with_vat'    => 1210,
                ],
                'items'               => [[
                    'item_kind'             => 'normal',
                    'description'           => 'Syntetická služba',
                    'quantity'              => 1,
                    'unit'                  => 'ks',
                    'unit_price_without_vat' => 1000,
                    'vat_rate_snapshot'     => 21,
                    'total_without_vat'     => 1000,
                    'total_with_vat'        => 1210,
                ]],
            ],
        ];
    }
}
