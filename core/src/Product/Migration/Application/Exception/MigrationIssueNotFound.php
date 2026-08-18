<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Exception;

use RuntimeException;

final class MigrationIssueNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self('Migration issue not found: ' . $publicId);
    }
}
