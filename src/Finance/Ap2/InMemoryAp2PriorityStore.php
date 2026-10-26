<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2PriorityStore;

/** Flags and pins held in memory. */
final class InMemoryAp2PriorityStore implements Ap2PriorityStore
{
    /** @var array<int, array<string, array{flag: bool, pin: bool}>> */
    private array $rows = [];

    public function forUser(int $userId): array
    {
        return $this->rows[$userId] ?? [];
    }

    public function put(int $userId, string $kind, string $itemId, bool $flag, bool $pin): void
    {
        $key = $kind . ':' . $itemId;
        if ($flag || $pin) {
            $this->rows[$userId][$key] = ['flag' => $flag, 'pin' => $pin];
        } else {
            unset($this->rows[$userId][$key]);
        }
    }
}
