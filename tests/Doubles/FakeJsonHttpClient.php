<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use RuntimeException;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

final class FakeJsonHttpClient implements JsonHttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, payload: array<string, mixed>}> */
    public array $calls = [];

    public int $status = 200;

    public string $body = '{"MessageID":"test"}';

    public ?RuntimeException $transportError = null;

    public function postJson(string $url, array $headers, array $payload): array
    {
        $this->calls[] = [
            'url' => $url,
            'headers' => $headers,
            'payload' => $payload,
        ];

        if ($this->transportError !== null) {
            throw $this->transportError;
        }

        return [
            'status' => $this->status,
            'body' => $this->body,
        ];
    }
}
