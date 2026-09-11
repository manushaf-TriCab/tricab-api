<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseReceipt;
use TriCab\Core\Expense\Domain\Exception\ExpenseConcurrencyConflict;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class InMemoryExpenseReceiptRepository implements ExpenseReceiptRepository
{
    /** @var array<string, ExpenseReceipt> */
    private array $byPublicId = [];

    private int $nextId = 1;

    private int $rowSeq = 1;

    public function save(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): ExpenseReceipt
    {
        $key = $receipt->publicId()->toString();
        $existing = $this->byPublicId[$key] ?? null;

        if ($expectedRowVersion !== null) {
            $current = $existing?->rowVersion();
            if ($current === null || !$current->equals($expectedRowVersion)) {
                throw ExpenseConcurrencyConflict::forReceipt();
            }
        }

        $saved = $receipt->id() === null ? $receipt->withId(ExpenseReceiptId::fromInt($this->nextId++)) : $receipt;
        $saved = $saved->withRowVersion($this->nextRowVersion());
        $this->byPublicId[$saved->publicId()->toString()] = $saved;

        return $saved;
    }

    public function findByPublicId(ExpenseReceiptPublicId $publicId): ?ExpenseReceipt
    {
        return $this->byPublicId[$publicId->toString()] ?? null;
    }

    public function listOwnedBy(UserId $ownerUserId, bool $includeDeleted = false): array
    {
        $out = [];
        foreach ($this->byPublicId as $receipt) {
            if (!$receipt->ownerUserId()->equals($ownerUserId)) {
                continue;
            }
            if (!$includeDeleted && $receipt->isDeleted()) {
                continue;
            }
            $out[] = $receipt;
        }

        return $out;
    }

    public function listPendingForManager(UserId $managerUserId): array
    {
        $out = [];
        foreach ($this->byPublicId as $receipt) {
            if ($receipt->isDeleted()) {
                continue;
            }
            if (!$receipt->status()->isPending()) {
                continue;
            }
            if ($receipt->managerUserId() === null || !$receipt->managerUserId()->equals($managerUserId)) {
                continue;
            }
            $out[] = $receipt;
        }

        return $out;
    }

    public function deletePermanently(ExpenseReceipt $receipt, ?RowVersionToken $expectedRowVersion = null): void
    {
        $key = $receipt->publicId()->toString();
        $existing = $this->byPublicId[$key] ?? null;
        if ($existing === null) {
            throw ExpenseConcurrencyConflict::forReceipt();
        }
        if ($expectedRowVersion !== null) {
            $current = $existing->rowVersion();
            if ($current === null || !$current->equals($expectedRowVersion)) {
                throw ExpenseConcurrencyConflict::forReceipt();
            }
        }
        unset($this->byPublicId[$key]);
    }

    private function nextRowVersion(): RowVersionToken
    {
        return RowVersionToken::fromBinary(pack('NN', 0, $this->rowSeq++));
    }
}
