<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Doubles;

use DateTimeImmutable;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Reads pass through; every optimistic-locked save reports a conflict.
 *
 * Stands in for the race the in-memory repository cannot stage on its own:
 * another writer bumping the row between this request's read and its save,
 * which Azure SQL surfaces as a rowversion mismatch.
 */
final class ConflictingExpenseReceiptRepository implements ExpenseReceiptRepository
{
    public function __construct(private readonly ExpenseReceiptRepository $inner)
    {
    }

    public function save(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): ExpenseReceipt
    {
        if ($expectedRowVersion !== null) {
            throw ExpenseConcurrencyConflict::forReceipt();
        }

        return $this->inner->save($receipt);
    }

    public function findByPublicId(ExpenseReceiptPublicId $publicId): ?ExpenseReceipt
    {
        return $this->inner->findByPublicId($publicId);
    }

    public function findById(int $id): ?ExpenseReceipt
    {
        return $this->inner->findById($id);
    }

    public function listOwnedBy(UserId $ownerUserId, bool $includeDeleted = false): array
    {
        return $this->inner->listOwnedBy($ownerUserId, $includeDeleted);
    }

    public function listPendingForManager(UserId $managerUserId): array
    {
        return $this->inner->listPendingForManager($managerUserId);
    }

    public function listMatchCandidates(): array
    {
        return $this->inner->listMatchCandidates();
    }

    public function listInReceiptDateRange(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $includeDeleted = false,
    ): array {
        return $this->inner->listInReceiptDateRange($start, $end, $includeDeleted);
    }

    public function listAll(bool $includeDeleted = false): array
    {
        return $this->inner->listAll($includeDeleted);
    }

    public function deletePermanently(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): void
    {
        throw ExpenseConcurrencyConflict::forReceipt();
    }
}
