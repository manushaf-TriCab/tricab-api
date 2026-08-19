<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final readonly class RawRecord
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $sourceSystem,
        public string $sourceTable,
        public string $sourceIdentifier,
        public array $payload,
        public string $recordType,
        public ?string $parentSourceIdentifier = null,
    ) {
    }

    public function sourceHash(): string
    {
        return hash('sha256', json_encode($this->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
