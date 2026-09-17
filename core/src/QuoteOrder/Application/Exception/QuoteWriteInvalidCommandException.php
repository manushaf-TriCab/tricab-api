<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use InvalidArgumentException;

final class QuoteWriteInvalidCommandException extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self('Invalid quote persistence command: ' . $reason);
    }
}
