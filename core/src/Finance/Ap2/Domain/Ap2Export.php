<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/** One saved Odoo CSV batch (`finance.ap2_exports`), kept so it can be downloaded again exactly as it was made. */
final readonly class Ap2Export
{
    /**
     * @param list<string>                      $invoiceIds
     * @param list<string>                      $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public function __construct(
        public string $id,
        public array $invoiceIds,
        public array $headers,
        public array $rows,
        public string $csv,
        public DateTimeImmutable $createdAt,
        public Ap2Person $createdBy,
    ) {
    }
}
