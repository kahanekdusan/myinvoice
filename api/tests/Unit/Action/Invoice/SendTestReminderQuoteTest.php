<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\SendTestReminderAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PublicInvoiceLinkFactory;
use MyInvoice\Service\Invoice\QuoteLifecyclePolicy;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SendTestReminderQuoteTest extends TestCase
{
    public function testTestReminderEndpointRejectsQuoteBeforeRenderingOrSending(): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('find')->with(226002)->willReturn([
            'id' => 226002,
            'supplier_id' => 7,
            'invoice_type' => 'proforma',
            'numbering_type' => 'quote',
        ]);
        $renderer = $this->createMock(InvoicePdfRenderer::class);
        $renderer->expects(self::never())->method('render');
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplate');
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('pdo');

        $action = new SendTestReminderAction(
            $repo,
            $renderer,
            $mailer,
            $this->createStub(InvoiceEmailVarsBuilder::class),
            $this->createStub(PublicInvoiceLinkFactory::class),
            $this->createStub(Config::class),
            $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class),
            $db,
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/226002/test-reminder')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['id' => 226002]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('quote_reminder_forbidden', $payload['error']['code'] ?? null);
        self::assertSame(QuoteLifecyclePolicy::REMINDER_FORBIDDEN_MESSAGE, $payload['error']['message'] ?? null);
    }
}
