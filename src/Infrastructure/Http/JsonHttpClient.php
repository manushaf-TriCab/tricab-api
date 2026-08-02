<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Http;

/**
 * Minimal JSON HTTP client boundary for infrastructure adapters.
 */
interface JsonHttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    public function postJson(string $url, array $headers, array $payload): array;
}
