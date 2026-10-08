<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** A period scan was asked for a period already scanned (the source's 409 `already_scanned`); `extra.priorRuns` lists the earlier runs. */
final class ArAlreadyScanned extends ArFailure
{
    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_ALREADY_SCANNED';
    }
}
