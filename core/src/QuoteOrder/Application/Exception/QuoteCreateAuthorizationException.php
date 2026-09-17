<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\Exception;

use RuntimeException;

final class QuoteCreateAuthorizationException extends RuntimeException
{
    public static function missingPermission(): self
    {
        return new self('QUOTE.CREATE permission is required to create quotes.');
    }

    public static function customerNotAllowed(string $customerPublicId): self
    {
        return new self(
            sprintf('Actor is not eligible to create a quote for customer "%s".', $customerPublicId),
        );
    }

    public static function customerNotFound(): self
    {
        return new self('Customer not found.');
    }

    public static function unauthenticated(): self
    {
        return new self('Authentication required to create quotes.');
    }
}
