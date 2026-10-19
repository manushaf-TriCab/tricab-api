<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\CableFit;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\NprRequestReader;
use TriCab\Core\Npr\NprRequestSnapshot;

/** In-memory reader: only snapshots that were put in are visible. */
final class FakeNprRequestReader implements NprRequestReader
{
    /** @var array<string, NprRequestSnapshot> */
    private array $snapshots = [];

    public function put(NprRequestSnapshot $snapshot): void
    {
        $this->snapshots[$snapshot->publicId] = $snapshot;
    }

    public function findForTsd(AuthenticatedUser $user, string $requestPublicId): ?NprRequestSnapshot
    {
        return $this->snapshots[$requestPublicId] ?? null;
    }
}
