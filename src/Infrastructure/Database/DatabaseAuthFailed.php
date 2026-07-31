<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use RuntimeException;
use Throwable;

final class DatabaseAuthFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function fromThrowable(Throwable $throwable): self
    {
        return new self('Unable to authenticate with Azure SQL.', 0, $throwable);
    }
}
