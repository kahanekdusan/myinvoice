<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Invoice;

use MyInvoice\Action\Invoice\SendTestEmailAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SendTestEmailActionTest extends TestCase
{
    public function testUsesInternalDetailWithoutRotatingClientTrackingToken(): void
    {
        $invoice = [
            'id' => 42,
            'supplier_id' => 7,
            'invoice_type' => 'invoice',
            'numbering_type' => 'default',
            'language' => 'cs',
            'status' => 'draft',
        ];
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('find')->with(42)->willReturn($invoice);
        $repo->expects(self::never())->method('rotatePublicViewToken');

        $renderer = $this->createMock(InvoicePdfRenderer::class);
        $renderer->expects(self::once())->method('render')->with(42, false, 5)->willReturn('/tmp/Faktura-test.pdf');

        $varsBuilder = $this->createMock(InvoiceEmailVarsBuilder::class);
        $varsBuilder->expects(self::once())->method('build')->with($invoice, true, 'cs')->willReturn([
            'is_standard_invoice' => true,
            'qr_data_uri' => null,
        ]);

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('sendTemplate')->with(
            'invoice_send',
            'cs',
            ['supplier@example.test'],
            self::callback(static fn (array $vars): bool =>
                ($vars['invoice_view_url'] ?? null) === 'https://faktury.example.test/invoices/42'
            ),
            null,
            [],
            [],
            [[
                'path' => '/tmp/Faktura-test.pdf',
                'name' => 'Faktura-test.pdf',
                'contentType' => 'application/pdf',
            ]],
            5,
        )->willReturn('accepted');

        $action = new SendTestEmailAction(
            $repo,
            $renderer,
            $mailer,
            $varsBuilder,
            new Config([
                'app' => ['url' => 'https://faktury.example.test'],
                'smtp' => ['from_email' => 'fallback@example.test'],
            ]),
            $this->createStub(ActivityLogger::class),
            $this->createStub(IpMatcher::class),
            $this->connection(),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/42/send-test')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 5]);

        $response = $action($request, (new ResponseFactory())->createResponse(), ['id' => 42]);
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['is_test']);
        self::assertSame(['supplier@example.test'], $payload['sent_to']);
    }

    private function connection(): Connection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE supplier (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $pdo->exec("INSERT INTO supplier (id, email) VALUES (7, 'supplier@example.test')");

        $connection = new Connection(new Config([]));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);
        return $connection;
    }
}
