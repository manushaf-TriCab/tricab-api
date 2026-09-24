<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\Service\PurgeExpiredOtpChallengesService;
use TriCab\Core\Identity\Contract\ExpiredEmailOtpChallengePurger;
use TriCab\Core\Onboarding\Application\Service\ReviewOnboardingRetentionService;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingRetentionRepository;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 5.3 — the two maintenance jobs.
 *
 * Held here: a dry run deletes nothing; the challenge cutoff is seven days and
 * requires expiry as well; deletion is batched to the end; and the retention
 * review keeps everything while every period is NULL, and refuses — never
 * deletes — once one is set.
 */
final class MaintenanceJobsTest extends TestCase
{
    public function test_a_dry_run_counts_challenges_and_deletes_none(): void
    {
        $purger = $this->purger(1200);

        $result = (new PurgeExpiredOtpChallengesService($purger, FixedClock::utc('2026-09-24T00:00:00Z')))->run();

        self::assertTrue($result['dryRun']);
        self::assertSame(1200, $result['eligible']);
        self::assertSame(0, $result['deleted']);
        self::assertSame([], $purger->purgeCalls);
    }

    public function test_the_cutoff_is_seven_days_and_expiry_is_checked_against_now(): void
    {
        $purger = $this->purger(0);

        $result = (new PurgeExpiredOtpChallengesService($purger, FixedClock::utc('2026-09-24T10:30:00Z')))->run();

        self::assertSame('2026-09-17T10:30:00Z', $result['cutoffUtc']);
        self::assertSame('2026-09-17T10:30:00+00:00', $purger->lastCutoff?->format(DATE_ATOM));
        self::assertSame('2026-09-24T10:30:00+00:00', $purger->lastNow?->format(DATE_ATOM));
    }

    public function test_apply_deletes_in_batches_until_none_are_left(): void
    {
        $purger = $this->purger(1200);

        $result = (new PurgeExpiredOtpChallengesService($purger, FixedClock::utc('2026-09-24T00:00:00Z')))->run(false);

        self::assertSame(1200, $result['deleted']);
        self::assertSame([500, 500, 500], $purger->purgeCalls, 'the third batch returns 200 and ends the run');
    }

    public function test_retention_keeps_every_class_while_no_period_is_set(): void
    {
        $result = (new ReviewOnboardingRetentionService(new InMemoryOnboardingRetentionRepository()))->run();

        self::assertSame('keep_all', $result['decision']);
        self::assertCount(6, $result['classes']);
        self::assertSame(['KEEP'], array_values(array_unique(array_column($result['classes'], 'action'))));
    }

    public function test_a_period_being_set_is_a_refusal_not_a_deletion(): void
    {
        $repository = new InMemoryOnboardingRetentionRepository();
        $repository->classes[1]['retentionDays'] = 2555;

        $result = (new ReviewOnboardingRetentionService($repository))->run();

        self::assertSame('refused', $result['decision']);
        self::assertSame('REFUSED_NO_ROUTINE', $result['classes'][1]['action']);
        self::assertStringContainsString('C1', $result['reason']);
        self::assertStringContainsString('Nothing was deleted', $result['reason']);
    }

    private function purger(int $eligible): ExpiredEmailOtpChallengePurger
    {
        return new class ($eligible) implements ExpiredEmailOtpChallengePurger {
            /** @var list<int> */
            public array $purgeCalls = [];
            public ?DateTimeImmutable $lastCutoff = null;
            public ?DateTimeImmutable $lastNow = null;

            public function __construct(private int $remaining)
            {
            }

            public function countPurgeable(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc): int
            {
                $this->lastCutoff = $createdBefore;
                $this->lastNow = $nowUtc;

                return $this->remaining;
            }

            public function purge(DateTimeImmutable $createdBefore, DateTimeImmutable $nowUtc, int $limit): int
            {
                $this->purgeCalls[] = $limit;
                $removed = min($limit, $this->remaining);
                $this->remaining -= $removed;

                return $removed;
            }
        };
    }
}
