<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Application\Exception\ExpensePeriodNotFound;
use TriCab\Core\Expense\Application\ExpenseRowVersion;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\ExpenseReceiptRepository;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseStatusTransition;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Shared\Time\Clock;

final class CloseExpensePeriodService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpensePeriodRepository $periods,
        private readonly ExpenseReceiptRepository $receipts,
        private readonly PreCloseExpensePeriodService $preClose,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ExpenseActor $actor, string $periodPublicId, string $expectedRowVersion): array
    {
        $this->access->requireFinance($actor);

        $period = $this->periods->findByPublicId(ExpensePeriodPublicId::fromString($periodPublicId));
        if ($period === null || $period->id() === null) {
            throw ExpensePeriodNotFound::forPublicId($periodPublicId);
        }

        $check = $this->preClose->evaluate($period);
        if (!$check['canClose']) {
            $summary = implode('; ', array_map(
                static fn (array $b): string => $b['type'] . ':' . ($b['publicId'] ?? (string) $b['id']),
                $check['blockers'],
            ));
            throw InvalidExpenseStatusTransition::periodPreCloseBlocked($summary !== '' ? $summary : 'blockers present');
        }

        $now = $this->clock->now();
        $period->close($actor->userId, $now);
        $saved = $this->periods->save($period, ExpenseRowVersion::require($expectedRowVersion));

        $txStart = $saved->transactionStartDate() ?? $saved->periodStart();
        $txEnd = $saved->transactionEndDate() ?? $saved->periodEnd();
        foreach ($this->receipts->listInReceiptDateRange($txStart, $txEnd, false) as $receipt) {
            if ($receipt->periodId() !== null) {
                continue;
            }
            $receipt->assignPeriod((int) $saved->id(), $now);
            $this->receipts->save($receipt);
        }

        $this->audit->record(
            $actor->userId,
            'period_closed',
            null,
            $saved->publicId()->toString(),
        );

        return ExpenseFinanceViews::period($saved);
    }
}
