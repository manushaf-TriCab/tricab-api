<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;

/**
 * The companies, cost centres and account categories (`invoice_companies`, `invoice_cost_centres`,
 * `invoice_account_categories`). Storage only: validation and messages are `ApReferenceService`'s.
 */
interface ApReferenceStore
{
    /**
     * Rows ordered on a column of the spec's `sortable()` (the caller has checked it), then by id.
     *
     * @return list<ApReferenceRow>
     */
    public function rows(ApReferenceSpec $spec, string $sortColumn, bool $descending, int $limit): array;

    public function find(ApReferenceSpec $spec, string $id): ?ApReferenceRow;

    /** @throws \TriCab\Core\Finance\Ap\Domain\ApConflict when the unique column already holds this value */
    public function insert(ApReferenceRow $row): void;

    /**
     * Writes every text column and the flag of an existing row.
     *
     * @throws \TriCab\Core\Finance\Ap\Domain\ApConflict as `insert`
     */
    public function update(ApReferenceRow $row): void;

    public function delete(ApReferenceSpec $spec, string $id): void;
}
