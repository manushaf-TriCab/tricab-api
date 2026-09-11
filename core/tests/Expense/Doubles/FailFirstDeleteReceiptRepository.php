<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Doubles;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/** Fails the first permanent delete, then behaves normally. */
final class FailFirstDeleteReceiptRepository implements ExpenseReceiptRepository
{
    private bool $failed = false;

    public function __construct(private readonly ExpenseReceiptRepository $inner)
    {
    }

    public function deletePermanently(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): void
    {
        if (!$this->failed) {
            $this->failed = true;

            throw new RuntimeException('Storage account is throttling deletes.');
        }

        $this->inner->deletePermanently($receipt, $expectedRowVersion);
    }

    public function save(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): ExpenseReceipt
    {
        return $this->inner->save($receipt, $expectedRowVersion);
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
}
