<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use RuntimeException;

/** A sync is already running (the portal's 409): two runs never overlap. */
final class OdooSyncBusy extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An Odoo sync is already running.');
    }
}
