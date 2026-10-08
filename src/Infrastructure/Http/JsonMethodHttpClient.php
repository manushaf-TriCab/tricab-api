<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Http;

/**
 * A {@see JsonHttpClient} that can also use the other HTTP verbs a REST API needs (PATCH, DELETE, a POST with no body).
 * A sub-interface, so the adapters and test doubles of the original three calls are untouched.
 */
interface JsonMethodHttpClient extends JsonHttpClient
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload null sends no body
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    public function sendJson(string $method, string $url, array $headers, ?array $payload = null): array;
}
