<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class IdentityAmbiguous extends RuntimeException
{
    public static function duplicateEmail(): self
    {
        return new self('Multiple TriCab users match the verified email.');
    }
}
