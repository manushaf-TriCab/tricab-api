<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Exception;

use RuntimeException;

final class ProductionWriteRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
