<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedEntry;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedPage;
use TriCab\Core\Onboarding\Application\Service\ReadCompletionFeedService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingCompletionFeedRepository;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 6 — the completion feed's contract (work order §7): cursor-based and
 * monotonic, idempotent on replay, every read audited, a schema version on
 * every page, and only events old enough that none can still be committing.
 */
final class CompletionFeedTest extends TestCase
{
    private const NOW = '2026-09-25T10:00:00Z';

    private InMemoryOnboardingCompletionFeedRepository $feed;
    private InMemoryOnboardingAdminAuditLog $audit;
    private ReadCompletionFeedService $service;

    protected function setUp(): void
    {
        $this->feed = new InMemoryOnboardingCompletionFeedRepository();
        $this->audit = new InMemoryOnboardingAdminAuditLog();
        $this->service = new ReadCompletionFeedService($this->feed, $this->audit, new FixedClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'))));
        foreach ([12 => '09:00', 5 => '08:00', 30 => '09:30', 31 => '09:59:30'] as $sequence => $at) {
            $this->feed->with(self::entry($sequence, '2026-09-25T' . (strlen($at) === 5 ? $at . ':00' : $at) . 'Z'));
        }
    }

    public function test_pages_follow_the_cursor_in_order_and_say_where_to_resume(): void
    {
        $first = $this->service->read($this->actor(), null, 2);
        self::assertSame(['5', '12'], array_map(static fn (CompletionFeedEntry $e) => $e->cursor(), $first->entries));
        self::assertTrue($first->hasMore);
        self::assertSame('12', $first->nextCursor);

        $second = $this->service->read($this->actor(), $first->nextCursor, 2);
        self::assertSame(['30'], array_map(static fn (CompletionFeedEntry $e) => $e->cursor(), $second->entries));
        self::assertFalse($second->hasMore);

        $empty = $this->service->read($this->actor(), $second->nextCursor, 2);
        self::assertSame([], $empty->entries);
        self::assertSame('30', $empty->nextCursor, 'an empty page hands back the cursor it was asked with');
    }

    public function test_a_replayed_cursor_returns_the_same_events(): void
    {
        $once = $this->service->read($this->actor(), '5', 10)->toArray();
        $again = $this->service->read($this->actor(), '5', 10)->toArray();

        self::assertSame($once, $again);
    }

    public function test_an_event_still_inside_the_settle_window_is_not_served_yet(): void
    {
        $page = $this->service->read($this->actor(), '12', 10);

        self::assertNotContains('31', array_map(static fn (CompletionFeedEntry $e) => $e->cursor(), $page->entries), 'recorded 30 s ago; a lower id may still be committing');
    }

    public function test_every_page_carries_the_schema_version_and_is_audited_without_people(): void
    {
        $page = $this->service->read($this->actor(), null, 100)->toArray();

        self::assertSame(CompletionFeedPage::SCHEMA_VERSION, $page['schemaVersion']);
        self::assertCount(1, $this->audit->entries);
        $event = $this->audit->entries[0]['event'];
        self::assertSame(AdminEventType::COMPLETIONS_READ, $event->eventType);
        self::assertNull($event->subjectUserId);
        self::assertSame('ONBOARDING.COMPLETIONS.READ', $event->permissionCode);
        self::assertSame(['schemaVersion' => 'onboarding.completions.v1', 'cursorFrom' => '0', 'cursorTo' => '30', 'count' => 3], $event->details);
    }

    public function test_the_page_size_is_bounded(): void
    {
        for ($i = 100; $i < 700; ++$i) {
            $this->feed->with(self::entry($i, '2026-09-24T00:00:00Z'));
        }

        self::assertCount(ReadCompletionFeedService::MAX_LIMIT, $this->service->read($this->actor(), null, 10_000)->entries);
        self::assertCount(1, $this->service->read($this->actor(), null, 0)->entries);
        self::assertCount(ReadCompletionFeedService::DEFAULT_LIMIT, $this->service->read($this->actor(), null, null)->entries);
    }

    public function test_a_cursor_this_feed_never_issued_is_refused(): void
    {
        foreach (['-1', 'abc', '1.5', '007', str_repeat('9', 19)] as $cursor) {
            try {
                $this->service->read($this->actor(), $cursor, 10);
                self::fail('accepted cursor ' . $cursor);
            } catch (InvalidOnboardingValue) {
            }
        }
        self::assertSame([], $this->audit->entries, 'a refused read is not a read');
    }

    private static function entry(int $sequence, string $at): CompletionFeedEntry
    {
        return new CompletionFeedEntry(
            $sequence,
            sprintf('eeeeeeee-0000-4000-8000-%012d', $sequence),
            CompletionFeedEntry::TYPE_RECORDED,
            $at,
            'aaaaaaaa-0000-4000-8000-000000000007',
            'training',
            'bbbbbbbb-0000-4000-8000-000000000001',
            'corporate-whse-induction',
            'Corporate and WHSE Induction',
            1,
            sprintf('cccccccc-0000-4000-8000-%012d', $sequence),
        );
    }

    private function actor(): ActorContext
    {
        return new ActorContext(90, 'skill-matrix-public-id', 'skill-matrix@service.invalid');
    }
}
