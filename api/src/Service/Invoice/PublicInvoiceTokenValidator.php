<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Validace formátu public invoice tokenu.
 */
final class PublicInvoiceTokenValidator
{
    private const TOKEN_REGEX = '/^[a-f0-9]{32,128}$/';

    public static function isValidFormat(string $token): bool
    {
        return $token !== '' && preg_match(self::TOKEN_REGEX, $token) === 1;
    }
}
