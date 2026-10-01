<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use RuntimeException;

final class CustomerContactReportNotAssignee extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the current assignee can complete this Customer Contact report.');
    }
}
