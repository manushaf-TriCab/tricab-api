<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure\Foundry;

interface FoundryStreamClient
{
    /**
     * POSTs `$payload` (which asks for `stream: true`) and hands each server-sent
     * event to `$onEvent` as it arrives. A non-2xx response is not an error here:
     * its status and a bounded slice of its body come back for the caller to judge.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @param callable(string, array<string, mixed>): void $onEvent
     * @return array{status: int, body: string} `body` is empty for a 2xx stream
     */
    public function stream(string $url, array $headers, array $payload, callable $onEvent): array;
}
