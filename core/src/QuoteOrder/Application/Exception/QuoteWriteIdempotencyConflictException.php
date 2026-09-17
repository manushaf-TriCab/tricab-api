<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class QuoteWriteIdempotencyConflictException extends RuntimeException
{
    public static function requestMismatch(): self
    {
        return new self(
            'Idempotency-Key was reused with a different request body.',
        );
    }
}
