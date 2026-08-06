<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class PermissionCodeConflict extends RuntimeException
{
    public static function forCode(string $permissionCode): self
    {
        return new self(sprintf('A permission with code "%s" already exists.', $permissionCode));
    }
}
