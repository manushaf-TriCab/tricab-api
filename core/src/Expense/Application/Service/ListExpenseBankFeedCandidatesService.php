<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Service\ExpenseBankFeedMatcher;
use TriCab\Core\Expense\Domain\Service\ExpenseNearMissDiagnostics;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Domain\ValueObject\LegacyExpenseBankFeedRef;

final class ListExpenseBankFeedCandidatesService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseBankFeedMatcher $matcher,
        private readonly ExpenseNearMissDiagnostics $nearMiss,
    ) {
    }

    /**
     * @return array{
     *   bankPublicId: string,
     *   matchStatus: string,
     *   candidates: list<array<string, mixed>>,
     *   nearMisses: list<array{receiptId: int, amountDelta: string, reason: string}>
     * }
     */
    public function execute(ExpenseActor $actor, string $publicId): array
    {
        $this->access->requireFinance($actor);
        LegacyExpenseBankFeedRef::assertNotLegacy($publicId);
        $entry = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($publicId));
        if ($entry === null) {
            throw ExpenseBankFeedNotFound::forPublicId($publicId);
        }

        $all = $this->receipts->listMatchCandidates();
        $profilesByUserId = [];
        foreach ($all as $receipt) {
            $uid = $receipt->ownerUserId()->toInt();
            if (!isset($profilesByUserId[$uid])) {
                $profilesByUserId[$uid] = $this->profiles->findByUserId($receipt->ownerUserId());
            }
        }

        $matches = $this->matcher->findCandidates($entry, $all, $profilesByUserId);
        $candidates = [];
        foreach ($matches as $receipt) {
            $candidates[] = [
                'publicId' => $receipt->publicId()->toString(),
                'receiptId' => $receipt->id()?->toInt(),
                'vendorName' => $receipt->vendorName(),
                'totalAmount' => $receipt->totalAmount()->amount(),
                'currency' => $receipt->currency()->toString(),
                'receiptDate' => $receipt->receiptDate()?->format('Y-m-d'),
                'status' => $receipt->status()->toString(),
            ];
        }

        return [
            'bankPublicId' => $entry->publicId()->toString(),
            'matchStatus' => $entry->matchStatus()->toString(),
            'candidates' => $candidates,
            'nearMisses' => $this->nearMiss->describe($entry, $all),
        ];
    }
}
