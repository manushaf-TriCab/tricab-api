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
            'rowVersion' => $this->rowVersion->toBase64(),
        ];
    }
}
