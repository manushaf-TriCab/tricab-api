<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpenseBankFeedNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\ValueObject\ExpenseBankFeedPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class OffsetExpenseBankFeedService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{a: array<string, mixed>, b: array<string, mixed>}
     */
    public function execute(
        ExpenseActor $actor,
        string $publicIdA,
        string $publicIdB,
        string $expectedRvA,
        string $expectedRvB,
    ): array {
        $this->access->requireFinance($actor);

        $a = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($publicIdA));
        $b = $this->bankFeeds->findByPublicId(ExpenseBankFeedPublicId::fromString($publicIdB));
        if ($a === null || $a->id() === null) {
            throw ExpenseBankFeedNotFound::forPublicId($publicIdA);
        }
        if ($b === null || $b->id() === null) {
            throw ExpenseBankFeedNotFound::forPublicId($publicIdB);
        }
        if ($a->publicId()->equals($b->publicId())) {
            throw InvalidExpenseValue::for('offset', 'same_entry');
        }

        if (!$a->isEligibleForOffset() || !$b->isEligibleForOffset()) {
            throw InvalidExpenseValue::for('offset', 'not_eligible');
        }
        if ($a->isPaymentType() || $b->isPaymentType()) {
            throw InvalidExpenseValue::for('offset', 'payment_type');
        }
        if ($a->cardholderEmail() !== $b->cardholderEmail()) {
            throw InvalidExpenseValue::for('offset', 'cardholder_mismatch');
        }

        $sum = bcadd($a->amount()->amount(), $b->amount()->amount(), 6);
        $abs = str_starts_with($sum, '-') ? substr($sum, 1) : $sum;
        if (bccomp($abs, '0.005', 6) >= 0) {
            throw InvalidExpenseValue::for('offset', 'amount_not_opposite');
        }

        $dateA = $a->transactionDate();
        $dateB = $b->transactionDate();
        if ($dateA === null || $dateB === null) {
            throw InvalidExpenseValue::for('offset', 'missing_transaction_date');
        }
        $days = (int) $dateA->setTime(0, 0)->diff($dateB->setTime(0, 0))->format('%a');
        if ($days > Module::OFFSET_MAX_DAYS) {
            throw InvalidExpenseValue::for('offset', 'date_gap');
        }

        $now = $this->clock->now();
        $a->offsetWith($b->id(), $actor->userId, $now);
        $b->offsetWith($a->id(), $actor->userId, $now);

        $savedA = $this->bankFeeds->save($a, ExpenseRowVersion::require($expectedRvA));
        $savedB = $this->bankFeeds->save($b, ExpenseRowVersion::require($expectedRvB));

        $this->audit->record(
            $actor->userId,
            'bank_feed_offset',
            null,
            sprintf('%s+%s', $savedA->publicId()->toString(), $savedB->publicId()->toString()),
        );

        return [
            'a' => ExpenseFinanceViews::bankEntry($savedA),
            'b' => ExpenseFinanceViews::bankEntry($savedB),
        ];
    }
}
