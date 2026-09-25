<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Authoring;

use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * One version with its authored body — answer key included, author's eyes
 * only (see {@see AuthoredContent}).
 */
final readonly class ContentVersionRecord
{
    public function __construct(
        public int $versionId,
        public string $publicId,
        public int $versionNumber,
        public string $state,
        public RowVersionToken $rowVersion,
        public ?string $publishedAtUtc,
        public bool $supersedesWorkInProgress,
        public AuthoredContent $content,
    ) {
    }

    public function isDraft(): bool
    {
        return $this->state === ContentVersionSummary::STATE_DRAFT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?string $accessGroup): array
    {
        return [
            'publicId' => $this->publicId,
            'versionNumber' => $this->versionNumber,
            'state' => $this->state,
            'rowVersion' => $this->rowVersion->toBase64(),
            'publishedAtUtc' => $this->publishedAtUtc,
            'supersedesWorkInProgress' => $this->supersedesWorkInProgress,
            'content' => $this->content->toArray(),
            // Shown on a draft so the author sees what is missing before
            // pressing Publish rather than after.
            'publishProblems' => $this->isDraft() ? $this->content->publishProblems($accessGroup) : [],
        ];
    }
}
