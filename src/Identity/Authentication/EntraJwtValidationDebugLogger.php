<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Api\Config\Settings;

/**
 * Temporary development-only diagnostics for Entra JWT rejection reasons.
 * Never logs tokens, Authorization header values, secrets, or JWT payloads.
 */
final class EntraJwtValidationDebugLogger
{
    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function enabled(): bool
    {
        return $this->settings->isDevelopment();
    }

    /**
     * @param array<string, string|int|bool|null> $fields
     */
    public function log(array $fields): void
    {
        if (!$this->enabled()) {
            return;
        }

        $lines = ['[entra-jwt-validation]'];
        foreach ($fields as $key => $value) {
            if ($value === null) {
                $value = 'n/a';
            } elseif (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $lines[] = $key . '=' . $value;
        }

        $message = implode(' ', $lines);
        error_log($message);
        $this->appendToFile($message);
    }

    public function classifyFailure(string $message): string
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'expired')) {
            return 'expired token';
        }
        if (str_contains($lower, 'signature')) {
            return 'invalid signature';
        }
        if (str_contains($lower, 'audience') || str_contains($lower, 'graph access tokens')) {
            return 'invalid audience';
        }
        if (str_contains($lower, 'issuer')) {
            return 'invalid issuer';
        }
        if (str_contains($lower, 'scope')) {
            return 'missing scope';
        }
        if (str_contains($lower, 'claim "oid"') || str_contains($lower, 'claim oid') || preg_match('/claim "oid"/', $lower) === 1) {
            return 'missing oid';
        }
        if (str_contains($lower, 'required identity claim')) {
            if (str_contains($lower, 'oid')) {
                return 'missing oid';
            }

            return 'missing subject claim';
        }
        if (str_contains($lower, 'tenant')) {
            return 'missing tid';
        }
        if (str_contains($lower, 'unable to download') || str_contains($lower, 'jwks')) {
            return 'JWKS download failure';
        }
        if (
            str_contains($lower, '"kid" invalid')
            || str_contains($lower, 'kid invalid')
            || str_contains($lower, 'unable to find a key')
            || str_contains($lower, 'key id')
        ) {
            return 'unknown key id (kid)';
        }
        if (str_contains($lower, 'malformed')) {
            return 'token not parsed';
        }
        if (str_contains($lower, 'not configured')) {
            return 'entra jwt not configured';
        }

        return 'unclassified: ' . $this->sanitizeReason($message);
    }

    private function sanitizeReason(string $message): string
    {
        // Strip anything that looks like a JWT fragment or bearer token.
        $sanitized = preg_replace('/[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/', '[redacted-jwt]', $message);
        $sanitized = is_string($sanitized) ? $sanitized : 'unclassified';

        return substr($sanitized, 0, 240);
    }

    private function appendToFile(string $message): void
    {
        $candidates = [
            dirname(__DIR__, 3) . '/var/log',
            sys_get_temp_dir() . '/tricab-api-entra-jwt',
        ];

        foreach ($candidates as $directory) {
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                continue;
            }

            $path = $directory . '/entra-jwt-validation.log';
            if (@file_put_contents($path, $message . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        }
    }
}
