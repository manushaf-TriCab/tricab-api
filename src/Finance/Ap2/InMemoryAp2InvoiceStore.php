<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2PoForecastRow;

/** The invoices held in memory: the tests', and a database-less run's. */
final class InMemoryAp2InvoiceStore implements Ap2InvoiceStore
{
    /** @var array<string, Ap2Invoice> */
    private array $rows = [];

    /** @var array<string, true> */
    private array $activePayments = [];

    /** @var list<Ap2PoForecastRow> */
    private array $forecast = [];

    public function __construct(private readonly Ap2PaymentStore $payments)
    {
    }

    public function all(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (Ap2Invoice $a, Ap2Invoice $b): int => [$a->due ?? '', $a->number, $a->id] <=> [$b->due ?? '', $b->number, $b->id]);

        return $rows;
    }

    public function forSupplier(string $supplierId): array
    {
        return array_values(array_filter($this->all(), static fn (Ap2Invoice $b): bool => $b->supplierId === $supplierId));
    }

    public function find(string $id): ?Ap2Invoice
    {
        return $this->rows[$id] ?? null;
    }

    public function insert(Ap2Invoice $invoice): void
    {
        $this->rows[$invoice->id] = $invoice;
    }

    public function update(Ap2Invoice $invoice): void
    {
        $this->rows[$invoice->id] = $invoice;
    }

    public function countCreated(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int
    {
        return count(array_filter($this->rows, static fn (Ap2Invoice $b): bool => $b->createdAt >= $from && $b->createdAt < $toExclusive));
    }

    public function hasActivePayment(string $invoiceId): bool
    {
        return isset($this->invoiceIdsWithActivePayment()[$invoiceId]);
    }

    public function invoiceIdsWithActivePayment(): array
    {
        $active = $this->activePayments;
        foreach ($this->payments->all() as $p) {
            if ($p->isActive()) {
                $active[$p->invoiceId] = true;
            }
        }

        return $active;
    }

    public function poForecast(): array
    {
        return $this->forecast;
    }

    /** Test seam: a live payment proposal exists for the invoice. */
    public function withActivePayment(string $invoiceId): self
    {
        $this->activePayments[$invoiceId] = true;

        return $this;
    }

    public function withPoForecast(Ap2PoForecastRow $row): self
    {
        $this->forecast[] = $row;

        return $this;
    }
}
