<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\Exception;

use DomainException;

final class InvalidPortalAccessTransition extends DomainException
{
    public static function from(string $fromStatus, string $toStatus): self
    {
        return new self(sprintf(
            'Portal access status cannot transition from "%s" to "%s".',
            $fromStatus,
            $toStatus,
        ));
    }
}
