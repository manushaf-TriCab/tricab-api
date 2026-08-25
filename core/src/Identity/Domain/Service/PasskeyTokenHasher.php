<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

final class PasskeyTokenHasher
{
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw, true);
    }

    public static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function ipHash(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        return hash('sha256', trim($ip), true);
    }
}
