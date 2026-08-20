<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Support;

/**
 * Strips credential-like keys from payloads before staging or logging.
 */
final class CredentialRedactor
{
    /** @var list<string> */
    public const FORBIDDEN_KEYS = [
        'user_pass',
        'password',
        'passwordhash',
        'password_hash',
        'user_activation_key',
        'session_tokens',
        'application_passwords',
        'auth_key',
        'secure_auth_key',
        'logged_in_key',
        'nonce_key',
        'otp',
        'secret',
        'client_secret',
        'access_token',
        'refresh_token',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function strip(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (self::isForbidden($key)) {
                continue;
            }
            if (is_array($value)) {
                if ($value === [] || array_is_list($value)) {
                    $mapped = [];
                    foreach ($value as $item) {
                        $mapped[] = is_array($item) ? self::strip($item) : $item;
                    }
                    $out[$key] = $mapped;
                    continue;
                }
                /** @var array<string, mixed> $nested */
                $nested = [];
                foreach ($value as $nestedKey => $nestedValue) {
                    if (is_string($nestedKey)) {
                        $nested[$nestedKey] = $nestedValue;
                    }
                }
                $out[$key] = self::strip($nested);
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    public static function isForbidden(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
            return true;
        }

        return str_contains($normalized, 'password')
            || str_contains($normalized, 'user_pass')
            || str_contains($normalized, 'activation_key')
            || str_contains($normalized, 'session_token');
    }
}
