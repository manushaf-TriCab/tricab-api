<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Exception;

use DomainException;

final class SystemRoleProtected extends DomainException
{
    public static function cannotRename(string $roleCode): self
    {
        return new self(sprintf('System-protected role code "%s" cannot be renamed.', $roleCode));
    }

    public static function cannotDeactivate(string $roleCode): self
    {
        return new self(sprintf('System-protected role "%s" cannot be deactivated.', $roleCode));
    }
}
