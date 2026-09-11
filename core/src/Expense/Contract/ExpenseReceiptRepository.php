<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

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

    /** @return list<ExpenseReceipt> */
    public function listOwnedBy(UserId $ownerUserId, bool $includeDeleted = false): array;

    /** @return list<ExpenseReceipt> */
    public function listPendingForManager(UserId $managerUserId): array;

    public function deletePermanently(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): void;
}