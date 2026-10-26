<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

/** `finance.ap2_priorities`: one person's flags and pins. */
interface Ap2PriorityStore
{
    /** @return array<string, array{flag: bool, pin: bool}> keyed `kind:id` */
    public function forUser(int $userId): array;

    /** Sets both marks; a row with neither is removed. */
    public function put(int $userId, string $kind, string $itemId, bool $flag, bool $pin): void;
}
