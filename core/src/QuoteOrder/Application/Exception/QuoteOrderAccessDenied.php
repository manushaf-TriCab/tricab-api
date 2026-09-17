<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class QuoteOrderAccessDenied extends RuntimeException
{
    public static function permissionRequired(): self
    {
        return new self('QUOTE.READ permission is required.');
    }

    public static function viewForbidden(): self
    {
        return new self('You are not authorized to view this quote or order.');
    }
}
