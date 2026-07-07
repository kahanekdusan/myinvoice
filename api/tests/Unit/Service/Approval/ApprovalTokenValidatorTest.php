<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Approval;

use MyInvoice\Service\Approval\ApprovalTokenValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApprovalTokenValidatorTest extends TestCase
{
    public function testRealProductionTokenAccepted(): void
    {
        // bin2hex(random_bytes(24)) = přesně 48 hex znaků
        $token = bin2hex(random_bytes(24));
        self::assertSame(48, strlen($token));
        self::assertTrue(ApprovalTokenValidator::isValidFormat($token));
    }

    public function testRejectsEmpty(): void
    {
        self::assertFalse(ApprovalTokenValidator::isValidFormat(''));
    }

    public function testRejectsTooShort(): void
    {
        self::assertFalse(ApprovalTokenValidator::isValidFormat(str_repeat('a', 31)));
    }

    public function testAcceptsMinLength(): void
    {
        self::assertTrue(ApprovalTokenValidator::isValidFormat(str_repeat('a', 32)));
    }

    public function testAcceptsMaxLength(): void
    {
        self::assertTrue(ApprovalTokenValidator::isValidFormat(str_repeat('a', 128)));
    }

    public function testRejectsTooLong(): void
    {
        self::assertFalse(ApprovalTokenValidator::isValidFormat(str_repeat('a', 129)));
    }

    /** @return array<string, array{string}> */
    public static function invalidCharsProvider(): array
    {
        return [
            'uppercase hex'      => [str_repeat('A', 48)],
            'has space'          => ['abcdef ' . str_repeat('a', 41)],
            'has slash'          => ['abc/def' . str_repeat('a', 41)],
            'sql injection'      => ["' OR '1'='1" . str_repeat('a', 38)],
            'path traversal'     => ['../' . str_repeat('a', 45)],
            'unicode'            => ['čšř' . str_repeat('a', 42)],
            'has g (non-hex)'    => [str_repeat('g', 48)],
            'has dash'           => [str_repeat('a', 23) . '-' . str_repeat('a', 24)],
        ];
    }

    #[DataProvider('invalidCharsProvider')]
    public function testRejectsInvalidCharacters(string $token): void
    {
        self::assertFalse(ApprovalTokenValidator::isValidFormat($token));
    }
}
