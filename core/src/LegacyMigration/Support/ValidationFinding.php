<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

final readonly class ValidationFinding
{
    public function __construct(
        public string $severity,
        public string $errorCode,
        public string $description,
        public ?string $sourceIdentifier,
        public string $recommendation,
        public bool $isConflict = false,
    ) {
    }
}
