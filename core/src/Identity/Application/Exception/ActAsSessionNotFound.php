<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class ActAsSessionNotFound extends RuntimeException
{
    public static function forId(string $sessionId): self
    {
        return new self(sprintf('No act-as session found for id "%s".', $sessionId));
    }
}
