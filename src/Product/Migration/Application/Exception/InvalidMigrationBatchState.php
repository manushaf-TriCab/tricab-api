<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Exception;

use RuntimeException;

final class InvalidMigrationBatchState extends RuntimeException
{
    public static function for(string $publicId, string $status, string $action): self
    {
        return new self(sprintf('Cannot %s batch %s in status %s', $action, $publicId, $status));
    }
}
