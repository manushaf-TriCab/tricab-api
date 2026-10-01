<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use RuntimeException;

final class CustomerContactReportAccessDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Customer Contact access is required.');
    }
}
