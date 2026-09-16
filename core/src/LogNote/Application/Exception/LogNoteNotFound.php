<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\Exception;

use RuntimeException;

final class LogNoteNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self(sprintf('No log note found for public id "%s".', $publicId));
    }
}
