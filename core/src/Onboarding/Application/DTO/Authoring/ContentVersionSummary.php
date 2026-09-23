<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Authoring;

/**
 * One version in an item's history, without its body.
 *
 * The two counts are what an author needs before ticking "supersedes work in
 * progress": how many people are part-way through this version and would be
 * moved, and how many have finished it and never are.
 */
final readonly class ContentVersionSummary
{
    public const STATE_DRAFT = 'draft';
    public const STATE_LIVE = 'live';
    public const STATE_RETIRED = 'retired';

    public function __construct(
        public string $publicId,
        public int $versionNumber,
        public string $state,
        public ?string $publishedAtUtc,
        public ?string $publishedByName,
        public ?string $retiredAtUtc,
        public bool $supersedesWorkInProgress,
        public int $unfinishedCount,
        public int $completedCount,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'versionNumber' => $this->versionNumber,
            'state' => $this->state,
            'publishedAtUtc' => $this->publishedAtUtc,
            'publishedByName' => $this->publishedByName,
            'retiredAtUtc' => $this->retiredAtUtc,
            'supersedesWorkInProgress' => $this->supersedesWorkInProgress,
            'unfinishedCount' => $this->unfinishedCount,
            'completedCount' => $this->completedCount,
        ];
    }
}
