<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Exception;

use DomainException;

final class InvalidExpenseValue extends DomainException
{
    public static function for(string $field, string $value): self
    {
        return new self(sprintf('Invalid expense value for %s: %s', $field, $value));
    }
}