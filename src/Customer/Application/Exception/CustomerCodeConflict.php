<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Exception;

use RuntimeException;

final class CustomerCodeConflict extends RuntimeException
{
    public static function forCode(string $customerCode): self
    {
        return new self(sprintf('A customer with code "%s" already exists.', $customerCode));
    }
}
