<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Contract;

interface LegacyMigrationModuleRegistry
{
    public function get(string $code): LegacyMigrationModule;

    /** @return list<LegacyMigrationModule> */
    public function all(): array;

    public function has(string $code): bool;
}
