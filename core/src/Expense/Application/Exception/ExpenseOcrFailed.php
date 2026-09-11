<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * Extraction failed upstream. The receipt is always left untouched, so the
 * caller can simply retry.
 */
final class ExpenseOcrFailed extends RuntimeException
{
    public static function extraction(?Throwable $previous = null): self
    {
        return new self('Receipt OCR extraction failed. The receipt was not changed.', 0, $previous);
    }

    public static function noFieldsExtracted(): self
    {
        return new self('Receipt OCR returned no usable fields. The receipt was not changed.');
    }
}
