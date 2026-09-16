<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\Exception;

use RuntimeException;

final class LogNoteContactCustomerMismatch extends RuntimeException
{
    public static function forContact(string $contactPublicId): self
    {
        return new self(sprintf(
            'Contact "%s" does not belong to the selected customer.',
            $contactPublicId,
        ));
    }
}
