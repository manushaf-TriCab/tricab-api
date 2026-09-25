<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use DateInterval;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\CompletionFeed\CompletionFeedPage;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingCompletionFeedRepository;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Module;
use TriCab\Core\Shared\Time\Clock;

/**
 * The completion feed Skill Matrix polls (Phase 6, work order §7). Defined and
 * tested; not deployed, and nothing connects to it.
 *
 * ## Cursor, replay and the settle window
 *
 * The cursor is the status event's identity value, so reading is monotonic and
 * a replay of the same cursor returns the same events — the consumer
 * de-duplicates by `eventId` if it processes a page twice. An identity value is
 * handed out at insert and becomes visible at commit, so a lower one can appear
 * after a higher one has been read; only events older than
 * {@see SETTLE_SECONDS} are served, which is far longer than any status write's
 * transaction, so none is skipped.
 *
 * ## Every read is audited
 *
 * One `completions.read` row per page, with the range and the count — not the
 * people, who are in the events themselves. Refusals are the middleware's.
 */
final readonly class ReadCompletionFeedService
{
    public const SETTLE_SECONDS = 60;
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 500;

    public function __construct(
        private OnboardingCompletionFeedRepository $feed,
        private OnboardingAdminAuditLog $audit,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidOnboardingValue for a cursor that is not one this feed issued
     */
    public function read(ActorContext $actor, ?string $cursor, ?int $limit): CompletionFeedPage
    {
        $after = self::parseCursor($cursor);
        $size = max(1, min(self::MAX_LIMIT, $limit ?? self::DEFAULT_LIMIT));
        $settledBefore = $this->clock->now()->sub(new DateInterval('PT' . self::SETTLE_SECONDS . 'S'));

        $entries = $this->feed->after($after, $size + 1, $settledBefore);
        $hasMore = count($entries) > $size;
        $entries = array_slice($entries, 0, $size);
        $next = $entries === [] ? (string) $after : $entries[array_key_last($entries)]->cursor();

        $this->audit->append($actor, OnboardingAdminEvent::succeeded(
            AdminEventType::COMPLETIONS_READ,
            null,
            null,
            sprintf('Read %d completion event(s) after cursor %d.', count($entries), $after),
            [
                'schemaVersion' => CompletionFeedPage::SCHEMA_VERSION,
                'cursorFrom' => (string) $after,
                'cursorTo' => $next,
                'count' => count($entries),
            ],
            Module::PERMISSION_COMPLETIONS_READ,
        ));

        return new CompletionFeedPage($entries, $next, $hasMore);
    }

    private static function parseCursor(?string $cursor): int
    {
        $value = trim($cursor ?? '');
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^(0|[1-9]\d{0,17})$/', $value) !== 1) {
            throw InvalidOnboardingValue::for('cursor', $value);
        }

        return (int) $value;
    }
}
