<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Service\ReminderPolicy;

/** Phase 5.2 — which items a reminder names, and as what. */
final class ReminderPolicyTest extends TestCase
{
    private const TODAY = '2026-09-24';

    /** @return iterable<string, array{string, ?string, ?string}> */
    public static function cases(): iterable
    {
        yield 'undated, not started' => ['not_started', null, ReminderPolicy::TYPE_UNDATED];
        yield 'due yesterday' => ['in_progress', '2026-09-23', ReminderPolicy::TYPE_OVERDUE];
        yield 'due today is not overdue' => ['in_progress', '2026-09-24', ReminderPolicy::TYPE_DUE_SOON];
        yield 'due at the horizon' => ['not_started', '2026-09-26', ReminderPolicy::TYPE_DUE_SOON];
        yield 'due past the horizon' => ['not_started', '2026-09-27', null];
        yield 'sent back and overdue' => ['fix_and_resubmit', '2026-09-01', ReminderPolicy::TYPE_OVERDUE];
        yield 'need help waits on HR' => ['need_help', '2026-09-01', null];
        yield 'submitted waits on HR' => ['submitted', '2026-09-01', null];
        yield 'completed is never reminded' => ['completed', null, null];
    }

    #[DataProvider('cases')]
    public function test_the_type_for_each_status_and_due_date(string $status, ?string $dueDate, ?string $expected): void
    {
        self::assertSame($expected, ReminderPolicy::typeFor($status, $dueDate, self::TODAY));
    }

    public function test_the_horizon_crosses_a_month_end(): void
    {
        self::assertSame('2026-10-01', ReminderPolicy::horizonDate('2026-09-29'));
    }

    public function test_need_help_is_the_one_worked_on_status_left_out(): void
    {
        self::assertSame(['not_started', 'in_progress', 'fix_and_resubmit'], ReminderPolicy::remindedStatuses());
    }
}
