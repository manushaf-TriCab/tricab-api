<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Cache;

use RuntimeException;

/**
 * Cross-request file cache for php -S / CLI when APCu is unavailable.
 * Files are 0600 and gitignored under var/cache. Never log cached values.
 */
final class FilesystemApplicationCache implements ApplicationCache
{
    public function __construct(private readonly string $directory)
    {
        $this->ensureDirectory();
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $payload = $this->readPayload($path);
        if ($payload === null) {
            @unlink($path);

            return null;
        }

        if ($payload['expiresAt'] <= time() || $payload['key'] !== $key) {
            @unlink($path);

            return null;
        }

        return $payload['value'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->ensureDirectory();
        $path = $this->path($key);
        $written = @file_put_contents($path, $this->encode($key, $value, $ttlSeconds), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Unable to write application cache file.');
        }
        @chmod($path, 0600);
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteByPrefix(string $prefix): void
    {
        foreach ($this->cacheFiles() as $path) {
            $payload = $this->readPayload($path);
            if ($payload === null) {
                @unlink($path);
                continue;
            }
            if (str_starts_with($payload['key'], $prefix)) {
                @unlink($path);
            }
        }
    }

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        $this->get($key);
        $this->ensureDirectory();
        $path = $this->path($key);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            return false;
        }

        try {
            fwrite($handle, $this->encode($key, $value, $ttlSeconds));
            fflush($handle);
        } finally {
            fclose($handle);
            @chmod($path, 0600);
        }

        return true;
    }

    public function driver(): string
    {
        return 'file';
    }

    public function isCrossRequest(): bool
    {
        return true;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create application cache directory.');
        }
        @chmod($this->directory, 0700);
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '/\\') . '/' . hash('sha256', $key) . '.cache';
    }

    /**
     * @return list<string>
     */
    private function cacheFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $matches = glob(rtrim($this->directory, '/\\') . '/*.cache') ?: [];

        return array_values(array_filter($matches, 'is_file'));
    }

    /**
     * @return array{key: string, expiresAt: int, value: mixed}|null
     */
    private function readPayload(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($decoded)) {
            return null;
        }

        $key = $decoded['key'] ?? null;
        $expiresAt = $decoded['expiresAt'] ?? null;
        if (!is_string($key) || !is_int($expiresAt)) {
            return null;
        }

        return [
            'key' => $key,
            'expiresAt' => $expiresAt,
            'value' => $decoded['value'] ?? null,
        ];
    }

    private function encode(string $key, mixed $value, int $ttlSeconds): string
    {
        return serialize([
            'key' => $key,
            'expiresAt' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ]);
    }
}
