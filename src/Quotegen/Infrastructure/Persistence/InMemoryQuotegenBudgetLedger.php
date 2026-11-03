<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Infrastructure\Persistence;

use TriCab\Api\Quotegen\Application\QuotegenBudget;
use TriCab\Api\Quotegen\Application\QuotegenBudgetLedger;

/** For tests and local runs. */
final class InMemoryQuotegenBudgetLedger implements QuotegenBudgetLedger
{
    /** @var array<string, array{rfq: string, kind: string, micros: int}> by reservation id */
    private array $rows = [];

    public function summary(string $rfqPublicId, int $limitMicros): QuotegenBudget
    {
        $spent = 0;
        $reserved = 0;
        foreach ($this->rows as $row) {
            if ($row['rfq'] !== strtolower($rfqPublicId)) {
                continue;
            }
            if ($row['kind'] === 'actual') {
                $spent += $row['micros'];
            } else {
                $reserved += $row['micros'];
            }
        }

        return new QuotegenBudget($limitMicros, $spent, $reserved);
    }

    public function reserve(string $rfqPublicId, string $reservationId, int $micros, int $limitMicros): bool
    {
        $summary = $this->summary($rfqPublicId, $limitMicros);
        if ($summary->spentMicros + $summary->reservedMicros + $micros > $limitMicros) {
            return false;
        }
        $this->rows[$reservationId] = ['rfq' => strtolower($rfqPublicId), 'kind' => 'reservation', 'micros' => $micros];

        return true;
    }

    public function reconcile(string $reservationId, int $actualMicros): void
    {
        if (isset($this->rows[$reservationId])) {
            $this->rows[$reservationId]['kind'] = 'actual';
            $this->rows[$reservationId]['micros'] = max(0, $actualMicros);
        }
    }
}
