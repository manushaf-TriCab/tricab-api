<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Authoring;

/** An item and its version history — still no version body. */
final readonly class ContentItemDetail
{
    /**
     * @param list<ContentVersionSummary> $versions newest first
     */
    public function __construct(
        public ContentItemRecord $item,
        public array $versions,
    ) {
    }

    public function hasDraft(): bool
    {
        foreach ($this->versions as $version) {
            if ($version->state === ContentVersionSummary::STATE_DRAFT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item' => $this->item->toArray(),
            'versions' => array_map(static fn (ContentVersionSummary $version): array => $version->toArray(), $this->versions),
            'hasDraft' => $this->hasDraft(),
        ];
    }
}
