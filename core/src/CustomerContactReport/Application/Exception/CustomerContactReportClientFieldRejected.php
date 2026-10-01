<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use DomainException;

final class CustomerContactReportClientFieldRejected extends DomainException
{
    public static function forField(string $field): self
    {
        return new self('The client cannot set ' . $field . '.');
    }
}
