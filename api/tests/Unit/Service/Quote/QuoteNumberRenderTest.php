<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Quote;

use MyInvoice\Service\Quote\QuoteNumberGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests render() - pure method, no DB (instance created without constructor).
 */
final class QuoteNumberRenderTest extends TestCase
{
    private QuoteNumberGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = (new \ReflectionClass(QuoteNumberGenerator::class))->newInstanceWithoutConstructor();
    }

    public function testDefaultTemplate(): void
    {
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('CN20260001', $this->gen->render(QuoteNumberGenerator::DEFAULT_TEMPLATE, $date, 1));
    }

    public function testYearDashCounter(): void
    {
        $date = new \DateTimeImmutable('2026-01-01');
        self::assertSame('2026-0042', $this->gen->render('{YYYY}-{CCCC}', $date, 42));
    }

    public function testMonthlyTemplate(): void
    {
        $date = new \DateTimeImmutable('2026-07-09');
        self::assertSame('NO2026070001', $this->gen->render('NO{YYYY}{MM}{CCCC}', $date, 1));
    }

    public function testTwoDigitYear(): void
    {
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('CN26-007', $this->gen->render('CN{YY}-{CCC}', $date, 7));
    }

    public function testCounterDoesNotTruncate(): void
    {
        $date = new \DateTimeImmutable('2026-04-15');
        self::assertSame('CN202612345', $this->gen->render('CN{YYYY}{CCC}', $date, 12345));
    }
}
