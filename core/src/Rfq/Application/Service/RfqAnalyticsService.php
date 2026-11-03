<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsDraft;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsEvent;
use TriCab\Core\Rfq\Application\DTO\Analytics\AnalyticsReview;
use TriCab\Core\Rfq\Application\DTO\Analytics\WorkloadTally;
use TriCab\Core\Rfq\Application\DTO\RfqAnalyticsPeriod;
use TriCab\Core\Rfq\Contract\RfqAnalyticsSource;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Shared\Time\Clock;

/**
 * `GET /api/v1/rfqs/analytics` — contract `rfq.analytics.v1`.
 *
 * The formulas are tc-web's (`draft_store.dashboard_metrics`,
 * `log_store.quality_metrics`), ported one for one; the deliberate
 * differences are listed in `platform/plans/RFQ-ANALYTICS-PLAN.md`. Three
 * populations are reported and must not be mixed:
 *
 *  - `current`: everything unfinished right now, with no date filter;
 *  - `received`: RFQs created in the window, archived ones included;
 *  - `sent`: distinct RFQs whose current status is sent and whose `synced_at`
 *    falls in the window — a re-send counts once, and an RFQ sent and since
 *    failed again is not counted. Send *attempts* are activity events.
 *
 * Durations are elapsed hours, never business hours; a negative one (clock
 * skew) counts as zero. Percentiles are nearest-rank, to two decimals.
 * Everyone who holds `RFQ.ANALYTICS.READ` sees every RFQ, so nothing here is
 * per-person.
 */
final class RfqAnalyticsService
{
    /** Stored statuses that are still work to do. */
    public const OPEN_STATUSES = ['new', 'in_progress', 'ready_to_sync', 'blocked', 'failed'];

    /** The order RFQs move through, for the stage breakdown. */
    public const STAGE_ORDER = ['new', 'in_progress', 'ready_to_sync', 'blocked', 'failed', 'synced', 'archived'];

    /** How long after creation an RFQ falls due: tc-web `DEFAULT_DUE_HOURS`, stamped into `due_at`. */
    public const OVERDUE_RULE_HOURS = 72;

    public const CONFIDENCE_HIGH = 0.85;
    public const CONFIDENCE_MEDIUM = 0.6;

    private const TOP_REASONS = 12;
    private const UNASSIGNED = 'unassigned';
    private const UNMATCHED = 'unmatched';

    public function __construct(
        private readonly RfqAnalyticsSource $source,
        private readonly Clock $clock,
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * Null when the actor may not read analytics (the route already refuses).
     *
     * @return array<string, mixed>|null
     */
    public function analytics(RfqActor $actor, RfqAnalyticsPeriod $period): ?array
    {
        if (!$actor->can(Module::PERMISSION_ANALYTICS_READ)) {
            return null;
        }
        $now = $this->clock->now();
        $drafts = $this->source->drafts();
        $events = $this->source->events();
        $period = $period->beginningOn(self::firstCreated($drafts));

        $open = array_values(array_filter($drafts, static fn (AnalyticsDraft $d): bool => self::isOpen($d)));
        $received = self::receivedIn($drafts, $period);
        $sent = self::sentIn($drafts, $period);
        $previous = $period->previous();

        return [
            'period' => $period->toArray(),
            'generatedAt' => $now->format(DATE_ATOM),
            'current' => $this->current($open, $now),
            'received' => ['count' => count($received), 'previous' => $previous === null ? null : count(self::receivedIn($drafts, $previous))],
            'sent' => ['count' => count($sent), 'previous' => $previous === null ? null : count(self::sentIn($drafts, $previous))],
            'previousPeriod' => $previous?->toArray(),
            'sendAttempts' => self::sendAttempts($events, $period),
            'firstWork' => self::summary(self::firstWorkHours($received, $events)),
            'turnaround' => self::summary(array_values(array_filter(array_map(self::turnaroundHours(...), $sent), 'is_float'))),
            'trendInterval' => $period->trendInterval(),
            'trend' => self::trend($received, $sent, $period),
            'stages' => self::stages($received),
            'sources' => self::sources($received),
            'branches' => self::branches($received),
            'workload' => $this->workload($received, $open, $sent, $now),
            'quality' => self::quality($this->source->reviews(), $period),
        ];
    }

    public static function isOpen(AnalyticsDraft $draft): bool
    {
        return !$draft->archived && in_array($draft->status, self::OPEN_STATUSES, true);
    }

    public static function isOverdue(AnalyticsDraft $draft, DateTimeImmutable $now): bool
    {
        return self::isOpen($draft) && $draft->dueAt !== null && $draft->dueAt < $now;
    }

    /**
     * Nearest-rank percentile, rounded to two decimals; null for no values.
     *
     * @param list<float> $values
     */
    public static function percentile(array $values, float $fraction): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $index = max(0, min(count($values) - 1, (int) ceil(count($values) * $fraction) - 1));

        return round($values[$index], 2);
    }

    /**
     * @param list<AnalyticsDraft> $open
     *
     * @return array<string, int>
     */
    private function current(array $open, DateTimeImmutable $now): array
    {
        $count = static fn (string $status): int => count(array_filter($open, static fn (AnalyticsDraft $d): bool => $d->status === $status));

        return [
            // These five partition `unfinished` exactly.
            'unfinished' => count($open),
            'new' => $count('new'),
            'inProgress' => $count('in_progress'),
            'ready' => $count('ready_to_sync'),
            'blocked' => $count('blocked'),
            'failed' => $count('failed'),
            // These two cut across the five; never add them to them.
            'unassigned' => count(array_filter($open, static fn (AnalyticsDraft $d): bool => $d->assigneeEmail === '')),
            'overdue' => count(array_filter($open, static fn (AnalyticsDraft $d): bool => self::isOverdue($d, $now))),
            'overdueRuleHours' => self::OVERDUE_RULE_HOURS,
        ];
    }

    /** @param list<AnalyticsDraft> $drafts */
    private static function firstCreated(array $drafts): ?DateTimeImmutable
    {
        $first = null;
        foreach ($drafts as $draft) {
            if ($draft->createdAt !== null && ($first === null || $draft->createdAt < $first)) {
                $first = $draft->createdAt;
            }
        }

        return $first;
    }

    /**
     * @param list<AnalyticsDraft> $drafts
     *
     * @return list<AnalyticsDraft>
     */
    private static function receivedIn(array $drafts, RfqAnalyticsPeriod $period): array
    {
        return array_values(array_filter($drafts, static fn (AnalyticsDraft $d): bool => $period->contains($d->createdAt)));
    }

    /**
     * @param list<AnalyticsDraft> $drafts
     *
     * @return list<AnalyticsDraft>
     */
    private static function sentIn(array $drafts, RfqAnalyticsPeriod $period): array
    {
        return array_values(array_filter(
            $drafts,
            static fn (AnalyticsDraft $d): bool => $d->status === 'synced' && $period->contains($d->syncedAt),
        ));
    }

    private static function turnaroundHours(AnalyticsDraft $draft): ?float
    {
        return $draft->createdAt !== null && $draft->syncedAt !== null ? self::hours($draft->createdAt, $draft->syncedAt) : null;
    }

    private static function hours(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        $seconds = (float) $to->format('U.u') - (float) $from->format('U.u');

        return max(0.0, $seconds / 3600);
    }

    /**
     * @param list<AnalyticsEvent> $events
     *
     * @return array{succeeded: int, failed: int, total: int, successRate: float|null}
     */
    private static function sendAttempts(array $events, RfqAnalyticsPeriod $period): array
    {
        $succeeded = 0;
        $failed = 0;
        foreach ($events as $event) {
            if (!$period->contains($event->at)) {
                continue;
            }
            $succeeded += (int) ($event->type === AnalyticsEvent::SYNC_SUCCEEDED);
            $failed += (int) ($event->type === AnalyticsEvent::SYNC_FAILED);
        }
        $total = $succeeded + $failed;

        return [
            'succeeded' => $succeeded,
            'failed' => $failed,
            'total' => $total,
            'successRate' => $total > 0 ? round($succeeded / $total, 4) : null,
        ];
    }

    /**
     * Hours from receipt to the first claim or edit, over RFQs received in the
     * window. The first event is looked up with no upper date bound: bounding
     * it drops exactly the slow starts near the end of the window.
     *
     * @param list<AnalyticsDraft> $received
     * @param list<AnalyticsEvent> $events
     *
     * @return list<float>
     */
    private static function firstWorkHours(array $received, array $events): array
    {
        $first = [];
        foreach ($events as $event) {
            if (in_array($event->type, AnalyticsEvent::FIRST_WORK, true)
                && (!isset($first[$event->draftId]) || $event->at < $first[$event->draftId])) {
                $first[$event->draftId] = $event->at;
            }
        }
        $hours = [];
        foreach ($received as $draft) {
            if ($draft->createdAt !== null && isset($first[$draft->id])) {
                $hours[] = self::hours($draft->createdAt, $first[$draft->id]);
            }
        }

        return $hours;
    }

    /**
     * @param list<float> $hours
     *
     * @return array{medianHours: float|null, p90Hours: float|null, sample: int}
     */
    private static function summary(array $hours): array
    {
        return [
            'medianHours' => self::percentile($hours, 0.5),
            'p90Hours' => self::percentile($hours, 0.9),
            'sample' => count($hours),
        ];
    }

    /**
     * Every day in the window (every month for all time), empty ones included,
     * so a quiet fortnight is not drawn as one step.
     *
     * @param list<AnalyticsDraft> $received
     * @param list<AnalyticsDraft> $sent
     *
     * @return list<array{date: string, received: int, sent: int}>
     */
    private static function trend(array $received, array $sent, RfqAnalyticsPeriod $period): array
    {
        $receivedOn = array_fill_keys($period->bucketKeys(), 0);
        $sentOn = $receivedOn;
        foreach ($received as $draft) {
            if ($draft->createdAt !== null) {
                $day = $period->bucket($draft->createdAt);
                $receivedOn[$day] = ($receivedOn[$day] ?? 0) + 1;
            }
        }
        foreach ($sent as $draft) {
            if ($draft->syncedAt !== null) {
                $day = $period->bucket($draft->syncedAt);
                $sentOn[$day] = ($sentOn[$day] ?? 0) + 1;
            }
        }

        $trend = [];
        foreach ($receivedOn as $day => $count) {
            $trend[] = ['date' => (string) $day, 'received' => $count, 'sent' => $sentOn[$day] ?? 0];
        }

        return $trend;
    }

    /**
     * RFQs received in the window by where they sit now, in workflow order; an
     * archived RFQ counts as archived whatever its status. A status the store
     * has never heard of is kept, after the known ones, so the stages always
     * add up to `received`. `workflowStatus` is the queue status the stage
     * belongs to (null for archived), so a stage can filter the queue.
     *
     * @param list<AnalyticsDraft> $received
     *
     * @return list<array{stage: string, count: int, workflowStatus: string|null}>
     */
    private static function stages(array $received): array
    {
        $counts = [];
        foreach ($received as $draft) {
            $stage = $draft->archived ? 'archived' : $draft->status;
            $counts[$stage] = ($counts[$stage] ?? 0) + 1;
        }
        $known = array_values(array_filter(self::STAGE_ORDER, static fn (string $s): bool => isset($counts[$s])));
        $other = array_values(array_diff(array_keys($counts), self::STAGE_ORDER));
        sort($other);

        return array_map(static fn (string $stage): array => [
            'stage' => $stage,
            'count' => $counts[$stage],
            'workflowStatus' => $stage === 'archived' ? null : (WorkflowStatus::SOURCE_STATUS_MAP[$stage] ?? WorkflowStatus::NEW),
        ], [...$known, ...$other]);
    }

    /**
     * @param list<AnalyticsDraft> $received
     *
     * @return list<array{source: string, channel: string, count: int}>
     */
    private static function sources(array $received): array
    {
        $counts = [];
        foreach ($received as $draft) {
            $counts[$draft->source] = ($counts[$draft->source] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        $rows = [];
        foreach ($counts as $source => $count) {
            $rows[] = ['source' => (string) $source, 'channel' => StoreProjection::channel($source)->toString(), 'count' => $count];
        }

        return $rows;
    }

    /**
     * @param list<AnalyticsDraft> $received
     *
     * @return list<array{branch: string, count: int}>
     */
    private static function branches(array $received): array
    {
        $counts = [];
        foreach ($received as $draft) {
            $branch = $draft->branch === '' ? 'none' : $draft->branch;
            $counts[$branch] = ($counts[$branch] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        $rows = [];
        foreach ($counts as $branch => $count) {
            $rows[] = ['branch' => (string) $branch, 'count' => $count];
        }

        return $rows;
    }

    /**
     * One row per person RFQs are assigned to, plus Unassigned, plus one row
     * for the addresses that match no platform user (no email leaves the
     * server). `total` is the RFQs received in the window that the person
     * holds now, whatever has happened to them since — the owner's chosen
     * first column — and the default order.
     *
     * @param list<AnalyticsDraft> $received
     * @param list<AnalyticsDraft> $open
     * @param list<AnalyticsDraft> $sent
     *
     * @return list<array<string, mixed>>
     */
    private function workload(array $received, array $open, array $sent, DateTimeImmutable $now): array
    {
        $emails = [];
        foreach ([...$received, ...$open, ...$sent] as $draft) {
            if ($draft->assigneeEmail !== '') {
                $emails[$draft->assigneeEmail] = true;
            }
        }
        $people = $emails === [] ? [] : $this->source->people(array_map('strval', array_keys($emails)));

        /** @var array<string, WorkloadTally> $tallies */
        $tallies = [];
        $tally = static function (AnalyticsDraft $draft) use (&$tallies, $people): WorkloadTally {
            $email = $draft->assigneeEmail;
            $person = $email === '' ? null : ($people[$email] ?? null);
            $key = $email === '' ? self::UNASSIGNED : ($person === null ? self::UNMATCHED : 'user:' . $person['userId']);

            return $tallies[$key] ??= match (true) {
                $person !== null => new WorkloadTally('person', $person['userId'], $person['name']),
                $email === '' => new WorkloadTally(self::UNASSIGNED, null, 'Unassigned'),
                default => new WorkloadTally(self::UNMATCHED, null, 'Not a platform user'),
            };
        };
        foreach ($received as $draft) {
            $tally($draft)->total++;
        }
        foreach ($open as $draft) {
            $row = $tally($draft);
            $row->open++;
            $row->ready += (int) ($draft->status === 'ready_to_sync');
            $row->overdue += (int) self::isOverdue($draft, $now);
        }
        foreach ($sent as $draft) {
            $row = $tally($draft);
            $row->sent++;
            $hours = self::turnaroundHours($draft);
            if ($hours !== null) {
                $row->turnaround[] = $hours;
            }
        }

        $result = array_map(static fn (WorkloadTally $row): array => [
            'kind' => $row->kind,
            'userId' => $row->userId,
            'name' => $row->name,
            'total' => $row->total,
            'open' => $row->open,
            'ready' => $row->ready,
            'overdue' => $row->overdue,
            'sent' => $row->sent,
            'medianTurnaroundHours' => self::percentile($row->turnaround, 0.5),
            'turnaroundSample' => count($row->turnaround),
        ], array_values($tallies));
        usort($result, static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        return $result;
    }

    /**
     * Review entries in the window: one per line per review, so an RFQ
     * reviewed twice counts its lines twice (`repeatReviewedRfqs` says how
     * often). "Unchanged" compares product code, status and correction reason
     * only; it is not a measure of parsing accuracy.
     *
     * @param list<AnalyticsReview> $reviews
     *
     * @return array<string, mixed>
     */
    private static function quality(array $reviews, RfqAnalyticsPeriod $period): array
    {
        $total = 0;
        $unchanged = 0;
        $withReason = 0;
        $tiers = ['high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0];
        $reasons = [];
        $sessions = 0;
        $reviewed = [];
        $sessionsPerDraft = [];
        $legacy = 0;
        foreach ($reviews as $review) {
            if ($review->legacyUnlinked) {
                $legacy++;
                continue;
            }
            if (!$period->contains($review->at)) {
                continue;
            }
            if ($review->draftId !== '') {
                $sessionsPerDraft[$review->draftId] = ($sessionsPerDraft[$review->draftId] ?? 0) + 1;
            }
            if ($review->entries === 0) {
                continue;
            }
            $sessions++;
            if ($review->draftId !== '') {
                $reviewed[$review->draftId] = true;
            }
            $total += $review->entries;
            $unchanged += $review->unchanged;
            $withReason += $review->withReason;
            $tiers['high'] += $review->highConfidence;
            $tiers['medium'] += $review->mediumConfidence;
            $tiers['low'] += $review->lowConfidence;
            $tiers['unknown'] += $review->unknownConfidence;
            foreach ($review->reasons as $reason => $count) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
            }
        }
        $reasonRows = [];
        foreach ($reasons as $reason => $count) {
            $reasonRows[] = ['reason' => (string) $reason, 'count' => $count];
        }
        usort($reasonRows, static fn (array $a, array $b): int => [$b['count'], $a['reason']] <=> [$a['count'], $b['reason']]);
        $changed = $total - $unchanged;

        return [
            'entries' => $total,
            'unchanged' => $unchanged,
            'changed' => $changed,
            'unchangedRate' => $total > 0 ? round($unchanged / $total, 4) : null,
            'unchangedFields' => ['product code', 'review status', 'correction reason'],
            'confidenceTiers' => array_map(
                static fn (string $tier, int $count): array => ['tier' => $tier, 'count' => $count],
                array_keys($tiers),
                array_values($tiers),
            ),
            'confidenceThresholds' => ['high' => self::CONFIDENCE_HIGH, 'medium' => self::CONFIDENCE_MEDIUM],
            'reasonsRecorded' => $withReason,
            'changedWithoutReason' => max(0, $changed - $withReason),
            'reasons' => array_slice($reasonRows, 0, self::TOP_REASONS),
            'reviewSessions' => $sessions,
            'reviewedRfqs' => count($reviewed),
            'repeatReviewedRfqs' => count(array_filter($sessionsPerDraft, static fn (int $n): bool => $n > 1)),
            // All dates, not this window: a coverage note, not a performance number.
            'legacyUnlinkedSessions' => $legacy,
        ];
    }
}
