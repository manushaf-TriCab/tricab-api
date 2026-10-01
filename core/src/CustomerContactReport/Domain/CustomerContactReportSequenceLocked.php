<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

use RuntimeException;

final class CustomerContactReportSequenceLocked extends RuntimeException
{
    public function __construct(int $year)
    {
        parent::__construct('Customer Contact sequence year ' . $year . ' is locked by another transaction.');
    }
}
