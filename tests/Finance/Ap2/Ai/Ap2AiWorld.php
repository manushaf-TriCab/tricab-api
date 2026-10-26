<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Ai;

use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Prompt\MarkdownPromptRepository;
use TriCab\Api\Assistant\Infrastructure\Prompt\VersionedPrompt;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Ap2\Ai\Ap2Foundry;
use TriCab\Api\Finance\Ap2\Ai\InMemoryAp2AiUsageLog;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiPricing;

/** A fake Foundry for the AI tests: a queued HTTP client, a usage log, the real prompts, a fixed nonce. */
final class Ap2AiWorld
{
    public const NONCE = 'abc123def456';
    public const ENDPOINT = 'https://tricab-ai2.services.ai.azure.com/';
    public const MODEL = 'claude-haiku-5-5';

    public FakeJsonHttpClient $http;

    public InMemoryAp2AiUsageLog $usage;

    public function __construct()
    {
        $this->http = new FakeJsonHttpClient();
        $this->http->queue = [];
        $this->usage = new InMemoryAp2AiUsageLog();
    }

    public function foundry(string $authMode = Settings::FOUNDRY_AUTH_MODE_MANAGED_IDENTITY): Ap2Foundry
    {
        $tokens = new class() implements FoundryAccessTokenProvider {
            public function accessToken(): string
            {
                return 'mi-token';
            }
        };

        return new Ap2Foundry($this->http, $tokens, self::ENDPOINT, self::MODEL, $authMode, 'key-1', Ap2AiPricing::haiku55(), $this->usage, false);
    }

    public static function prompt(string $name): VersionedPrompt
    {
        return (new MarkdownPromptRepository(dirname(__DIR__, 4) . '/PROMPTS'))->get($name, 'v1');
    }

    public static function nonce(): string
    {
        return self::NONCE;
    }

    /** The value at a path of keys inside nested arrays; fails the test when a step is not an array or key. */
    public static function dig(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new \RuntimeException('No ' . $key . ' in the value');
            }
            $value = $value[$key];
        }

        return $value;
    }

    /** @return array<mixed> */
    public static function digArray(mixed $value, int|string ...$path): array
    {
        $found = self::dig($value, ...$path);
        if (!is_array($found)) {
            throw new \RuntimeException('Not an array at ' . implode('.', $path));
        }

        return $found;
    }

    public static function digString(mixed $value, int|string ...$path): string
    {
        $found = self::dig($value, ...$path);
        if (!is_string($found)) {
            throw new \RuntimeException('Not a string at ' . implode('.', $path));
        }

        return $found;
    }

    /**
     * `$base` with `$over` laid on top, string keys only (named arguments of a test helper).
     *
     * @param array<string, mixed> $base
     * @param array<int|string, mixed> $over
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[(string) $key] = $value;
        }

        return $base;
    }

    /**
     * A structured-output answer as Haiku 5.5 sends it: an empty thinking block, then the JSON as text.
     *
     * @param array<string, mixed> $answer
     * @return array{status: int, body: string}
     */
    public static function json(array $answer, string $stopReason = 'end_turn', int $in = 1000, int $out = 200): array
    {
        return self::message([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'text', 'text' => json_encode($answer, JSON_THROW_ON_ERROR)]], $stopReason, $in, $out);
    }

    /**
     * @param list<array<string, mixed>> $content
     * @return array{status: int, body: string}
     */
    public static function message(array $content, string $stopReason = 'end_turn', int $in = 1000, int $out = 200): array
    {
        return ['status' => 200, 'body' => json_encode([
            'id' => 'msg_1',
            'model' => self::MODEL,
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ], JSON_THROW_ON_ERROR)];
    }

    /** @return array{status: int, body: string} */
    public static function text(string $text, string $stopReason = 'end_turn'): array
    {
        return self::message([['type' => 'text', 'text' => $text]], $stopReason);
    }

    /**
     * The payload of the n-th request (0-based).
     *
     * @return array<string, mixed>
     */
    public function payload(int $n = 0): array
    {
        $payload = $this->http->calls[$n]['payload'] ?? null;
        if (!is_array($payload)) {
            throw new \RuntimeException('No request ' . $n);
        }

        return $payload;
    }

    /** The user message text of the n-th request's last message, whether a string or blocks. */
    public function lastUserText(int $n = 0): string
    {
        $messages = $this->payload($n)['messages'] ?? [];
        $last = is_array($messages) ? end($messages) : null;
        $content = is_array($last) ? ($last['content'] ?? '') : '';
        if (is_string($content)) {
            return $content;
        }
        $text = '';
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return $text;
    }
}
