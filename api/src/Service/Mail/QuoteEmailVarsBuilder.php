<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Branding\AccentColor;

/**
 * Sestavuje template variables pro quote_send.{cs|en}.{html|txt}.twig.
 */
final class QuoteEmailVarsBuilder
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /** @return array<string,mixed> */
    public function build(array $quote, bool $isTest, string $locale): array
    {
        $number = (string) ($quote['quote_number'] ?? ('#' . (int) ($quote['id'] ?? 0)));

        if ($locale === 'en') {
            $greeting = 'Hello,';
            $introPrefix = 'we are sending you quote';
            $introPlain = "we are sending you quote {$number}.";
        } else {
            $greeting = 'Dobrý den,';
            $introPrefix = 'posíláme Vám cenovou nabídku';
            $introPlain = "posíláme Vám cenovou nabídku {$number}.";
        }

        return [
            'greeting'      => $greeting,
            'intro_prefix'  => $introPrefix,
            'intro_plain'   => $introPlain,
            'quote'         => $quote,
            'client_name'   => (string) ($quote['client_company_name'] ?? ''),
            'is_test'       => $isTest,
            'subject'       => $this->buildSubject($quote, $isTest, $locale),
            'supplier'      => $this->loadSupplierFooter($quote),
        ];
    }

    private function buildSubject(array $quote, bool $isTest, string $locale): string
    {
        $number = (string) ($quote['quote_number'] ?? ('#' . (int) ($quote['id'] ?? 0)));
        $supplier = $this->resolveSupplierName($quote);
        $prefix = $isTest ? '[TEST] ' : '';
        $base = $locale === 'en' ? 'Quote' : 'Cenová nabídka';
        return $prefix . $base . ' ' . $number . ($supplier !== '' ? ' — ' . $supplier : '');
    }

    private function resolveSupplierName(array $quote): string
    {
        $snap = $quote['supplier_snapshot'] ?? null;
        if (is_string($snap)) {
            $snap = json_decode($snap, true);
        }
        if (is_array($snap)) {
            return (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
        }

        $supplierId = (int) ($quote['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return '';
        }

        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(display_name, company_name) FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    /** @return array<string,mixed>|null */
    private function loadSupplierFooter(array $quote): ?array
    {
        $row = null;
        $snapshot = $quote['supplier_snapshot'] ?? null;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }
        if (is_array($snapshot)) {
            $row = [
                'company_name' => $snapshot['company_name'] ?? '',
                'display_name' => $snapshot['display_name'] ?? null,
                'tagline'      => $snapshot['tagline'] ?? null,
                'street'       => $snapshot['street'] ?? '',
                'city'         => $snapshot['city'] ?? '',
                'zip'          => $snapshot['zip'] ?? '',
                'country'      => $snapshot['country_name_cs'] ?? '',
                'email'        => $snapshot['email'] ?? null,
                'phone'        => $snapshot['phone'] ?? null,
                'web'          => $snapshot['web'] ?? null,
            ];
        }

        $supplierId = (int) ($quote['supplier_id'] ?? 0);
        if ($row === null && $supplierId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT s.id, s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                        s.email, s.phone, s.web, co.name_cs AS country
                   FROM supplier s
              LEFT JOIN countries co ON co.id = s.country_id
                  WHERE s.id = ?'
            );
            $stmt->execute([$supplierId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        if ($row !== null && empty($row['id']) && $supplierId > 0) {
            $row['id'] = $supplierId;
        }

        if ($row !== null && $supplierId > 0) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT email_branding_enabled, email_accent_color, logo_path
                   FROM supplier
                  WHERE id = ?'
            );
            $stmt->execute([$supplierId]);
            $branding = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($branding !== false) {
                $row['email_branding_enabled'] = (bool) $branding['email_branding_enabled'];
                $row['email_accent_color'] = (string) ($branding['email_accent_color'] ?: '#3B2D83');
                $row['logo_path'] = $branding['logo_path'] ?: null;
                $row['accent_soft'] = AccentColor::emailBackground(
                    $row['email_branding_enabled'],
                    $row['email_accent_color'],
                );
            }
        }

        return $row;
    }
}
