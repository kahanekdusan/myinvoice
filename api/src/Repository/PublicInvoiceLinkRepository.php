<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Úložištì public link tokenù pro vydané faktury.
 */
final class PublicInvoiceLinkRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Vrátí aktivní token pro fakturu, nebo vytvoøí nový.
     */
    public function ensureToken(int $invoiceId, int $ttlDays): string
    {
        $row = $this->db->pdo()->prepare(
            'SELECT public_invoice_token, public_invoice_token_expires_at
               FROM invoices
              WHERE id = ?
              LIMIT 1'
        );
        $row->execute([$invoiceId]);
        $current = $row->fetch(PDO::FETCH_ASSOC);
        if ($current === false) {
            throw new \RuntimeException("Invoice #{$invoiceId} not found");
        }

        $token = (string) ($current['public_invoice_token'] ?? '');
        $expiresAt = $current['public_invoice_token_expires_at'] ?? null;
        $isActive = $token !== ''
            && ($expiresAt === null || strtotime((string) $expiresAt) > time());
        if ($isActive) {
            return $token;
        }

        $newExpiresAt = $ttlDays > 0
            ? (new \DateTimeImmutable('+' . $ttlDays . ' days'))->format('Y-m-d H:i:s')
            : null;

        // Extrémnì nepravdìpodobná kolize tokenu na unique indexu — zkusíme znovu.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $newToken = bin2hex(random_bytes(24));
            try {
                $this->db->pdo()->prepare(
                    'UPDATE invoices
                        SET public_invoice_token = ?,
                            public_invoice_token_expires_at = ?
                      WHERE id = ?'
                )->execute([$newToken, $newExpiresAt, $invoiceId]);
                return $newToken;
            } catch (\PDOException $e) {
                $isDuplicate = (int) ($e->errorInfo[1] ?? 0) === 1062;
                if (!$isDuplicate) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Failed to generate unique public invoice token.');
    }

    /**
     * Aktivní faktura podle public tokenu, nebo null.
     */
    public function findActiveByToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id,
                    i.supplier_id,
                    i.varsymbol,
                    i.invoice_type,
                    i.status,
                    i.issue_date,
                    i.due_date,
                    i.language,
                    i.amount_to_pay,
                    i.paid_total,
                    i.total_with_vat,
                    i.exchange_rate,
                    i.public_invoice_token_expires_at,
                    i.public_invoice_view_count,
                    c.company_name AS client_company_name,
                    cur.code AS currency,
                    s.company_name AS supplier_company_name,
                    s.display_name AS supplier_display_name,
                    s.email_branding_enabled,
                    s.email_accent_color
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
               JOIN currencies cur ON cur.id = i.currency_id
               JOIN supplier s ON s.id = i.supplier_id
              WHERE i.public_invoice_token = ?
                AND (i.public_invoice_token_expires_at IS NULL OR i.public_invoice_token_expires_at > NOW())
              LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function markSent(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET public_invoice_sent_at = NOW()
              WHERE id = ?'
        )->execute([$invoiceId]);
    }

    public function touchViewed(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET public_invoice_last_viewed_at = NOW(),
                    public_invoice_view_count = public_invoice_view_count + 1
              WHERE id = ?'
        )->execute([$invoiceId]);
    }

    public function touchHeartbeat(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET public_invoice_last_heartbeat_at = NOW()
              WHERE id = ?'
        )->execute([$invoiceId]);
    }
}
