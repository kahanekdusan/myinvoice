<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Mail;

use MyInvoice\Service\Mail\ApprovalEmailVarsBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Subject building je pure static helper — testujeme ho bez DB / Config / mocků.
 */
final class ApprovalEmailVarsBuilderSubjectTest extends TestCase
{
    public function testCsRegular(): void
    {
        self::assertSame(
            'Žádost o schválení výkazu práce (2605012) — Acme',
            ApprovalEmailVarsBuilder::buildSubject('2605012', 'Acme', 'cs', false, false),
        );
    }

    public function testEnRegular(): void
    {
        self::assertSame(
            'Work report — please approve (2605012) — Acme',
            ApprovalEmailVarsBuilder::buildSubject('2605012', 'Acme', 'en', false, false),
        );
    }

    public function testTestPrefixCs(): void
    {
        $s = ApprovalEmailVarsBuilder::buildSubject('2605012', 'Acme', 'cs', true, false);
        self::assertStringStartsWith('[TEST] ', $s);
        self::assertStringContainsString('Žádost o schválení', $s);
    }

    public function testTestPrefixEn(): void
    {
        $s = ApprovalEmailVarsBuilder::buildSubject('2605012', 'Acme', 'en', true, false);
        self::assertStringStartsWith('[TEST] Work report', $s);
    }

    public function testReminderCs(): void
    {
        self::assertSame(
            'Připomínka: žádost o schválení výkazu (2605012) — Acme',
            ApprovalEmailVarsBuilder::buildSubject('2605012', 'Acme', 'cs', false, true),
        );
    }

    public function testReminderEn(): void
    {
        self::assertSame(
            'Reminder: please approve work report (2605012) — Acme',
            ApprovalEmailVarsBuilder::buildSubject('2605012', 'Acme', 'en', false, true),
        );
    }

    public function testTestAndReminderTogether(): void
    {
        // Edge case — RequestApprovalTestAction nikdy neposlílá reminder, ale logika
        // se musí skládat čistě (test prefix → reminder text)
        $s = ApprovalEmailVarsBuilder::buildSubject('2605012', 'Acme', 'cs', true, true);
        self::assertStringStartsWith('[TEST] Připomínka:', $s);
    }

    public function testNoSupplierName(): void
    {
        self::assertSame(
            'Žádost o schválení výkazu práce (2605012)',
            ApprovalEmailVarsBuilder::buildSubject('2605012', '', 'cs', false, false),
        );
    }

    public function testDraftIdInsteadOfVarsymbol(): void
    {
        $s = ApprovalEmailVarsBuilder::buildSubject('DRAFT-22', 'Acme', 'cs', false, false);
        self::assertStringContainsString('(DRAFT-22)', $s);
    }
}
