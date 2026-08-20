<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

/**
 * Optional live source probe. Return null to keep the engine's default (no connection).
 */
interface LegacySourceHealthProbe
{
    /** @return array<string, mixed>|null */
    public function probe(string $code): ?array;
}
