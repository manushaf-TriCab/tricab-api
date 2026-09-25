<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Authoring;

/**
 * One row of the content list. No version body — no slides, no wording, and
 * no answer key: a list view is where an accidental leak is cheapest to make.
 */
final readonly class ContentItemSummary
{
    public function __construct(
        public string $itemType,
        public string $publicId,
        public string $slug,
        public string $title,
        public ?string $accessGroup,
        public bool $isActive,
        public ?int $liveVersionNumber,
        public ?string $draftVersionPublicId,
        public ?int $draftVersionNumber,
        /** People who hold this item now, on any version, not withdrawn. */
        public int $holderCount,
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
            'accessGroup' => $this->accessGroup,
            'isActive' => $this->isActive,
            'liveVersionNumber' => $this->liveVersionNumber,
            'draftVersionPublicId' => $this->draftVersionPublicId,
            'draftVersionNumber' => $this->draftVersionNumber,
            'holderCount' => $this->holderCount,
        ];
    }
}
