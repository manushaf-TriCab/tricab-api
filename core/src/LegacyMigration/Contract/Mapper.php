<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

use TriCab\Core\LegacyMigration\Support\NormalizedRecord;

interface Mapper
{
    /**
     * Map a normalized legacy record to a staging payload.
     * Must not write production tables.
     *
     * @return array<string, mixed>
     */
    public function map(NormalizedRecord $record): array;
}
