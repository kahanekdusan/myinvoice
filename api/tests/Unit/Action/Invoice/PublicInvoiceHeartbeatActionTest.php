<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\PublicInvoiceHeartbeatAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicInvoiceHeartbeatActionTest extends TestCase
{
    public function testAuthenticatedInternalUserDoesNotCreateClientView(): void
    {
        $token = str_repeat('a', 48);
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('findByPublicToken')->with($token)->willReturn([
            'id' => 42,
            'supplier_id' => 7,
        ]);
        $repo->expects(self::never())->method('findByPublicViewToken');
        $repo->expects(self::never())->method('markPublicLinkViewed');
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::never())->method('log');

        $action = new PublicInvoiceHeartbeatAction($repo, $logger, $this->createStub(IpMatcher::class));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/public/invoice/{$token}/heartbeat")
            ->withParsedBody(['seconds_on_page' => 10])
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5]);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($payload['accepted']);
        self::assertSame('internal_user', $payload['reason']);
    }

    public function testAnonymousTenSecondHeartbeatCreatesClientView(): void
    {
        $token = str_repeat('b', 64);
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('findByPublicToken')->with($token)->willReturn([
            'id' => 42,
            'supplier_id' => 7,
        ]);
        $repo->expects(self::once())->method('markPublicLinkViewed')->with(42, 10);
        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log')->with(
            'invoice.public_link_viewed',
            null,
            'invoice',
            42,
            ['seconds_on_page' => 10],
            self::anything(),
            self::anything(),
        );

        $action = new PublicInvoiceHeartbeatAction($repo, $logger, $this->createStub(IpMatcher::class));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/public/invoice/{$token}/heartbeat")
            ->withParsedBody(['seconds_on_page' => 10]);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['token' => $token]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['accepted']);
    }
}
