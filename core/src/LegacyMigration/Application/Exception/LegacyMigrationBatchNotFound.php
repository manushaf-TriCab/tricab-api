<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Application\Exception;

use RuntimeException;

final class LegacyMigrationBatchNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self('Legacy migration batch not found: ' . $publicId);
    }
}
