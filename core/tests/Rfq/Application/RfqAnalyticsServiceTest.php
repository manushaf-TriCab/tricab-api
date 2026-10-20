<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsDraft;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsEvent;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsReview;
use TriCab\Core\Rfq\Application\DTO\RfqAnalyticsPeriod;
use TriCab\Core\Rfq\Application\Service\RfqAnalyticsService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqAnalyticsSource;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * tc-web's dashboard formulas, one scenario worked by hand. "Now" is
 * 20 Oct 2026 14:00 in Melbourne (03:00 UTC), and the window is the last
 * seven Melbourne days: 14–20 Oct, which is 13 Oct 13:00 UTC to 20 Oct
 * 13:00 UTC. Several times sit either side of a UTC/Melbourne date line on
 * purpose.
 */
final class RfqAnalyticsServiceTest extends TestCase
{
    private const NOW = '2026-10-20T03:00:00Z';

    public function test_one_week_worked_by_hand(): void
    {
        $result = $this->analytics(RfqAnalyticsPeriod::lastDays(7, self::at(self::NOW)));

        self::assertSame(['from' => '2026-10-14', 'to' => '2026-10-20', 'timezone' => 'Australia/Melbourne', 'days' => 7], $result['period']);
        self::assertSame(['from' => '2026-10-07', 'to' => '2026-10-13', 'timezone' => 'Australia/Melbourne', 'days' => 7], $result['previousPeriod']);

        // C ready and overdue, D new, F failed and overdue; E is archived, A/B/G sent.
        self::assertSame(
            ['unfinished' => 3, 'new' => 1, 'inProgress' => 0, 'ready' => 1, 'blocked' => 0, 'failed' => 1, 'unassigned' => 1, 'overdue' => 2, 'overdueRuleHours' => 72],
            $result['current'],
        );

        // B was created at 23:30 Melbourne on the 13th: the previous window, though UTC says the 13th too.
        self::assertSame(['count' => 5, 'previous' => 2], $result['received']);
        // B was sent inside the window; G's synced_at is after it.
        self::assertSame(['count' => 2, 'previous' => 0], $result['sent']);
        self::assertSame(['succeeded' => 1, 'failed' => 1, 'total' => 2, 'successRate' => 0.5], $result['sendAttempts']);

        // A 3 h, C 1 h (the earlier of two events), E 216 h (its first event is after the window).
        self::assertSame(['medianHours' => 3.0, 'p90Hours' => 216.0, 'sample' => 3], $result['firstWork']);
        // A 24 h, B 12 h: nearest rank.
        self::assertSame(['medianHours' => 12.0, 'p90Hours' => 24.0, 'sample' => 2], $result['turnaround']);

        self::assertSame([
            ['date' => '2026-10-14', 'received' => 2, 'sent' => 1],
            ['date' => '2026-10-15', 'received' => 0, 'sent' => 1],
            ['date' => '2026-10-16', 'received' => 1, 'sent' => 0],
            ['date' => '2026-10-17', 'received' => 0, 'sent' => 0],
            ['date' => '2026-10-18', 'received' => 1, 'sent' => 0],
            ['date' => '2026-10-19', 'received' => 1, 'sent' => 0],
            ['date' => '2026-10-20', 'received' => 0, 'sent' => 0],
        ], $result['trend'], 'Melbourne days, zero-filled; C (13 Oct 13:30 UTC) is the 14th');

        self::assertSame([
            ['stage' => 'new', 'count' => 1, 'workflowStatus' => 'new'],
            ['stage' => 'ready_to_sync', 'count' => 1, 'workflowStatus' => 'in_progress'],
            ['stage' => 'synced', 'count' => 2, 'workflowStatus' => 'completed'],
            ['stage' => 'archived', 'count' => 1, 'workflowStatus' => null],
        ], $result['stages']);
        self::assertSame(5, array_sum(array_column($result['stages'], 'count')), 'stages add up to received');

        self::assertSame([
            ['source' => 'alternative', 'channel' => 'web', 'count' => 1],
            ['source' => 'email', 'channel' => 'email', 'count' => 2],
            ['source' => 'web', 'channel' => 'web', 'count' => 2],
        ], $result['sources']);
        self::assertSame([['branch' => 'NSW', 'count' => 1], ['branch' => 'VIC', 'count' => 3], ['branch' => 'none', 'count' => 1]], $result['branches']);

        self::assertSame([
            ['kind' => 'unassigned', 'userId' => null, 'name' => 'Unassigned', 'total' => 2, 'open' => 1, 'ready' => 0, 'overdue' => 0, 'sent' => 0, 'medianTurnaroundHours' => null, 'turnaroundSample' => 0],
            ['kind' => 'person', 'userId' => 7, 'name' => 'Zoe Sales', 'total' => 2, 'open' => 1, 'ready' => 1, 'overdue' => 1, 'sent' => 2, 'medianTurnaroundHours' => 12.0, 'turnaroundSample' => 2],
            ['kind' => 'unmatched', 'userId' => null, 'name' => 'Not a platform user', 'total' => 1, 'open' => 1, 'ready' => 0, 'overdue' => 1, 'sent' => 0, 'medianTurnaroundHours' => null, 'turnaroundSample' => 0],
        ], $result['workload'], 'by total, then name; no email anywhere');
        self::assertStringNotContainsString('@', json_encode($result, JSON_THROW_ON_ERROR));

        $quality = $result['quality'];
        self::assertSame(14, $quality['entries']);
        self::assertSame(11, $quality['unchanged']);
        self::assertSame(3, $quality['changed']);
        self::assertSame(0.7857, $quality['unchangedRate']);
        self::assertSame(2, $quality['reasonsRecorded']);
        self::assertSame(1, $quality['changedWithoutReason']);
        self::assertSame([['tier' => 'high', 'count' => 10], ['tier' => 'medium', 'count' => 2], ['tier' => 'low', 'count' => 1], ['tier' => 'unknown', 'count' => 1]], $quality['confidenceTiers']);
        self::assertSame([['reason' => 'wrong size', 'count' => 2]], $quality['reasons']);
        self::assertSame(2, $quality['reviewSessions'], 'a session with no entries is not a review');
        self::assertSame(1, $quality['reviewedRfqs']);
        self::assertSame(1, $quality['repeatReviewedRfqs']);
        self::assertSame(1, $quality['legacyUnlinkedSessions'], 'all dates, excluded from the rest');
    }

    public function test_only_analytics_readers_get_anything(): void
    {
        $service = new RfqAnalyticsService(new InMemoryRfqAnalyticsSource(), FixedClock::utc(self::NOW));
        $sales = new RfqActor(10, 'u10', Module::ROLE_PERMISSIONS[Module::ROLE_SALES], null);

        self::assertNull($service->analytics($sales, RfqAnalyticsPeriod::lastDays(30, self::at(self::NOW))));
    }

    public function test_an_empty_store_answers_zeros_not_inventions(): void
    {
        $result = (new RfqAnalyticsService(new InMemoryRfqAnalyticsSource(), FixedClock::utc(self::NOW)))
            ->analytics(self::admin(), RfqAnalyticsPeriod::lastDays(30, self::at(self::NOW)));

        self::assertNotNull($result);
        self::assertSame(0, $result['received']['count']);
        self::assertNull($result['turnaround']['medianHours']);
        self::assertNull($result['sendAttempts']['successRate']);
        self::assertNull($result['quality']['unchangedRate']);
        self::assertCount(30, $result['trend']);
        self::assertSame([], $result['workload']);
    }

    public function test_the_period_is_strict(): void
    {
        $now = self::at(self::NOW);
        self::assertSame('2026-09-21', RfqAnalyticsPeriod::fromQueryParams([], $now)->toArray()['from'], 'thirty days by default');
        self::assertSame(90, RfqAnalyticsPeriod::fromQueryParams(['days' => '90'], $now)->days());
        $custom = RfqAnalyticsPeriod::fromQueryParams(['from' => '2026-01-01', 'to' => '2026-12-31'], $now);
        self::assertSame(365, $custom->days());
        self::assertSame('2025-12-31T13:00:00+00:00', $custom->start->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM), 'Melbourne midnight, AEDT');
        self::assertSame(2, RfqAnalyticsPeriod::fromQueryParams(['from' => '2026-10-04', 'to' => '2026-10-05'], $now)->days(), 'a 23-hour daylight-saving day is still one day');

        foreach ([['days' => '14'], ['days' => '30x'], ['from' => '2026-10-01'], ['from' => '2026-02-30', 'to' => '2026-03-01'],
            ['from' => '2026-10-02', 'to' => '2026-10-01'], ['from' => '2025-01-01', 'to' => '2026-10-01'], ['days' => '7', 'from' => '2026-10-01', 'to' => '2026-10-02']] as $bad) {
            try {
                RfqAnalyticsPeriod::fromQueryParams($bad, $now);
                self::fail('Accepted ' . json_encode($bad));
            } catch (InvalidRfqValue) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_percentiles_are_nearest_rank(): void
    {
        self::assertNull(RfqAnalyticsService::percentile([], 0.5));
        self::assertSame(2.0, RfqAnalyticsService::percentile([3.0, 1.0, 2.0], 0.5));
        self::assertSame(10.0, RfqAnalyticsService::percentile(array_map('floatval', range(1, 10)), 0.95));
        self::assertSame(1.33, RfqAnalyticsService::percentile([1.333333], 0.9));
    }

    /** @return array<string, mixed> */
    private function analytics(RfqAnalyticsPeriod $period): array
    {
        $draft = static fn (string $id, string $created, string $status, bool $archived, ?string $synced, ?string $due, string $assignee, string $source, string $branch): AnalyticsDraft
            => new AnalyticsDraft($id, self::at($created), $status, $archived, $synced === null ? null : self::at($synced), $due === null ? null : self::at($due), $assignee, $source, $branch);
        $drafts = [
            $draft('A', '2026-10-14T00:00:00Z', 'synced', false, '2026-10-15T00:00:00Z', null, 'zoe@tricab.com', 'email', 'VIC'),
            $draft('B', '2026-10-13T12:30:00Z', 'synced', false, '2026-10-14T00:30:00Z', null, 'zoe@tricab.com', 'email', 'VIC'),
            $draft('C', '2026-10-13T13:30:00Z', 'ready_to_sync', false, null, '2026-10-16T13:30:00Z', 'zoe@tricab.com', 'web', 'VIC'),
            $draft('D', '2026-10-18T00:00:00Z', 'new', false, null, '2026-10-21T00:00:00Z', '', 'web', ''),
            $draft('E', '2026-10-16T00:00:00Z', 'in_progress', true, null, '2026-10-17T00:00:00Z', 'ghost@tricab.com', 'alternative', 'NSW'),
            $draft('F', '2026-10-10T00:00:00Z', 'failed', false, null, '2026-10-13T00:00:00Z', 'ghost@tricab.com', 'web', 'NSW'),
            $draft('G', '2026-10-19T00:00:00Z', 'synced', false, '2026-10-21T00:00:00Z', null, '', 'email', 'VIC'),
        ];
        $event = static fn (string $draft, string $type, string $at): AnalyticsEvent => new AnalyticsEvent($draft, $type, self::at($at));
        $events = [
            $event('C', AnalyticsEvent::CLAIMED, '2026-10-13T15:30:00Z'),
            $event('C', AnalyticsEvent::CONTENT_UPDATED, '2026-10-13T14:30:00Z'),
            $event('A', AnalyticsEvent::CLAIMED, '2026-10-14T03:00:00Z'),
            $event('E', AnalyticsEvent::WORKFLOW_UPDATED, '2026-10-25T00:00:00Z'),
            $event('A', AnalyticsEvent::SYNC_SUCCEEDED, '2026-10-15T00:00:00Z'),
            $event('A', AnalyticsEvent::SYNC_FAILED, '2026-10-14T23:00:00Z'),
            $event('B', AnalyticsEvent::SYNC_SUCCEEDED, '2026-10-12T00:00:00Z'),
        ];
        $review = static fn (string $id, string $draftId, string $at, bool $legacy, int $entries, int $unchanged, int $reason, array $tiers, array $reasons = []): AnalyticsReview
            => new AnalyticsReview($id, $draftId, self::at($at), $legacy, $entries, $unchanged, $reason, ...[...$tiers, $reasons]);
        $reviews = [
            $review('R1', 'A', '2026-10-15T00:00:00Z', false, 10, 7, 2, [6, 2, 1, 1], ['wrong size' => 2]),
            $review('R2', 'A', '2026-10-16T00:00:00Z', false, 4, 4, 0, [4, 0, 0, 0]),
            $review('R3', 'C', '2026-10-01T00:00:00Z', false, 5, 5, 0, [5, 0, 0, 0]),
            $review('R4', '', '2026-10-15T00:00:00Z', true, 3, 3, 0, [3, 0, 0, 0]),
            $review('R5', 'D', '2026-10-17T00:00:00Z', false, 0, 0, 0, [0, 0, 0, 0]),
        ];
        $source = new InMemoryRfqAnalyticsSource($drafts, $events, $reviews, ['zoe@tricab.com' => ['userId' => 7, 'name' => 'Zoe Sales']]);
        $result = (new RfqAnalyticsService($source, FixedClock::utc(self::NOW)))->analytics(self::admin(), $period);
        self::assertNotNull($result);

        return $result;
    }

    private static function admin(): RfqActor
    {
        return new RfqActor(1, 'u1', Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN], null);
    }

    private static function at(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso);
    }
}
