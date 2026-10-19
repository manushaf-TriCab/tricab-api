<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use Closure;
use LogicException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;

/** A JSON HTTP client that answers from a script and records each POST. No network. */
final class ScriptedHttp implements JsonHttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, payload: array<string, mixed>}> */
    public array $requests = [];

    /** @var (Closure(): void)|null */
    public ?Closure $afterEachRequest = null;

    /**
     * @param list<array{status: int, body: string, headers?: array<string, string>}|Throwable> $replies
     */
    public function __construct(private array $replies)
    {
    }

    public function getJson(string $url, array $headers = []): array
    {
        throw new LogicException('Unexpected GET ' . $url);
    }

    public function postJson(string $url, array $headers, array $payload): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'payload' => $payload];
        if ($this->afterEachRequest !== null) {
            ($this->afterEachRequest)();
        }
        $next = array_shift($this->replies) ?? throw new LogicException('ScriptedHttp has no more replies.');
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function postForm(string $url, array $headers, array $fields): array
    {
        throw new LogicException('Unexpected form POST ' . $url);
    }
}
