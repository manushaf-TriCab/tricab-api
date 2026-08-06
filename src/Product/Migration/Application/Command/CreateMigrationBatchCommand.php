<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Command;

final class CreateMigrationBatchCommand
{
    /**
     * @param array<string, mixed>|null $sourceFilter
     */
    public function __construct(
        public readonly string $name,
        public readonly string $sourceSystem,
        public readonly ?string $description = null,
        public readonly ?array $sourceFilter = null,
        public readonly bool $dryRun = true,
    ) {
    }
}
