<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Admin;

use MyInvoice\Action\Admin\SmtpMicrosoftOauthAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SmtpMicrosoftOauthRedirectUriTest extends TestCase
{
    #[DataProvider('redirectUriProvider')]
    public function testMicrosoftRedirectUriUsesHttpOnlyForLocalhost(string $input, string $expected): void
    {
        $method = new ReflectionMethod(SmtpMicrosoftOauthAction::class, 'enforceMicrosoftRedirectScheme');

        self::assertSame($expected, $method->invoke(null, $input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function redirectUriProvider(): iterable
    {
        yield 'localhost keeps HTTP' => [
            'http://localhost:8090/api/admin/smtp/oauth/microsoft/callback',
            'http://localhost:8090/api/admin/smtp/oauth/microsoft/callback',
        ];
        yield 'localhost is case insensitive' => [
            'HTTP://LOCALHOST:8090/api/admin/smtp/oauth/microsoft/callback',
            'HTTP://LOCALHOST:8090/api/admin/smtp/oauth/microsoft/callback',
        ];
        yield 'IPv4 loopback requires HTTPS' => [
            'http://127.0.0.1:8090/api/admin/smtp/oauth/microsoft/callback',
            'https://127.0.0.1:8090/api/admin/smtp/oauth/microsoft/callback',
        ];
        yield 'IPv6 loopback requires HTTPS' => [
            'http://[::1]:8090/api/admin/smtp/oauth/microsoft/callback',
            'https://[::1]:8090/api/admin/smtp/oauth/microsoft/callback',
        ];
        yield 'LAN address requires HTTPS' => [
            'http://192.168.1.25/api/admin/smtp/oauth/microsoft/callback',
            'https://192.168.1.25/api/admin/smtp/oauth/microsoft/callback',
        ];
        yield 'hostname requires HTTPS' => [
            'http://invoice.example.test/api/admin/smtp/oauth/microsoft/callback',
            'https://invoice.example.test/api/admin/smtp/oauth/microsoft/callback',
        ];
        yield 'existing HTTPS stays unchanged' => [
            'https://127.0.0.1:8090/api/admin/smtp/oauth/microsoft/callback',
            'https://127.0.0.1:8090/api/admin/smtp/oauth/microsoft/callback',
        ];
    }
}
