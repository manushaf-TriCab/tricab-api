<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use RuntimeException;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

final class FakeJsonHttpClient implements JsonHttpClient
{
    /**
     * @var list<array{
     *   url: string,
     *   headers: array<string, string>,
     *   kind: 'json'|'form',
     *   payload: array<string, mixed>|null,
     *   fields: array<string, string>|null
     * }>
     */
    public array $calls = [];

    public int $status = 200;

    public string $body = '{"MessageID":"test"}';

    public ?RuntimeException $transportError = null;

    /** @var list<array{status: int, body: string}|RuntimeException> */
    public array $queue = [];

    public function postJson(string $url, array $headers, array $payload): array
    {
        $this->calls[] = [
            'url' => $url,
            'headers' => $headers,
            'kind' => 'json',
            'payload' => $payload,
            'fields' => null,
        ];

        return $this->nextResponse();
    }

    public function postForm(string $url, array $headers, array $fields): array
    {
        $this->calls[] = [
            'url' => $url,
            'headers' => $headers,
            'kind' => 'form',
            'payload' => null,
            'fields' => $fields,
        ];

        return $this->nextResponse();
    }

    /**
     * @return array{status: int, body: string}
     */
    private function nextResponse(): array
    {
        if ($this->transportError !== null) {
            throw $this->transportError;
        }

        if ($this->queue !== []) {
            $next = array_shift($this->queue);
            if ($next instanceof RuntimeException) {
                throw $next;
            }

            return $next;
        }

        return [
            'status' => $this->status,
            'body' => $this->body,
        ];
    }
}
