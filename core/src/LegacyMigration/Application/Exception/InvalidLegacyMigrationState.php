<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Exception;

use RuntimeException;

final class InvalidLegacyMigrationState extends RuntimeException
{
    public static function because(string $action, string $status): self
    {
        return new self(sprintf('Cannot %s a batch in status "%s".', $action, $status));
    }
}
