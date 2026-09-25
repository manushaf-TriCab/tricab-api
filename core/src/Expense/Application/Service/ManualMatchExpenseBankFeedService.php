<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Application\Exception\ExpenseReceiptNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseReceiptPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseBankFeedRef;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseReceiptRef;
use TriCab\Core\Shared\Time\Clock;

final class ManualMatchExpenseBankFeedService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{bank: array<string, mixed>, receipt: array<string, mixed>}
     */
    public function execute(
        ExpenseActor $actor,
        string $bankPublicId,
        string $receiptPublicId,
        string $expectedBankRowVersion,
        string $expectedReceiptRowVersion,
    ): array {
        $this->access->requireFinance($actor);
        LegacyExpenseBankFeedRef::assertNotLegacy($bankPublicId);
        LegacyExpenseReceiptRef::assertNotLegacy($receiptPublicId);

        $bank = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($bankPublicId));
        if ($bank === null || $bank->id() === null) {
            throw ExpenseBankFeedNotFound::forPublicId($bankPublicId);
        }

        $receipt = $this->receipts->findByPublicId(ExpenseReceiptPublicId::fromString($receiptPublicId));
        if ($receipt === null || $receipt->isDeleted() || $receipt->id() === null) {
            throw ExpenseReceiptNotFound::forPublicId($receiptPublicId);
        }

        $now = $this->clock->now();

        if ($bank->receiptId() !== null && $bank->receiptId() !== $receipt->id()->toInt()) {
            $prior = $this->receipts->findById($bank->receiptId());
            if ($prior !== null) {
                $prior->unlinkBankFeed($now);
                $this->receipts->save($prior);
            }
        }

        $bank->matchTo($receipt->id()->toInt());
        $receipt->linkBankFeed($bank->id(), $now);

        $savedBank = $this->bankFeeds->save($bank, ExpenseRowVersion::require($expectedBankRowVersion));
        $savedReceipt = $this->receipts->save($receipt, ExpenseRowVersion::require($expectedReceiptRowVersion));

        $this->audit->record(
            $actor->userId,
            'bank_feed_manual_match',
            $savedReceipt->publicId(),
            $savedBank->publicId()->toString(),
        );

        return [
            'bank' => ExpenseFinanceViews::bankEntry($savedBank),
            'receipt' => ExpenseFinanceViews::receipt($savedReceipt),
        ];
    }
}
