<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use DateTimeImmutable;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFinanceViews;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Domain\Entity\ExpensePeriod;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Domain\ValueObject\ExpensePeriodPublicId;
use TriCab\Core\Expense\Module;
use TriCab\Core\Shared\Time\Clock;

final class GetOrCreateExpensePeriodService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpensePeriodRepository $periods,
        private readonly ExpenseBankFeedRepository $bankFeeds,
        private readonly ExpenseAuditRecorder $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ExpenseActor $actor, string $country): array
    {
        $this->access->requireFinance($actor);

        $countryCode = strtoupper(trim($country));
        if (!in_array($countryCode, [Module::COUNTRY_AU, Module::COUNTRY_NZ], true)) {
            throw InvalidExpenseValue::for('country', $country);
        }

        $window = $this->computeWindow($countryCode);
        $open = $this->periods->findOpenByCountry($countryCode);

        if ($open !== null) {
            $open->updateWindow(
                $window['periodStart'],
                $window['periodEnd'],
                $window['txStart'],
                $window['txEnd'],
            );
            $saved = $this->periods->save($open);
            $this->assignBankEntries($saved, $countryCode, $window);

            return ExpenseFinanceViews::period($saved);
        }

        $period = ExpensePeriod::open(
            publicId: ExpensePeriodPublicId::generate(),
            country: $countryCode,
            periodStart: $window['periodStart'],
            periodEnd: $window['periodEnd'],
            transactionStartDate: $window['txStart'],
            transactionEndDate: $window['txEnd'],
            createdAtUtc: $this->clock->now(),
        );
        $saved = $this->periods->save($period);
        $this->assignBankEntries($saved, $countryCode, $window);

        $this->audit->record(
            $actor->userId,
            'period_opened',
            null,
            sprintf('%s:%s', $countryCode, $saved->publicId()->toString()),
        );

        return ExpenseFinanceViews::period($saved);
    }

    /**
     * @return array{
     *   periodStart: DateTimeImmutable,
     *   periodEnd: DateTimeImmutable,
     *   txStart: ?DateTimeImmutable,
     *   txEnd: ?DateTimeImmutable
     * }
     */
    private function computeWindow(string $countryCode): array
    {
        $entries = $this->bankFeeds->listByCountry($countryCode);
        $maxStatement = $this->bankFeeds->maxStatementDate($countryCode);
        $minTx = $this->bankFeeds->minTransactionDate($countryCode);

        $txStart = null;
        $txEnd = null;
        foreach ($entries as $entry) {
            $tx = $entry->transactionDate();
            if ($tx === null) {
                continue;
            }
            if ($txStart === null || $tx < $txStart) {
                $txStart = $tx;
            }
            if ($txEnd === null || $tx > $txEnd) {
                $txEnd = $tx;
            }
        }

        $periodEnd = $maxStatement ?? $txEnd ?? $this->clock->now()->setTime(0, 0);
        $previous = $this->periods->findLatestByCountry($countryCode);
        if ($previous !== null && (!$previous->status()->isOpen())) {
            $periodStart = $previous->periodEnd()->modify('+1 day')->setTime(0, 0);
        } elseif ($previous !== null && $previous->status()->isOpen()) {
            $periodStart = $previous->periodStart();
        } else {
            $periodStart = ($minTx ?? $periodEnd)->setTime(0, 0);
        }

        if ($periodStart > $periodEnd) {
            $periodStart = $periodEnd;
        }

        return [
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd->setTime(0, 0),
            'txStart' => $txStart?->setTime(0, 0),
            'txEnd' => $txEnd?->setTime(0, 0),
        ];
    }

    /**
     * @param array{periodStart: DateTimeImmutable, periodEnd: DateTimeImmutable, txStart: ?DateTimeImmutable, txEnd: ?DateTimeImmutable} $window
     */
    private function assignBankEntries(ExpensePeriod $period, string $countryCode, array $window): void
    {
        if ($period->id() === null) {
            return;
        }
        $txStart = $window['txStart'];
        $txEnd = $window['txEnd'];
        foreach ($this->bankFeeds->listByCountry($countryCode) as $entry) {
            if ($entry->periodId() !== null) {
                continue;
            }
            $tx = $entry->transactionDate();
            if ($txStart !== null && $txEnd !== null && $tx !== null) {
                $day = $tx->setTime(0, 0);
                if ($day < $txStart || $day > $txEnd) {
                    continue;
                }
            }
            $entry->assignPeriod($period->id());
            $this->bankFeeds->save($entry);
        }
    }
}
