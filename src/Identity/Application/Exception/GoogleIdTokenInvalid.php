<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Exception;

use RuntimeException;

final class GoogleIdTokenInvalid extends RuntimeException
{
    public static function create(string $message = 'Google ID token validation failed.'): self
    {
        return new self($message);
    }

    public static function emailNotVerified(): self
    {
        return new self('Google account email is not verified.');
    }

    public static function notConfigured(): self
    {
        return new self('Google Sign-In is not configured.');
    }
}
