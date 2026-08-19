<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

final class MigrationReportingService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @return array<string, mixed> */
    public function generate(string $batchPublicId, string $type, string $format = 'json'): array
    {
        return $this->engine->report($batchPublicId, $type, $format);
    }
}
