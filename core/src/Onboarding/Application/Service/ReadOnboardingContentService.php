<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Onboarding\Application\DTO\Authoring\AuthoredContent;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemDetail;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemSummary;
use TriCab\Core\Onboarding\Application\DTO\Authoring\ContentVersionRecord;
use TriCab\Core\Onboarding\Contract\OnboardingContentRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingContentNotFound;

/**
 * The author's reads (4.4): the content list, one item's history, one
 * version's body.
 *
 * Only the last carries an answer key, and only because its caller holds
 * `ONBOARDING.CONTENT.MANAGE` — the route gate, not anything here, is what
 * keeps it from anybody else.
 */
final readonly class ReadOnboardingContentService
{
    public function __construct(
        private OnboardingContentRepository $content,
    ) {
    }

    /**
     * @return list<ContentItemSummary>
     */
    public function list(): array
    {
        return $this->content->listItems();
    }

    public function item(string $itemType, string $itemPublicId): ContentItemDetail
    {
        $item = $this->requireItem($itemType, $itemPublicId);

        return new ContentItemDetail($item, $this->content->listVersions($item));
    }

    /**
     * @return array{item: ContentItemRecord, version: ContentVersionRecord}
     */
    public function version(string $itemType, string $itemPublicId, string $versionPublicId): array
    {
        $item = $this->requireItem($itemType, $itemPublicId);
        $version = $this->content->findVersion($item, strtolower(trim($versionPublicId)))
            ?? throw OnboardingContentNotFound::version($versionPublicId);

        return ['item' => $item, 'version' => $version];
    }

    public function requireItem(string $itemType, string $itemPublicId): ContentItemRecord
    {
        if (!in_array($itemType, AuthoredContent::TYPES, true)) {
            throw OnboardingContentNotFound::item($itemPublicId);
        }

        return $this->content->findItem($itemType, strtolower(trim($itemPublicId)))
            ?? throw OnboardingContentNotFound::item($itemPublicId);
    }
}
