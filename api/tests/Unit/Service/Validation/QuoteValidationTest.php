<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Validation;

use MyInvoice\Service\Validation\QuoteValidation;
use PHPUnit\Framework\TestCase;

/**
 * Tests QuoteValidation::quote() - pure static validation.
 */
final class QuoteValidationTest extends TestCase
{
    private function validItem(): array
    {
        return ['description' => 'Item', 'quantity' => 1, 'unit_price_without_vat' => 100, 'vat_rate_id' => 1];
    }

    public function testValidQuotePasses(): void
    {
        $errors = QuoteValidation::quote([
            'client_id'  => 5,
            'status'     => 'draft',
            'issue_date' => '2026-07-09',
            'valid_until'=> '2026-07-23',
            'items'      => [$this->validItem()],
        ]);
        self::assertSame([], $errors);
    }

    public function testMissingClientFails(): void
    {
        $errors = QuoteValidation::quote(['items' => [$this->validItem()]]);
        self::assertArrayHasKey('client_id', $errors);
    }

    public function testInvalidStatusFails(): void
    {
        $errors = QuoteValidation::quote([
            'client_id' => 5,
            'status'    => 'bogus',
            'items'     => [$this->validItem()],
        ]);
        self::assertArrayHasKey('status', $errors);
    }

    public function testEmptyItemsFails(): void
    {
        $errors = QuoteValidation::quote(['client_id' => 5, 'items' => []]);
        self::assertArrayHasKey('items', $errors);
    }

    public function testDiscountItemsOnlyStillFails(): void
    {
        $errors = QuoteValidation::quote([
            'client_id' => 5,
            'items'     => [['item_kind' => 'discount', 'description' => 'Sleva']],
        ]);
        self::assertArrayHasKey('items', $errors);
    }

    public function testValidUntilBeforeIssueFails(): void
    {
        $errors = QuoteValidation::quote([
            'client_id'   => 5,
            'issue_date'  => '2026-07-09',
            'valid_until' => '2026-07-01',
            'items'       => [$this->validItem()],
        ]);
        self::assertArrayHasKey('valid_until', $errors);
    }

    public function testDiscountOutOfRangeFails(): void
    {
        $errors = QuoteValidation::quote([
            'client_id'        => 5,
            'discount_percent' => 150,
            'items'            => [$this->validItem()],
        ]);
        self::assertArrayHasKey('discount_percent', $errors);
    }
}
