<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Http;

/**
 * Minimal JSON / form HTTP client boundary for infrastructure adapters.
 */
interface JsonHttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    public function getJson(string $url, array $headers = []): array;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    public function postJson(string $url, array $headers, array $payload): array;

    /**
     * application/x-www-form-urlencoded POST (OAuth token endpoints).
     *
     * @param array<string, string> $headers
     * @param array<string, string> $fields
     * @return array{status: int, body: string}
     */
    public function postForm(string $url, array $headers, array $fields): array;
}
