<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use RuntimeException;
use TriCab\Api\Infrastructure\Http\JsonMethodHttpClient;

/**
 * An HTTP client for the threaded-reply tests: it never leaves the process, records every call with its verb, and
 * answers from a queue of responses (or throws a queued exception). A call with nothing queued is a test bug.
 */
final class RecordingMethodHttpClient implements JsonMethodHttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, payload: array<string, mixed>|null}> */
    public array $calls = [];

    /** @var list<array{status: int, body: string, headers?: array<string, string>}|RuntimeException> */
    public array $queue = [];

    public function getJson(string $url, array $headers = []): array
    {
        return $this->record('GET', $url, $headers, null);
    }

    public function postJson(string $url, array $headers, array $payload): array
    {
        return $this->record('POST', $url, $headers, $payload);
    }

    public function postForm(string $url, array $headers, array $fields): array
    {
        return $this->record('FORM', $url, $headers, $fields);
    }

    public function sendJson(string $method, string $url, array $headers, ?array $payload = null): array
    {
        return $this->record($method, $url, $headers, $payload);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $payload
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    private function record(string $method, string $url, array $headers, ?array $payload): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'payload' => $payload];
        $next = array_shift($this->queue) ?? throw new RuntimeException('Nothing queued for ' . $method . ' ' . $url);
        if ($next instanceof RuntimeException) {
            throw $next;
        }

        return $next;
    }

    /**
     * The verb and the path after `/v1.0/users/{mailbox}/`, one string per call, for compact assertions.
     *
     * @return list<string>
     */
    public function trail(): array
    {
        return array_map(static function (array $call): string {
            $path = (string) preg_replace('~^https://graph\.microsoft\.com/v1\.0/users/[^/]+/~', '', $call['url']);

            return $call['method'] . ' ' . $path;
        }, $this->calls);
    }
}
