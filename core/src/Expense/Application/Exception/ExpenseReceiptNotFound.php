<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Exception;

use RuntimeException;

final class ExpenseReceiptNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('Expense receipt not found: %s', $publicId));
    }
}