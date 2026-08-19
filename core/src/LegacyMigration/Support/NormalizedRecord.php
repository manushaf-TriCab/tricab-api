<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final readonly class NormalizedRecord
{
    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public string $sourceIdentifier,
        public string $moduleCode,
        public array $canonical,
        public array $rawPayload,
        public string $sourceHash,
    ) {
    }
}
