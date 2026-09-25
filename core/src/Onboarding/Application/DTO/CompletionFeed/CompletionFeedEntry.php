<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\CompletionFeed;

/**
 * One completion event on the feed (Phase 6) — architecture boundary B7:
 * person id, item id and type, when, and an evidence reference. Nothing else.
 *
 * No score, answer, form value, note, email address or document URL can be
 * carried, because there is no field for one. {@see toArray()} is the wire
 * shape, and `OnboardingCompletionFeedEndpointsTest` holds it to an allow-list.
 */
final readonly class CompletionFeedEntry
{
    public const TYPE_RECORDED = 'completion.recorded';
    public const TYPE_REOPENED = 'completion.reopened';

    public function __construct(
        /** The status event's own id: monotonic, and the cursor. */
        public int $sequence,
        /** The status event's public id — stable, so a consumer can de-duplicate a replay by it. */
        public string $eventPublicId,
        public string $type,
        public string $occurredAtUtc,
        /** `identity.Users.PublicId` — the platform's stable person id, never an email or a name. */
        public string $userPublicId,
        public string $itemType,
        public string $itemPublicId,
        public string $itemSlug,
        public string $itemTitle,
        public int $versionNumber,
        public string $assignmentPublicId,
    ) {
    }

    public function cursor(): string
    {
        return (string) $this->sequence;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eventId' => $this->eventPublicId,
            'cursor' => $this->cursor(),
            'type' => $this->type,
            'occurredAtUtc' => $this->occurredAtUtc,
            'person' => ['userPublicId' => $this->userPublicId],
            'item' => [
                'type' => $this->itemType,
                'publicId' => $this->itemPublicId,
                'slug' => $this->itemSlug,
                'title' => $this->itemTitle,
                'versionNumber' => $this->versionNumber,
            ],
            // A reference HR can resolve on the platform, never a URL: the
            // documents behind it stay behind the platform's audited routes.
            'evidenceReference' => 'onboarding:assignment:' . $this->assignmentPublicId,
        ];
    }
}
