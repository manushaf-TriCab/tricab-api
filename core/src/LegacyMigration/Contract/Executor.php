<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

use TriCab\Core\LegacyMigration\Support\NormalizedRecord;

interface Executor
{
    /**
     * Apply a mapped record. Framework default writes snapshots only.
     * Module implementations must still refuse production writes until explicitly built.
     *
     * @param array<string, mixed> $mapped
     * @return array{action: string, target: string, snapshot: array<string, mixed>}
     */
    public function execute(NormalizedRecord $record, array $mapped): array;

    public function writesProductionTables(): bool;
}
