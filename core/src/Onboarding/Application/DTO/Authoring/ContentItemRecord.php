<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Authoring;

use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * One training module, policy or form definition — the thing that has
 * versions (4.4).
 *
 * The title and summary live here rather than on a version, as the schema has
 * it: they name the item in lists and on every version. Changing them is an
 * ordinary edit, not a new version, and every holder sees the new name at once.
 * A policy's chapter and handbook position are the same kind of thing.
 */
final readonly class ContentItemRecord
{
    public function __construct(
        public int $itemId,
        public string $itemType,
        public string $publicId,
        public string $slug,
        public string $title,
        public ?string $summary,
        /** Forms only: `hr`, `payroll` or `restricted_hr`. Fixed at creation. */
        public ?string $accessGroup,
        public bool $isActive,
        public RowVersionToken $rowVersion,
        /** Policies only: the handbook chapter, e.g. "Working at TriCab". */
        public ?string $chapterTitle = null,
        /** Policies only: position in the handbook, 1 first; null after the rest. */
        public ?int $sortOrder = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'itemType' => $this->itemType,
            'publicId' => $this->publicId,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'accessGroup' => $this->accessGroup,
            'isActive' => $this->isActive,
            'chapterTitle' => $this->chapterTitle,
            'sortOrder' => $this->sortOrder,
            'rowVersion' => $this->rowVersion->toBase64(),
        ];
    }
}
