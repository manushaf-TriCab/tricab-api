<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when guest session issuance cannot complete safely.
 * Must never include the access token in the message.
 */
final class GuestSessionIssuanceFailed extends RuntimeException
{
    public static function persistenceFailed(?Throwable $previous = null): self
    {
        return new self('Guest session persistence failed.', 0, $previous);
    }
}
