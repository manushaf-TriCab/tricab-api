<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

final class MigrationAuditService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $batchPublicId = null): array
    {
        return $this->engine->logs($batchPublicId);
    }
}
