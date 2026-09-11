<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;

final class UndoOffsetExpenseBankFeedService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseAuditRecorder $audit,
    ) {
    }

    /**
     * @return array{entry: array<string, mixed>, partner: ?array<string, mixed>}
     */
    public function execute(ExpenseActor $actor, string $publicId, string $expectedRowVersion): array
    {
        $this->access->requireFinance($actor);

        $entry = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($publicId));
        if ($entry === null) {
            throw ExpenseBankFeedNotFound::forPublicId($publicId);
        }

        $partnerId = $entry->offsetBankFeedEntryId();
        $entry->undoOffset();
        $saved = $this->bankFeeds->save($entry, ExpenseRowVersion::require($expectedRowVersion));

        $partnerView = null;
        if ($partnerId !== null) {
            $partner = $this->bankFeeds->findById($partnerId);
            if ($partner !== null) {
                $partner->undoOffset();
                $partner = $this->bankFeeds->save($partner);
                $partnerView = ExpenseFinanceViews::bankEntry($partner);
            }
        }

        $this->audit->record(
            $actor->userId,
            'bank_feed_offset_undone',
            null,
            $saved->publicId()->toString(),
        );

        return [
            'entry' => ExpenseFinanceViews::bankEntry($saved),
            'partner' => $partnerView,
        ];
    }
}
