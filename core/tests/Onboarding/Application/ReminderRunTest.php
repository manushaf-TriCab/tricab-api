<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OnboardingReminderDelivery;
use TriCab\Core\Onboarding\Application\DTO\Reminder\OutstandingReminderItem;
use TriCab\Core\Onboarding\Application\Service\SendOnboardingRemindersService;
use TriCab\Core\Onboarding\Domain\Service\ReminderPolicy;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAccountDirectory;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReminderEmailSender;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReminderRepository;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 5.2 — the reminder job.
 *
 * Held here: one digest per person; a second run the same day sends nothing
 * (A8); a refused digest is retried whole; nobody who cannot sign in is
 * reminded; and every way of misconfiguring delivery ends in no email.
 */
final class ReminderRunTest extends TestCase
{
    private const SAM = 101;
    private const ALEX = 102;

    private InMemoryOnboardingReminderRepository $repository;
    private InMemoryOnboardingAccountDirectory $accounts;
    private InMemoryOnboardingReminderEmailSender $sender;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->repository = new InMemoryOnboardingReminderRepository();
        $this->accounts = new InMemoryOnboardingAccountDirectory();
        $this->sender = new InMemoryOnboardingReminderEmailSender();
        // 22:00 UTC on the 23rd is 08:00 on the 24th in Melbourne.
        $this->clock = FixedClock::utc('2026-09-23T22:00:00Z');

        $this->accounts->withAccount(self::SAM, 'sam@example.test', true);
        $this->accounts->withAccount(self::ALEX, 'alex@example.test', true);
    }

    public function test_one_digest_per_person_listing_every_item_due(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', '2026-09-20');
        $this->item(self::SAM, 'a2', 'Code of Conduct', 'in_progress', null);
        $this->item(self::SAM, 'a3', 'Bank details', 'not_started', '2026-10-30');
        $this->item(self::ALEX, 'a4', 'Leave Policy', 'fix_and_resubmit', '2026-09-25');

        $summary = $this->service()->run();

        self::assertSame(2, $summary->emailsSent);
        self::assertSame(3, $summary->itemsIncluded);
        self::assertSame('2026-09-24', $summary->localDate, 'the date is Melbourne\'s, not UTC\'s');
        $sam = $this->sentTo('sam@example.test');
        self::assertSame(['WHS induction', 'Code of Conduct'], array_map(static fn ($i) => $i->title, $sam['items']));
        self::assertTrue($sam['items'][0]->overdue);
        self::assertFalse($sam['items'][1]->overdue);
        self::assertSame('https://tricab.ai/onboarding', $sam['onboardingUrl']);
    }

    public function test_a_second_run_the_same_day_sends_nothing(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);

        $this->service()->run();
        $second = $this->service()->run();

        self::assertCount(1, $this->sender->sent);
        self::assertSame(0, $second->emailsSent);
        self::assertSame(1, $second->alreadySentToday);
    }

    public function test_the_next_day_reminds_again(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);

        $this->service()->run();
        $this->clock->advance('+1 day');
        $this->service()->run();

        self::assertCount(2, $this->sender->sent);
    }

    public function test_an_item_that_became_due_since_the_morning_run_goes_alone(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);
        $this->service()->run();

        $this->item(self::SAM, 'a2', 'Code of Conduct', 'not_started', '2026-09-24');
        $this->service()->run();

        self::assertCount(2, $this->sender->sent);
        self::assertSame(['Code of Conduct'], array_map(static fn ($i) => $i->title, $this->sender->sent[1]['items']));
    }

    public function test_a_refused_digest_is_marked_failed_and_retried_whole(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);
        $this->item(self::SAM, 'a2', 'Code of Conduct', 'not_started', null);
        $this->sender->refuse = ['sam@example.test'];

        $first = $this->service()->run();

        self::assertSame(0, $first->emailsSent);
        self::assertSame(['user 101 — RuntimeException'], $first->failures);
        self::assertCount(2, $this->repository->rowsWithOutcome('failed'));
        self::assertStringNotContainsString('sam@', implode(' ', $first->failures), 'no address in job output');

        $retry = $this->service()->run();

        self::assertSame(1, $retry->emailsSent);
        self::assertSame(2, $retry->itemsIncluded, 'the whole digest, not a fragment');
        self::assertCount(0, $this->repository->rowsWithOutcome('failed'));
    }

    public function test_one_refusal_does_not_stop_the_others(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);
        $this->item(self::ALEX, 'a2', 'WHS induction', 'not_started', null);
        $this->sender->refuse = ['sam@example.test'];

        $summary = $this->service()->run();

        self::assertSame(1, $summary->emailsSent);
        self::assertSame('alex@example.test', $this->sender->sent[0]['to']);
    }

    public function test_someone_who_cannot_sign_in_is_not_reminded(): void
    {
        $this->accounts->withAccount(self::SAM, 'sam@example.test', false);
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);

        $summary = $this->service()->run();

        self::assertSame(0, $summary->emailsSent);
        self::assertSame([], $this->repository->log, 'nothing claimed for them either');
    }

    public function test_need_help_and_submitted_items_are_left_alone(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'need_help', '2026-09-01');
        $this->item(self::SAM, 'a2', 'Code of Conduct', 'submitted', '2026-09-01');

        self::assertSame(0, $this->service()->run()->emailsSent);
    }

    public function test_the_test_recipient_receives_every_reminder(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);
        $this->item(self::ALEX, 'a2', 'WHS induction', 'not_started', null);

        $summary = $this->service(OnboardingReminderDelivery::decide('on', 'hr-test@tricab.com', false, false))->run();

        self::assertTrue($summary->testRecipientOverride);
        self::assertSame(['hr-test@tricab.com', 'hr-test@tricab.com'], array_column($this->sender->sent, 'to'));
    }

    public function test_switched_off_sends_nothing_and_claims_nothing(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);

        $summary = $this->service(OnboardingReminderDelivery::decide('', '', true, false))->run();

        self::assertFalse($summary->enabled);
        self::assertSame([], $this->sender->sent);
        self::assertSame([], $this->repository->log);
    }

    public function test_a_dry_run_counts_without_claiming_or_sending_even_when_off(): void
    {
        $this->item(self::SAM, 'a1', 'WHS induction', 'not_started', null);
        $this->item(self::ALEX, 'a2', 'Leave Policy', 'not_started', '2026-09-01');

        $summary = $this->service(OnboardingReminderDelivery::disabled())->run(true);

        self::assertTrue($summary->dryRun);
        self::assertSame(2, $summary->people);
        self::assertSame(2, $summary->itemsIncluded);
        self::assertSame([], $this->sender->sent);
        self::assertSame([], $this->repository->log);
    }

    public function test_only_exactly_on_switches_reminders_on(): void
    {
        foreach (['', 'off', 'true', '1', 'yes', 'onn'] as $switch) {
            self::assertFalse(OnboardingReminderDelivery::decide($switch, '', true, false)->enabled, $switch);
        }
        self::assertTrue(OnboardingReminderDelivery::decide(' ON ', '', true, false)->enabled);
    }

    public function test_outside_production_real_people_are_never_emailed(): void
    {
        self::assertFalse(OnboardingReminderDelivery::decide('on', '', false, false)->enabled, 'real provider, no override');
        self::assertTrue(OnboardingReminderDelivery::decide('on', '', false, true)->enabled, 'development provider delivers nothing');
        self::assertTrue(OnboardingReminderDelivery::decide('on', 'hr-test@tricab.com', false, false)->enabled, 'override set');
    }

    private function service(?OnboardingReminderDelivery $delivery = null): SendOnboardingRemindersService
    {
        return new SendOnboardingRemindersService(
            $this->repository,
            $this->accounts,
            $this->sender,
            $delivery ?? OnboardingReminderDelivery::decide('on', '', true, false),
            $this->clock,
            'https://tricab.ai/',
        );
    }

    private function item(int $userId, string $assignment, string $title, string $status, ?string $dueDate): void
    {
        $this->repository->outstanding[] = new OutstandingReminderItem(
            $userId,
            $userId === self::SAM ? 'sam@example.test' : 'alex@example.test',
            $userId === self::SAM ? 'Sam New' : 'Alex Other',
            sprintf('00000000-0000-4000-8000-%012d', crc32($assignment)),
            'training',
            $title,
            $status,
            $dueDate,
        );
    }

    /** @return array{to: string, displayName: string, items: list<\TriCab\Core\Onboarding\Application\DTO\Reminder\ReminderDigestItem>, onboardingUrl: string} */
    private function sentTo(string $address): array
    {
        foreach ($this->sender->sent as $message) {
            if ($message['to'] === $address) {
                return $message;
            }
        }
        self::fail('nothing sent to ' . $address);
    }
}
