<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\MonthIncrementer;
use PHPUnit\Framework\TestCase;

/**
 * Doplňující testy pro generický increment(text, months) — pro frequency
 * quarterly/annually se posouvá o 3/12 měsíců. Test {@see IncrementMonthInStringTest}
 * pokrývá pouze default +1 přes BulkReissueAction wrapper.
 */
final class MonthIncrementerTest extends TestCase
{
    public function testIncrementByThreeMonths(): void
    {
        // Quarterly: 3/2026 → 6/2026
        self::assertSame(
            'Hosting 6/2026',
            MonthIncrementer::increment('Hosting 3/2026', 3),
        );
    }

    public function testIncrementByThreeMonthsAcrossYear(): void
    {
        self::assertSame(
            'Hosting 1/2027',
            MonthIncrementer::increment('Hosting 10/2026', 3),
        );
    }

    public function testIncrementByTwelveMonths(): void
    {
        // Annually: 3/2026 → 3/2027 (přesně rok)
        self::assertSame(
            'Předplatné 3/2027',
            MonthIncrementer::increment('Předplatné 3/2026', 12),
        );
    }

    public function testIncrementByZeroIsNoop(): void
    {
        self::assertSame(
            'Hosting 3/2026',
            MonthIncrementer::increment('Hosting 3/2026', 0),
        );
    }

    public function testIsoFormatPreservedAcrossPeriod(): void
    {
        // YYYY-MM s zero-padding → padding zachován
        self::assertSame(
            'období 2026-04',
            MonthIncrementer::increment('období 2026-01', 3),
        );
        self::assertSame(
            'období 2027-01',
            MonthIncrementer::increment('období 2026-04', 9),
        );
    }
}
