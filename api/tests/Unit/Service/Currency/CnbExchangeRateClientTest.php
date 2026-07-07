<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Currency;

use MyInvoice\Service\Currency\CnbExchangeRateClient;
use PHPUnit\Framework\TestCase;

final class CnbExchangeRateClientTest extends TestCase
{
    private const SAMPLE_FEED = "03.05.2026 #84\n"
        . "země|měna|množství|kód|kurz\n"
        . "EMU|euro|1|EUR|24,360\n"
        . "USA|dolar|1|USD|22,123\n"
        . "Japonsko|jen|100|JPY|14,562\n"
        . "Velká Británie|libra|1|GBP|28,710\n"
        . "Polsko|zlotý|1|PLN|5,729\n";

    public function testParseExtractsAllCurrencies(): void
    {
        $rates = CnbExchangeRateClient::parse(self::SAMPLE_FEED, '2026-05-03');
        self::assertIsArray($rates);
        self::assertArrayHasKey('EUR', $rates);
        self::assertArrayHasKey('USD', $rates);
        self::assertArrayHasKey('JPY', $rates);
        self::assertArrayHasKey('GBP', $rates);
        self::assertArrayHasKey('PLN', $rates);
    }

    public function testParseEurRateNotNormalizedWhenAmountIsOne(): void
    {
        $rates = CnbExchangeRateClient::parse(self::SAMPLE_FEED, '2026-05-03');
        self::assertSame(24.360, $rates['EUR']);
    }

    public function testParseJpyRateNormalizedToPerUnitWhenAmountIsHundred(): void
    {
        // CNB feed: "Japonsko|jen|100|JPY|14,562" → 14,562 / 100 = 0,14562 CZK / 1 JPY
        $rates = CnbExchangeRateClient::parse(self::SAMPLE_FEED, '2026-05-03');
        self::assertEqualsWithDelta(0.14562, $rates['JPY'], 1e-6);
    }

    public function testParseRejectsMismatchedHeaderDate(): void
    {
        // Header říká 03.05.2026 ale ptáme se na 04.05.2026 — vrať null,
        // CNB občas vrací předchozí den pro budoucí dotaz, caller to musí poznat
        $rates = CnbExchangeRateClient::parse(self::SAMPLE_FEED, '2026-05-04');
        self::assertNull($rates);
    }

    public function testParseRejectsEmptyOrTooShortFeed(): void
    {
        self::assertNull(CnbExchangeRateClient::parse('', '2026-05-03'));
        self::assertNull(CnbExchangeRateClient::parse("hello\nworld", '2026-05-03'));
    }

    public function testParseRejectsMalformedHeader(): void
    {
        $bad = "garbage line\nzemě|měna|množství|kód|kurz\nEMU|euro|1|EUR|24,360\n";
        self::assertNull(CnbExchangeRateClient::parse($bad, '2026-05-03'));
    }

    public function testParseSkipsMalformedDataLines(): void
    {
        $feed = "03.05.2026 #84\n"
            . "země|měna|množství|kód|kurz\n"
            . "EMU|euro|1|EUR|24,360\n"
            . "BAD|line|with|too|many|columns\n"
            . "USA|dolar|1|USD|22,123\n"
            . "\n"  // empty line
            . "USA|invalid|0|XXX|0\n";  // amount/rate <= 0 → skip
        $rates = CnbExchangeRateClient::parse($feed, '2026-05-03');
        self::assertIsArray($rates);
        self::assertCount(2, $rates);
        self::assertArrayHasKey('EUR', $rates);
        self::assertArrayHasKey('USD', $rates);
        self::assertArrayNotHasKey('XXX', $rates);
    }

    public function testParseHandlesCrlfLineEndings(): void
    {
        $feed = "03.05.2026 #84\r\n"
            . "země|měna|množství|kód|kurz\r\n"
            . "EMU|euro|1|EUR|24,360\r\n";
        $rates = CnbExchangeRateClient::parse($feed, '2026-05-03');
        self::assertIsArray($rates);
        self::assertSame(24.360, $rates['EUR']);
    }
}
