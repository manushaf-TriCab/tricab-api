<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Exception;

use RuntimeException;

final class CustomerContactUserLinkNotFound extends RuntimeException
{
    public static function forContactAndUser(string $contactPublicId, string $userPublicId): self
    {
        return new self(sprintf(
            'No active link found between contact "%s" and user "%s".',
            $contactPublicId,
            $userPublicId,
        ));
    }
}
