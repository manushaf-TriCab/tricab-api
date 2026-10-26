<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;

/** The payment proposals held in memory. */
final class InMemoryAp2PaymentStore implements Ap2PaymentStore
{
    /** @var array<string, Ap2Payment> */
    private array $rows = [];

    public function all(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (Ap2Payment $a, Ap2Payment $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $rows;
    }

    public function find(string $id): ?Ap2Payment
    {
        return $this->rows[$id] ?? null;
    }

    public function insert(Ap2Payment $payment): void
    {
        $this->rows[$payment->id] = $payment;
    }

    public function update(Ap2Payment $payment): void
    {
        $this->rows[$payment->id] = $payment;
    }
}
