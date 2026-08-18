<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class RoleCodeConflict extends RuntimeException
{
    public static function forCode(string $roleCode): self
    {
        return new self(sprintf('A role with code "%s" already exists.', $roleCode));
    }
}
