<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PublicInvoiceLinkFactory;
use MyInvoice\Service\Invoice\QuoteLifecyclePolicy;
use MyInvoice\Service\Invoice\ReminderService;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use PHPUnit\Framework\TestCase;

final class ReminderServiceQuoteTest extends TestCase
{
    public function testQuoteIsRejectedBeforeAnyDeliverySideEffect(): void
    {
        $repo = $this->createMock(InvoiceRepository::class);
        $repo->expects(self::once())->method('find')->with(226002)->willReturn([
            'id' => 226002,
            'invoice_type' => 'proforma',
            'numbering_type' => 'quote',
            'status' => 'sent',
        ]);
        $repo->expects(self::never())->method('rotatePublicViewToken');

        $renderer = $this->createMock(InvoicePdfRenderer::class);
        $renderer->expects(self::never())->method('render');
        $mailer = $this->createMock(Mailer::class);
        $mailer->expects(self::never())->method('sendTemplate');
        $recipients = $this->createMock(RecipientResolver::class);
        $recipients->expects(self::never())->method('resolve');

        $service = new ReminderService(
            $repo,
            $this->createStub(Connection::class),
            $this->createStub(PublicInvoiceLinkFactory::class),
            $renderer,
            $mailer,
            $this->createStub(InvoiceEmailVarsBuilder::class),
            $this->createStub(ActivityLogger::class),
            $recipients,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(QuoteLifecyclePolicy::REMINDER_FORBIDDEN_MESSAGE);

        $service->send(226002);
    }
}
