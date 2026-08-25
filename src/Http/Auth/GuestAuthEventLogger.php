<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

/**
 * High-level guest auth event logger. Never writes OTP, JWT, or Authorization values.
 */
final class GuestAuthEventLogger
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function log(string $event, array $context = []): void
    {
        $safe = ['event' => $event];
        foreach ($context as $key => $value) {
            if ($this->isForbiddenKey($key) || $this->looksSensitive((string) $value)) {
                continue;
            }
            $safe[$key] = $value;
        }

        error_log(json_encode($safe, JSON_UNESCAPED_SLASHES) ?: '{"event":"guest_auth_log_encode_failed"}');
    }

    private function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'otp')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'authorization')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'hash')
            || str_contains($normalized, 'jwt')
            || str_contains($normalized, 'cookie')
            || str_contains($normalized, 'attestation')
            || str_contains($normalized, 'assertion')
            || str_contains($normalized, 'credential')
            || str_contains($normalized, 'publickey')
            || str_contains($normalized, 'private');
    }

    private function looksSensitive(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (preg_match('/^\d{6}$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $value) === 1) {
            return true;
        }

        return str_starts_with(strtolower($value), 'bearer ');
    }
}
