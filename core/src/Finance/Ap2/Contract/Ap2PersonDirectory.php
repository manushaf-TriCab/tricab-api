<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/** Platform users who may be an owner: active people holding `FINANCE.AP2.ACCESS` through an active role. Read only, from `[identity]`. */
interface Ap2PersonDirectory
{
    public function find(int $userId): ?Ap2Person;

    /**
     * Active users holding the platform permission `$permission` (a full code such as `FINANCE.AP2.INVOICES.WRITE`)
     * and `FINANCE.AP2.ACCESS`, through an active, unexpired role.
     *
     * @return list<Ap2Person> by name, then id
     */
    public function holding(string $permission): array;
}
