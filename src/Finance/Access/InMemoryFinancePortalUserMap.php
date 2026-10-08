<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Access;

use TriCab\Core\Finance\Shared\Access\FinancePortalUserMap;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMapping;

/** The map held in memory, with the same two uniqueness rules as the table. */
final class InMemoryFinancePortalUserMap implements FinancePortalUserMap
{
    /** @var array<string, FinancePortalUserMapping> */
    private array $rows = [];

    public function platformUserIdFor(string $portalUserId): ?int
    {
        return isset($this->rows[$portalUserId]) ? $this->rows[$portalUserId]->platformUserId : null;
    }

    public function all(): array
    {
        $rows = $this->rows;
        ksort($rows);

        return $rows;
    }

    public function add(FinancePortalUserMapping $mapping): bool
    {
        if (isset($this->rows[$mapping->portalUserId])) {
            return false;
        }
        foreach ($this->rows as $row) {
            if ($row->platformUserId === $mapping->platformUserId) {
                return false;
            }
        }
        $this->rows[$mapping->portalUserId] = $mapping;

        return true;
    }
}
