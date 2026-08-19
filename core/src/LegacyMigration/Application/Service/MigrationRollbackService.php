<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

final class MigrationRollbackService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @return array<string, mixed> */
    public function rollback(ActorContext $actor, string $batchPublicId): array
    {
        return $this->engine->rollback($actor, $batchPublicId);
    }
}
