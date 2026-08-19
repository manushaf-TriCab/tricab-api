<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

final class MigrationSourceService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->engine->sources();
    }

    /** @return array<string, mixed> */
    public function health(string $code): array
    {
        return $this->engine->sourceHealth($code);
    }
}
