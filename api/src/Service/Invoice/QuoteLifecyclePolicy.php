<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

final class QuoteLifecyclePolicy
{
    public const REMINDER_FORBIDDEN_MESSAGE = 'Cenové nabídky se nikdy neupomínají.';

    public static function isQuote(array $invoice): bool
    {
        return ($invoice['invoice_type'] ?? null) === 'proforma'
            && ($invoice['numbering_type'] ?? 'default') === 'quote';
    }

    public static function isConversion(
        array $source,
        ?string $targetInvoiceType,
        ?string $targetNumberingType,
    ): bool {
        return self::isQuote($source)
            && in_array($targetInvoiceType, ['invoice', 'proforma'], true)
            && $targetNumberingType !== 'quote';
    }

    /** @return array{code: string, message: string}|null */
    public static function reminderViolation(array $invoice): ?array
    {
        return self::isQuote($invoice)
            ? [
                'code' => 'quote_reminder_forbidden',
                'message' => self::REMINDER_FORBIDDEN_MESSAGE,
            ]
            : null;
    }

    /** @return array{code: string, message: string}|null */
    public static function conversionViolation(
        array $source,
        ?string $targetInvoiceType,
        ?string $targetNumberingType,
    ): ?array {
        if (!self::isConversion($source, $targetInvoiceType, $targetNumberingType)) {
            return null;
        }
        if (($source['approval_status'] ?? 'none') !== 'approved') {
            return [
                'code' => 'quote_not_approved',
                'message' => 'Navazující doklad lze vytvořit pouze ze schválené cenové nabídky.',
            ];
        }
        if (!empty($source['final_invoice']) || !empty($source['advance_invoice'])) {
            return [
                'code' => 'quote_already_invoiced',
                'message' => 'Z cenové nabídky již byl vytvořen navazující doklad.',
            ];
        }

        return null;
    }

    /** @return list<string> */
    public static function allowedManualStatuses(array $invoice): array
    {
        return self::isQuote($invoice)
            ? ['none', 'approved', 'expired', 'rejected']
            : ['none', 'approved', 'rejected'];
    }
}
