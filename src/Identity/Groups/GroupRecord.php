<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** One row of `identity.Groups`. Times are ISO 8601 strings. */
final readonly class GroupRecord
{
    public const SOURCE_ENTRA = 'entra';
    public const SOURCE_PLATFORM = 'platform';

    public function __construct(
        public int $id,
        public string $publicId,
        public string $name,
        public ?string $description,
        public string $source,
        public ?string $entraGroupId,
        public ?string $entraMail,
        public bool $isUnlinked,
        public ?string $unlinkedAt,
        public ?string $lastSyncedAt,
        public ?string $lastSyncOutcome,
        public ?string $lastSyncMessage,
        public string $createdAt,
    ) {
    }

    public function isEntra(): bool
    {
        return $this->source === self::SOURCE_ENTRA;
    }

    /** Members of an Entra-linked group follow Entra; nobody edits them by hand. */
    public function membersAreReadOnly(): bool
    {
        return $this->isEntra();
    }

    public function describedAs(string $name, ?string $description, ?string $entraMail): self
    {
        return new self(
            $this->id, $this->publicId, $name, $description, $this->source, $this->entraGroupId, $entraMail,
            $this->isUnlinked, $this->unlinkedAt, $this->lastSyncedAt, $this->lastSyncOutcome, $this->lastSyncMessage, $this->createdAt,
        );
    }

    public function withLinkState(bool $isUnlinked, ?string $unlinkedAt): self
    {
        return new self(
            $this->id, $this->publicId, $this->name, $this->description, $this->source, $this->entraGroupId, $this->entraMail,
            $isUnlinked, $unlinkedAt, $this->lastSyncedAt, $this->lastSyncOutcome, $this->lastSyncMessage, $this->createdAt,
        );
    }

    public function withSync(?string $lastSyncedAt, ?string $outcome, ?string $message): self
    {
        return new self(
            $this->id, $this->publicId, $this->name, $this->description, $this->source, $this->entraGroupId, $this->entraMail,
            $this->isUnlinked, $this->unlinkedAt, $lastSyncedAt, $outcome, $message, $this->createdAt,
        );
    }
}
