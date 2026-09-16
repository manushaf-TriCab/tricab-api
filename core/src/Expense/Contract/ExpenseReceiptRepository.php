<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use DateTimeImmutable;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

interface ExpenseReceiptRepository
{
    /**
     * Persist a receipt. When $expectedRowVersion is provided and does not match the
     * current row, throw ExpenseConcurrencyConflict.
     *
     * @throws ExpenseConcurrencyConflict
     */
    public function save(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): ExpenseReceipt;

    public function findByPublicId(ExpenseReceiptPublicId $publicId): ?ExpenseReceipt;

    public function findById(int $id): ?ExpenseReceipt;

    /** @return list<ExpenseReceipt> */
    public function listOwnedBy(UserId $ownerUserId, bool $includeDeleted = false): array;

    /** @return list<ExpenseReceipt> */
    public function listPendingForManager(UserId $managerUserId): array;

    /** Unmatched, non-deleted, non-draft receipts for auto-match. */
    /** @return list<ExpenseReceipt> */
    public function listMatchCandidates(): array;

    /**
     * Receipts whose receipt date falls in [start, end] inclusive (date-only).
     *
     * @return list<ExpenseReceipt>
     */
    public function listInReceiptDateRange(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $includeDeleted = false,
    ): array;

    /** @return list<ExpenseReceipt> */
    public function listAll(bool $includeDeleted = false): array;

    public function deletePermanently(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): void;
}
