<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Admin;

use MyInvoice\Action\Admin\SmtpMicrosoftOauthAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SmtpMicrosoftOauthErrorDetailTest extends TestCase
{
    public function testProviderErrorIsShownWithoutOtherResponseFields(): void
    {
        $raw = json_encode([
            'error' => 'invalid_client',
            'error_description' => "AADSTS7000215: Invalid client secret.\r\nTrace ID: synthetic",
            'access_token' => 'must-not-be-shown',
        ], JSON_THROW_ON_ERROR);

        $detail = $this->detail($raw, 400, '');

        self::assertSame(
            'HTTP 400 — invalid_client: AADSTS7000215: Invalid client secret. Trace ID: synthetic',
            $detail
        );
        self::assertStringNotContainsString('must-not-be-shown', $detail);
    }

    public function testCurlErrorTakesPrecedence(): void
    {
        self::assertSame('Connection timed out', $this->detail('', 0, 'Connection timed out'));
    }

    public function testInvalidProviderResponseFallsBackToHttpStatus(): void
    {
        self::assertSame('HTTP 400', $this->detail('<html>Bad request</html>', 400, ''));
    }

    private function detail(mixed $raw, int $httpCode, string $curlError): string
    {
        $method = new ReflectionMethod(SmtpMicrosoftOauthAction::class, 'tokenExchangeErrorDetail');
        return (string) $method->invoke(null, $raw, $httpCode, $curlError);
    }
}
