<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

final class MigrationConflictService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $batchPublicId = null): array
    {
        return $this->engine->conflicts($batchPublicId);
    }

    /** @return array<string, mixed> */
    public function resolve(ActorContext $actor, string $conflictPublicId, string $decision, ?string $mapping = null): array
    {
        return $this->engine->resolveConflict($actor, $conflictPublicId, $decision, $mapping);
    }
}
