<?php

declare(strict_types=1);

namespace TriCab\Core\LogNote\Application\Exception;

use RuntimeException;

final class LogNoteClientIdentityRejected extends RuntimeException
{
    public static function forbiddenField(string $field): self
    {
        return new self(sprintf(
            'Client-supplied author identity field "%s" is not accepted.',
            $field,
        ));
    }
}
