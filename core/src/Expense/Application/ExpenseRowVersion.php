<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application;

use InvalidArgumentException;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class ExpenseRowVersion
{
    public static function require(mixed $value): RowVersionToken
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('expectedRowVersion is required.');
        }

        return RowVersionToken::fromBase64(trim($value));
    }
}
