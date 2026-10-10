<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * A period scan was asked for a period already scanned (the source's 409 `already_scanned`); `extra.priorRuns` lists the
 * earlier runs. The page asks the person whether to "Scan anyway" (`confirmRescan`). The code says "already scanned" on
 * purpose: the web adapter turns a 409 into this prompt by it, so no other conflict may use those words.
 */
final class ApAlreadyScanned extends ApFailure
{
    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP_ALREADY_SCANNED';
    }
}
