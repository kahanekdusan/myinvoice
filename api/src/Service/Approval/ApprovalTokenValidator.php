<?php

declare(strict_types=1);

namespace MyInvoice\Service\Approval;

/**
 * Validace formátu approval tokenu.
 *
 * Token v invoices.approval_token je generován jako bin2hex(random_bytes(24))
 * → 48 hex znaků. Allow rozsah [32, 128] pro budoucí kompatibilitu (kratší =
 * historické, delší = upgrade entropy).
 *
 * Volá se v public endpointech PŘED databázovým lookupem — chrání proti
 * SQL queries s patologicky dlouhým / non-hex inputem (path traversal,
 * neformované hex stringy).
 */
final class ApprovalTokenValidator
{
    /** Regex pro hex token v rozmezí 32-128 znaků (lower-case). */
    private const TOKEN_REGEX = '/^[a-f0-9]{32,128}$/';

    public static function isValidFormat(string $token): bool
    {
        return $token !== '' && preg_match(self::TOKEN_REGEX, $token) === 1;
    }
}
