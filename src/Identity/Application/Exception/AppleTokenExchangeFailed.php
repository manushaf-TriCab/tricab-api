<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class AppleTokenExchangeFailed extends RuntimeException
{
    public static function create(string $message = 'Apple authorization code exchange failed.'): self
    {
        return new self($message);
    }
}
