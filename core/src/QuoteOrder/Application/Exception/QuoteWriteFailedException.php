<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;
use Throwable;

final class QuoteWriteFailedException extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('Quote legacy write failed: ' . $reason, 0, $previous);
    }
}
