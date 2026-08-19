<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

final class MigrationStatisticsService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return $this->engine->overview();
    }

    /** @return array<string, mixed> */
    public function statistics(): array
    {
        return $this->engine->statistics();
    }
}
