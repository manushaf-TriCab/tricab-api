<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class IdentityNotLinked extends RuntimeException
{
    public static function forExternalIdentity(
        string $provider,
        string $subjectId,
        string $tenantId,
    ): self {
        return new self(sprintf(
            'No platform user is linked to external identity provider="%s" subject="%s" tenant="%s".',
            $provider,
            $subjectId,
            $tenantId,
        ));
    }
}
