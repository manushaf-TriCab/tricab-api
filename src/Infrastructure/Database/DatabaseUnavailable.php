<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use RuntimeException;
use Throwable;

class DatabaseUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function fromThrowable(Throwable $throwable): self
    {
        return new self('Azure SQL connection failed.', 0, $throwable);
    }
}
