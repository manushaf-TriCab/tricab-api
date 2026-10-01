<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\Exception;

use DomainException;

final class InvalidCustomerContactReportValue extends DomainException
{
    public static function for(string $field, string $message): self
    {
        return new self($field . ': ' . $message);
    }
}
