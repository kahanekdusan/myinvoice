<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\BulkReissueAction;
use MyInvoice\Action\Invoice\CloneInvoiceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CloneInvoiceQuoteConversionTest extends TestCase
{
    public function testApprovedQuoteCreatesLinkedInvoiceDraftWithCanonicalTargetAndCurrentDate(): void
    {
        $quoteId = 226002;
        $supplierId = 7;
        $userId = 19;
        $today = date('Y-m-d');

        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('find')->with($quoteId)->willReturn([
            'id' => $quoteId,
            'supplier_id' => $supplierId,
            'invoice_type' => 'proforma',
            'numbering_type' => 'quote',
            'approval_status' => 'approved',
            'final_invoice' => null,
            'advance_invoice' => null,
        ]);

        $bulk = $this->createMock(BulkReissueAction::class);
        $bulk->expects(self::once())->method('cloneOne')->with(
            $quoteId,
            $today,
            false,
            $userId,
            'invoice',
            'default',
            $quoteId,
        )->willReturn(226003);

        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log');
        $ipMatcher = $this->createStub(IpMatcher::class);
        $ipMatcher->method('clientIpFromRequest')->willReturn('127.0.0.1');

        $action = new CloneInvoiceAction($repo, $bulk, $logger, $ipMatcher);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/invoices/{$quoteId}/clone")
            ->withParsedBody([
                'increment_month_in_descriptions' => true,
                'issue_date' => '2020-01-15',
                'target_invoice_type' => 'invoice',
                'target_numbering_type' => 'default',
                'parent_invoice_id' => null,
            ])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId]);

        $response = $action(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $quoteId],
        );
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(226003, $payload['draft_id'] ?? null);
    }
}
