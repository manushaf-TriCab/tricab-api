<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Mail;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;

/** The ledger held in memory, with the same per-app uniqueness as the filtered index. */
final class InMemoryFinanceMailLedger implements FinanceMailLedger
{
    /** @var array<string, FinanceMailDelivery> keyed by delivery id, in insertion order */
    private array $rows = [];

    public function findByIdempotencyKey(string $application, string $key): ?FinanceMailDelivery
    {
        foreach ($this->rows as $row) {
            if ($row->application === $application && $row->idempotencyKey === $key) {
                return $row;
            }
        }

        return null;
    }

    public function findById(string $id): ?FinanceMailDelivery
    {
        return $this->rows[$id] ?? null;
    }

    public function recordPending(FinanceMailDelivery $delivery): bool
    {
        if ($delivery->idempotencyKey !== null && $this->findByIdempotencyKey($delivery->application, $delivery->idempotencyKey) !== null) {
            return false;
        }
        $this->rows[$delivery->id] = $delivery;

        return true;
    }

    public function markSent(string $id, DateTimeImmutable $at): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = $this->rows[$id]->withSent($at);
        }
    }

    public function markFailed(string $id, string $error): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = $this->rows[$id]->withFailed($error);
        }
    }

    public function markSuppressed(string $id, string $reason): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id] = $this->rows[$id]->withSuppressed($reason);
        }
    }

    public function reopenSuppressed(FinanceMailDelivery $delivery): bool
    {
        if (!isset($this->rows[$delivery->id]) || !$this->rows[$delivery->id]->isSuppressed()) {
            return false;
        }
        $this->rows[$delivery->id] = $delivery;

        return true;
    }

    /** @return list<FinanceMailDelivery> */
    public function all(): array
    {
        return array_values($this->rows);
    }
}
