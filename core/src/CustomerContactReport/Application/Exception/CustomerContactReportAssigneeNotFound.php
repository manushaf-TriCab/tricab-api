<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use RuntimeException;

final class CustomerContactReportAssigneeNotFound extends RuntimeException
{
    public static function forLogin(string $login): self
    {
        return new self('No active staff member matches assignee ' . $login . '.');
    }
}
