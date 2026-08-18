<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class PermissionNotFound extends RuntimeException
{
    public static function forCode(string $permissionCode): self
    {
        return new self(sprintf('No permission found for code "%s".', $permissionCode));
    }
}
