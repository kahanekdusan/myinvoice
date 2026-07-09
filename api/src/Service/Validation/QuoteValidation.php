<?php

declare(strict_types=1);

namespace MyInvoice\Service\Validation;

/**
 * Validace payloadu cenové nabídky. Sdílí položkovou validaci s fakturami
 * (InvoiceAmountPolicy::validateItem) — stejná pravidla pro popis/množství/cenu/DPH.
 */
final class QuoteValidation
{
    /**
     * @return array<string, string[]>
     */
    public static function quote(array $data): array
    {
        $err = [];

        $status = (string) ($data['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'sent', 'ordered', 'invoiced', 'rejected'], true)) {
            $err['status'][] = 'Neplatný stav nabídky';
        }

        if (array_key_exists('payment_method', $data) && $data['payment_method'] !== null && $data['payment_method'] !== '') {
            if (!in_array((string) $data['payment_method'], ['bank_transfer', 'card', 'cash', 'other'], true)) {
                $err['payment_method'][] = 'Neplatný způsob úhrady';
            }
        }

        if (empty($data['client_id']) || !is_numeric($data['client_id'])) {
            $err['client_id'][] = 'Klient je povinný';
        }

        if (isset($data['currency_id']) && (int) $data['currency_id'] <= 0) {
            $err['currency_id'][] = 'Neplatné currency_id';
        }

        if (!empty($data['issue_date']) && !self::isValidDate((string) $data['issue_date'])) {
            $err['issue_date'][] = 'Neplatné datum vystavení';
        }
        if (!empty($data['valid_until'])) {
            if (!self::isValidDate((string) $data['valid_until'])) {
                $err['valid_until'][] = 'Neplatné datum platnosti';
            } elseif (!empty($data['issue_date']) && self::isValidDate((string) $data['issue_date'])
                && (string) $data['valid_until'] < (string) $data['issue_date']) {
                $err['valid_until'][] = 'Platnost musí být po datu vystavení';
            }
        }

        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            $err['items'][] = 'items musí být pole';
        } else {
            $standardCount = 0;
            foreach (array_values($items) as $i => $item) {
                if (!is_array($item)) {
                    $err["items.{$i}"][] = 'Neplatná položka';
                    continue;
                }
                if (($item['item_kind'] ?? 'standard') === 'discount') {
                    continue;
                }
                $standardCount++;
                $err = array_merge($err, InvoiceAmountPolicy::validateItem($item, $i));
            }
            if ($standardCount === 0) {
                $err['items'][] = 'Nabídka musí mít alespoň jednu položku';
            }
        }

        if (array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null && $data['discount_percent'] !== '') {
            if (!is_numeric($data['discount_percent'])) {
                $err['discount_percent'][] = 'Sleva musí být číslo';
            } else {
                $d = (float) $data['discount_percent'];
                if ($d < 0 || $d > 100) {
                    $err['discount_percent'][] = 'Sleva musí být mezi 0 a 100 %';
                }
            }
        }

        return $err;
    }

    private static function isValidDate(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }
}
