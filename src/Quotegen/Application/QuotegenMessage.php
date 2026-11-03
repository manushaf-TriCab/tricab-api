<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

/**
 * One entry of the conversation. `role` is `user`, `assistant` or `event` (a
 * system note, such as a turn that stopped). `containsPricing` marks text
 * written for someone who may see prices, so a reader without `RFQ.PRICING.VIEW`
 * is shown a placeholder instead.
 */
final readonly class QuotegenMessage
{
    public const HIDDEN_TEXT = 'This message includes pricing details you do not have access to.';

    /**
     * @param list<array{kind: string, name: string, summary: string}> $events
     */
    public function __construct(
        public string $id,
        public string $role,
        public ?string $authorUserId,
        public ?string $authorName,
        public string $text,
        public array $events,
        public ?int $proposedSyncRevision,
        public string $createdAt,
        public bool $containsPricing = false,
    ) {
    }

    /** The message as the reader may see it. */
    public function forReader(bool $mayViewPricing): self
    {
        if ($mayViewPricing || !$this->containsPricing) {
            return $this;
        }

        return new self(
            $this->id,
            $this->role,
            $this->authorUserId,
            $this->authorName,
            self::HIDDEN_TEXT,
            array_map(static fn (array $event): array => [...$event, 'summary' => ''], $this->events),
            $this->proposedSyncRevision,
            $this->createdAt,
            true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'author' => $this->authorUserId === null ? null : ['userId' => $this->authorUserId, 'name' => $this->authorName ?? ''],
            'text' => $this->text,
            'events' => $this->events,
            'proposedSync' => $this->proposedSyncRevision === null ? null : ['revision' => $this->proposedSyncRevision],
            'createdAt' => $this->createdAt,
        ];
    }
}
