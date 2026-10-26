<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;

/** `finance.ap2_payments`. */
interface Ap2PaymentStore
{
    /** @return list<Ap2Payment> newest first */
    public function all(): array;

    public function find(string $id): ?Ap2Payment;

    public function insert(Ap2Payment $payment): void;

    public function update(Ap2Payment $payment): void;
}
