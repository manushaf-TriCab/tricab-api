<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Exception;

use RuntimeException;

final class ExpenseOcrUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('Receipt OCR is not enabled for this environment.');
    }

    public static function inputRejected(string $reason): self
    {
        return new self('Receipt attachment cannot be processed by OCR: ' . $reason . '.');
    }
}
