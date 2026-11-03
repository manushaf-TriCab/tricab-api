<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

use RuntimeException;

/** Entra has no group with this id (deleted, or never existed). */
final class EntraGroupNotFound extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self('Entra has no group ' . $id . '.');
    }
}
