<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Admin;

use MyInvoice\Action\Admin\SmtpMicrosoftOauthAction;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Slim\Psr7\Factory\ResponseFactory;

final class SmtpMicrosoftOauthSuccessRedirectTest extends TestCase
{
    public function testSuccessfulOauthReturnsToMicrosoftIntegrationAdministration(): void
    {
        $method = new ReflectionMethod(SmtpMicrosoftOauthAction::class, 'oauthSuccessRedirect');
        $response = $method->invoke(null, (new ResponseFactory())->createResponse());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/integrations?tab=microsoft', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }
}
