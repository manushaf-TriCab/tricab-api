<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** No vendors are synced from Odoo: the source's 503. */
final class ApVendorUnavailable extends ApVendorFailure
{
    public const MESSAGE = 'The Odoo vendor list is unavailable; try again shortly';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function httpStatus(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP_VENDORS_UNAVAILABLE';
    }
}
