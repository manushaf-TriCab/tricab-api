<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;

final class MigrationBatchService
{
    public function __construct(private readonly LegacyMigrationEngine $engine)
    {
    }

    /** @param array<string, mixed>|null $filter */
    public function create(ActorContext $actor, string $name, string $moduleCode, ?string $description = null, ?array $filter = null): array
    {
        return $this->engine->createBatch($actor, $name, $moduleCode, $description, $filter);
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $statusCode = null, ?string $moduleCode = null): array
    {
        return $this->engine->listBatches($statusCode, $moduleCode);
    }

    /** @return array<string, mixed> */
    public function get(string $publicId): array
    {
        return $this->engine->getBatch($publicId);
    }

    /** @return array<string, mixed> */
    public function extract(ActorContext $actor, string $publicId, bool $incremental = false): array
    {
        return $this->engine->extract($actor, $publicId, $incremental);
    }

    /** @return array<string, mixed> */
    public function normalize(ActorContext $actor, string $publicId): array
    {
        return $this->engine->normalize($actor, $publicId);
    }

    /** @return array<string, mixed> */
    public function preview(ActorContext $actor, string $publicId): array
    {
        return $this->engine->preview($actor, $publicId);
    }

    /** @return array<string, mixed> */
    public function approve(ActorContext $actor, string $publicId): array
    {
        return $this->engine->approve($actor, $publicId);
    }

    /** @return array<string, mixed> */
    public function cancel(ActorContext $actor, string $publicId): array
    {
        return $this->engine->cancel($actor, $publicId);
    }

    /** @return array<string, mixed> */
    public function retry(ActorContext $actor, string $publicId): array
    {
        return $this->engine->retry($actor, $publicId);
    }
}
