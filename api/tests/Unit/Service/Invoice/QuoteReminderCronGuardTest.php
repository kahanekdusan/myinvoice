<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use PHPUnit\Framework\TestCase;

final class QuoteReminderCronGuardTest extends TestCase
{
    public function testPaymentReminderCronExcludesQuotesInItsCandidateQuery(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/bin/cron-send-reminders.php');

        self::assertIsString($source);
        self::assertStringContainsString("COALESCE(i.numbering_type, 'default') <> 'quote'", $source);
    }

    public function testApprovalReminderCronExcludesQuotesThroughLifecyclePolicy(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/bin/cron-send-approval-reminders.php');

        self::assertIsString($source);
        self::assertStringContainsString('!QuoteLifecyclePolicy::isQuote($invoice)', $source);
    }

    public function testEveryQuoteWritePathForcesAutomaticRemindersOff(): void
    {
        $apiRoot = dirname(__DIR__, 4);
        $repository = file_get_contents($apiRoot . '/src/Repository/InvoiceRepository.php');
        $bulkReissue = file_get_contents($apiRoot . '/src/Action/Invoice/BulkReissueAction.php');
        $issue = file_get_contents($apiRoot . '/src/Action/Invoice/IssueInvoiceAction.php');

        self::assertIsString($repository);
        self::assertIsString($bulkReissue);
        self::assertIsString($issue);
        self::assertStringContainsString('auto_send_reminders = 0 WHERE id = ?', $repository);
        self::assertStringContainsString('$params[] = $isQuote ? 0', $bulkReissue);
        self::assertStringContainsString('CASE WHEN ? = "quote" THEN 0 ELSE auto_send_reminders END', $issue);
    }
}
