<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One row of `finance.ap2_statements`: what a supplier says it is owed. Reviewing it never changes the ledger. */
final readonly class Ap2Statement
{
    public const STATUSES = ['Unreviewed', 'Matched', 'Discrepancy'];

    public function __construct(
        public string $id,
        public string $supplierId,
        public string $date,
        public float $amount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $currency = 'AUD',
        public string $reference = '',
        public string $status = 'Unreviewed',
        public string $evidence = '',
        public string $notes = '',
        public ?Ap2Person $reviewedBy = null,
        public ?DateTimeImmutable $reviewedAt = null,
        public ?string $mailId = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
