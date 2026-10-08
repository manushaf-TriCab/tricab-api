<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ai;

use TriCab\Api\Infrastructure\Http\JsonHttpClient;

/**
 * A stand-in for the finance AI service's HTTP side: it answers a `complete` call the
 * way the service does, echoing the correlation id and purpose it was sent, so a test
 * does not need to know the random id in advance. `$override` replaces fields of the
 * answer, to build the wrong ones.
 */
final class EchoingFakeHttp implements JsonHttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, payload: array<string, mixed>|null}> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $answer fields of the answer besides the echoed ones
     * @param array<string, mixed> $override fields that replace the answer's
     */
    public function __construct(private readonly array $answer, private readonly array $override = [])
    {
    }

    public function getJson(string $url, array $headers = []): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'payload' => null];

        return ['status' => 200, 'body' => '{"contract":"finance-ai.v1","status":"ok","purposes":[]}'];
    }

    public function postJson(string $url, array $headers, array $payload): array
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
        $purpose = substr($url, (int) strrpos($url, '/') + 1);
        $body = [
            'contract' => 'finance-ai.v1',
            'correlationId' => $payload['correlationId'] ?? null,
            'purpose' => $purpose,
        ] + $this->answer;

        return ['status' => 200, 'body' => json_encode($this->override + $body, JSON_THROW_ON_ERROR)];
    }

    public function postForm(string $url, array $headers, array $fields): array
    {
        return ['status' => 404, 'body' => ''];
    }
}
