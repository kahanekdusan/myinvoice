<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\Invoice\PublicInvoiceLinkFactory;
use MyInvoice\Service\Invoice\SnapshotBuilder;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;

final class AutoIssueAndSendServiceEmailAttachmentTest extends TestCase
{
    public function testStandardInvoiceAutoSendDoesNotAttachGeneratedPdf(): void
    {
        $invoice = [
            'id' => 42,
            'supplier_id' => 7,
            'invoice_type' => 'invoice',
            'status' => 'issued',
            'language' => 'cs',
            'varsymbol' => '20260042',
        ];
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('find')->with(42)->willReturn($invoice);
        $repo->expects(self::once())->method('rotatePublicViewToken')->with(42)->willReturn(str_repeat('a', 64));

        $renderer = $this->createMock(InvoicePdfRenderer::class);
        $renderer->expects(self::once())->method('render')->with(42, false, 5)->willReturn('/tmp/Faktura-20260042.pdf');

        $recipients = $this->createMock(RecipientResolver::class);
        $recipients->expects(self::once())->method('resolve')->with(RecipientResolver::TYPE_DOCUMENTS, $invoice)->willReturn([
            'to' => ['client@example.test'],
            'cc' => [],
            'bcc' => [],
        ]);

        $linkFactory = $this->createMock(PublicInvoiceLinkFactory::class);
        $linkFactory->expects(self::once())->method('build')->willReturn('https://faktury.example.test/invoice/' . str_repeat('a', 64));

        $varsBuilder = $this->createMock(InvoiceEmailVarsBuilder::class);
        $varsBuilder->expects(self::once())->method('build')->with($invoice, false, 'cs')->willReturn([
            'is_standard_invoice' => true,
            'qr_data_uri' => null,
        ]);

        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::once())->method('sendTemplate')->with(
            'invoice_send',
            'cs',
            ['client@example.test'],
            self::callback(static fn (array $vars): bool => str_starts_with(
                (string) ($vars['invoice_view_url'] ?? ''),
                'https://faktury.example.test/invoice/',
            )),
            null,
            [],
            [],
            [],
            5,
        )->willReturn('accepted');

        $logger = $this->createMock(ActivityLogger::class);
        $logger->expects(self::once())->method('log')->with(
            'invoice.sent',
            5,
            'invoice',
            42,
            self::callback(static fn (array $payload): bool => ($payload['delivery_mode'] ?? null) === 'public_link'),
            '127.0.0.1',
            'PHPUnit',
        );

        $service = new AutoIssueAndSendService(
            $repo,
            $this->connection(),
            $this->createStub(VarsymbolGenerator::class),
            $this->createStub(SnapshotBuilder::class),
            $linkFactory,
            $renderer,
            $recipients,
            $mailer,
            $varsBuilder,
            $logger,
            $this->createStub(StatsRecomputer::class),
            new Config([]),
        );

        $result = $service->run(42, 5, '127.0.0.1', 'PHPUnit');

        self::assertFalse($result['issued']);
        self::assertSame(['client@example.test'], $result['sent_to']);
    }

    private function connection(): Connection
    {
        $pdo = new Sqlite('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->createFunction('NOW', static fn (): string => '2026-09-01 12:00:00');
        $pdo->exec(
            'CREATE TABLE invoices (
                id INTEGER PRIMARY KEY,
                status TEXT NOT NULL,
                sent_at TEXT NULL,
                public_link_sent_at TEXT NULL
            )'
        );
        $pdo->exec("INSERT INTO invoices (id, status) VALUES (42, 'issued')");

        $connection = new Connection(new Config([]));
        (new \ReflectionProperty(Connection::class, 'pdo'))->setValue($connection, $pdo);
        return $connection;
    }
}
