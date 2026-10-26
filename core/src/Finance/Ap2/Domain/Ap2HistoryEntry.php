<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One row of the supplier activity ledger, `finance.ap2_history`. Append only: there is no way to change one. */
final readonly class Ap2HistoryEntry
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $id,
        public string $supplierId,
        public string $type,
        public string $detail,
        public Ap2Person $actor,
        public DateTimeImmutable $at,
        public ?string $invoiceId = null,
        public ?string $mailId = null,
        public array $extra = [],
    ) {
    }
}
