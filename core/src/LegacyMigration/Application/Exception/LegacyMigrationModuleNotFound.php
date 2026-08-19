<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Exception;

use RuntimeException;

final class LegacyMigrationModuleNotFound extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self('Unknown legacy migration module: ' . $code);
    }
}
