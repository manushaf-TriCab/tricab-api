<?php

declare(strict_types=1);

namespace TriCab\Api\Quotegen\Application;

/**
 * The per-RFQ spend ledger: a reservation row before each model call, replaced by
 * an actual row once the call's reported usage is known.
 */
interface QuotegenBudgetLedger
{
    public function summary(string $rfqPublicId, int $limitMicros): QuotegenBudget;

    /**
     * Sets `$micros` aside for one call, unless spent + reserved + `$micros` would pass the limit.
     *
     * @return bool false when the limit would be passed (nothing is reserved)
     */
    public function reserve(string $rfqPublicId, string $reservationId, int $micros, int $limitMicros): bool;

    /** Turns the reservation into spend of `$actualMicros` (0 releases it). Unknown ids are ignored. */
    public function reconcile(string $reservationId, int $actualMicros): void;
}
