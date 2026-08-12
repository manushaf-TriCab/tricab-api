<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Cache;

use RuntimeException;

/**
 * Shared-memory cache. Survives HTTP requests in the same FPM worker / php -S
 * process when APCu is loaded. CLI and `php -S` also need `apc.enable_cli=1`.
 */
final class ApcuApplicationCache implements ApplicationCache
{
    public static function isUsable(): bool
    {
        if (!extension_loaded('apcu') || !function_exists('apcu_fetch') || !function_exists('apcu_store')) {
            return false;
        }

        $enabled = ini_get('apc.enabled');
        if ($enabled === '0' || $enabled === 'Off' || $enabled === 'off' || $enabled === 'false') {
            return false;
        }

        $sapi = PHP_SAPI;
        if ($sapi === 'cli' || $sapi === 'phpdbg' || $sapi === 'cli-server') {
            $cli = ini_get('apc.enable_cli');

            return $cli === '1' || $cli === 'On' || $cli === 'on' || $cli === 'true';
        }

        return true;
    }

    public function get(string $key): mixed
    {
        $this->assertAvailable();
        $success = false;
        $value = apcu_fetch($key, $success);

        return $success ? $value : null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->assertAvailable();
        apcu_store($key, $value, max(1, $ttlSeconds));
    }

    public function delete(string $key): void
    {
        $this->assertAvailable();
        apcu_delete($key);
    }

    public function deleteByPrefix(string $prefix): void
    {
        $this->assertAvailable();
        if (!class_exists(\APCUIterator::class)) {
            return;
        }

        $pattern = '/^' . preg_quote($prefix, '/') . '/';
        foreach (new \APCUIterator($pattern) as $entry) {
            if (is_array($entry) && isset($entry['key']) && is_string($entry['key'])) {
                apcu_delete($entry['key']);
            }
        }
    }

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        $this->assertAvailable();

        return apcu_add($key, $value, max(1, $ttlSeconds));
    }

    public function driver(): string
    {
        return 'apcu';
    }

    public function isCrossRequest(): bool
    {
        return true;
    }

    private function assertAvailable(): void
    {
        if (!self::isUsable()) {
            throw new RuntimeException(
                'APCu is not usable. Install the apcu extension. For php -S / CLI, start PHP with -d apc.enable_cli=1.',
            );
        }
    }
}
