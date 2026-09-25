<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\CompletionFeed;

/**
 * A page of the completion feed. `nextCursor` is the cursor to send next time
 * — the last entry's, or the one asked with when the page is empty — so a
 * consumer that stores it after processing a page never skips or repeats one.
 */
final readonly class CompletionFeedPage
{
    public const SCHEMA_VERSION = 'onboarding.completions.v1';

    /**
     * @param list<CompletionFeedEntry> $entries
     */
    public function __construct(
        public array $entries,
        public string $nextCursor,
        public bool $hasMore,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'items' => array_map(static fn (CompletionFeedEntry $e): array => $e->toArray(), $this->entries),
            'nextCursor' => $this->nextCursor,
            'hasMore' => $this->hasMore,
        ];
    }
}
