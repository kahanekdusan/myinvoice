<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\QuoteLifecyclePolicy;
use PHPUnit\Framework\TestCase;

final class QuoteLifecyclePolicyTest extends TestCase
{
    public function testApprovedQuoteCanCreateInvoiceAndIsLinkedAutomaticallyByTheAction(): void
    {
        $quote = $this->quote('approved');

        self::assertTrue(QuoteLifecyclePolicy::isConversion($quote, 'invoice', 'default'));
        self::assertNull(QuoteLifecyclePolicy::conversionViolation($quote, 'invoice', 'default'));
    }

    public function testUnapprovedOrExpiredQuoteCannotCreateInvoice(): void
    {
        foreach (['none', 'requested', 'expired', 'rejected'] as $status) {
            $violation = QuoteLifecyclePolicy::conversionViolation($this->quote($status), 'invoice', 'default');

            self::assertSame('quote_not_approved', $violation['code'] ?? null, $status);
        }
    }

    public function testQuoteWithLinkedDocumentCannotBeConvertedAgain(): void
    {
        $quote = $this->quote('approved');
        $quote['final_invoice'] = ['id' => 42];

        $violation = QuoteLifecyclePolicy::conversionViolation($quote, 'invoice', 'default');

        self::assertSame('quote_already_invoiced', $violation['code'] ?? null);
    }

    public function testQuoteCloneAndOrdinaryInvoiceCloneAreNotConversions(): void
    {
        self::assertNull(QuoteLifecyclePolicy::conversionViolation($this->quote('none'), 'proforma', 'quote'));
        self::assertNull(QuoteLifecyclePolicy::conversionViolation([
            'invoice_type' => 'invoice',
            'numbering_type' => 'default',
            'approval_status' => 'none',
        ], 'invoice', 'default'));
    }

    public function testExpiredIsManualStatusOnlyForQuotes(): void
    {
        self::assertContains('expired', QuoteLifecyclePolicy::allowedManualStatuses($this->quote('none')));
        self::assertNotContains('expired', QuoteLifecyclePolicy::allowedManualStatuses([
            'invoice_type' => 'invoice',
            'numbering_type' => 'default',
        ]));
    }

    public function testEveryReminderIsForbiddenForQuotesOnly(): void
    {
        $violation = QuoteLifecyclePolicy::reminderViolation($this->quote('approved'));

        self::assertSame('quote_reminder_forbidden', $violation['code'] ?? null);
        self::assertSame(QuoteLifecyclePolicy::REMINDER_FORBIDDEN_MESSAGE, $violation['message'] ?? null);
        self::assertNull(QuoteLifecyclePolicy::reminderViolation([
            'invoice_type' => 'proforma',
            'numbering_type' => 'default',
        ]));
        self::assertNull(QuoteLifecyclePolicy::reminderViolation([
            'invoice_type' => 'invoice',
            'numbering_type' => 'default',
        ]));
    }

    private function quote(string $approvalStatus): array
    {
        return [
            'invoice_type' => 'proforma',
            'numbering_type' => 'quote',
            'approval_status' => $approvalStatus,
            'final_invoice' => null,
            'advance_invoice' => null,
        ];
    }
}
