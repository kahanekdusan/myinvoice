<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Inkrementuje měsíc v řetězcích, které vypadají jako rok+měsíc.
 *
 * Sdílená logika mezi BulkReissueAction (manuální klon faktury) a
 * RecurringInvoiceGenerator (cron pravidelných faktur).
 *
 * Podporované formáty (vždy musí být přítomen 4-místný rok, jinak je
 * dvojice čísel příliš ambiguózní — např. "5/26" může být datum 26. května):
 *   M/YYYY, MM/YYYY        "3/2026"   → "4/2026",   "12/2025"  → "1/2026"
 *   YYYY-MM, YYYY-M        "2026-05"  → "2026-06",  "2025-12"  → "2026-01"
 *   YYYY/MM                "2026/05"  → "2026/06"
 *   MM.YYYY, M.YYYY        "12.2025"  → "1.2026"
 *   MM-YYYY, M-YYYY        "12-2025"  → "1-2026"
 *
 * Zachovává původní separátor i zero-padding měsíce.
 * Plná data (např. "2026-05-15") jsou chráněna lookaroundy a neinkrementují se.
 * Neplatné měsíce (0, >12) zůstávají beze změny.
 */
final class MonthIncrementer
{
    /**
     * Posune měsíc v řetězci o $months dopředu (default 1). Záporné = zpět.
     * Pro recurring s frequency != monthly se obvykle volá s $months = 3/6/12.
     */
    public static function increment(string $text, int $months = 1): string
    {
        if ($months === 0) return $text;

        // (?<![\d./-]) … (?![\d./-]) chrání před matchem uvnitř plných dat
        // jako "2026-05-15" nebo Czech "20.5.2026".
        return preg_replace_callback(
            '/(?<![\d.\/\-])(\d{1,4})([.\/\-])(\d{1,4})(?![\d.\/\-])/',
            function ($m) use ($months) {
                [$full, $left, $sep, $right] = $m;
                $leftLen  = strlen($left);
                $rightLen = strlen($right);

                // Identifikuj, která strana je rok (přesně 4 číslice) a která měsíc (1-2 číslice).
                // Padding: ISO formát "YYYY-MM" vždy paduje (konvence). Month-first
                // formáty padují jen když uživatel sám napsal leading zero ("01-2026"),
                // jinak ne ("12/2025" → "1/2026", ne "01/2026").
                if ($leftLen === 4 && $rightLen >= 1 && $rightLen <= 2) {
                    $year         = (int) $left;
                    $month        = (int) $right;
                    $yearFirst    = true;
                    $monthPadded  = true;
                } elseif ($rightLen === 4 && $leftLen >= 1 && $leftLen <= 2) {
                    $month        = (int) $left;
                    $year         = (int) $right;
                    $yearFirst    = false;
                    $monthPadded  = $leftLen === 2 && $left[0] === '0';
                } else {
                    return $full; // nezná se, který je rok
                }

                if ($month < 1 || $month > 12) {
                    return $full; // neplatný měsíc
                }

                // Total months from year 0 → posun → zpět na year+month
                $total = $year * 12 + ($month - 1) + $months;
                if ($total < 0) return $full;  // safety, nepoužíváme záporné roky
                $newYear  = intdiv($total, 12);
                $newMonth = ($total % 12) + 1;

                $monthStr = $monthPadded ? sprintf('%02d', $newMonth) : (string) $newMonth;
                return $yearFirst
                    ? "{$newYear}{$sep}{$monthStr}"
                    : "{$monthStr}{$sep}{$newYear}";
            },
            $text,
        ) ?? $text;
    }
}
