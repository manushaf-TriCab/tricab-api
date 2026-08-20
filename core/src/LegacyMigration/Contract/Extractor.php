<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

use TriCab\Core\LegacyMigration\Support\RawRecord;

interface Extractor
{
    /**
     * Read-only. Must never write to the legacy system.
     *
     * @param array<string, mixed> $filter
     * @return iterable<RawRecord>
     */
    public function extract(array $filter): iterable;

    public function supportsIncremental(): bool;
}
