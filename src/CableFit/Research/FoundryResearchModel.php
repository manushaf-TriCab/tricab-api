<?php

declare(strict_types=1);

namespace TriCab\Api\CableFit\Research;

use Closure;
use Throwable;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\CableFit\Contract\ResearchModel;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchModelFailed;
use TriCab\Core\CableFit\Domain\ResearchModelResponse;

/**
 * Claude on Foundry with the web search server tool, over `{endpoint}/anthropic/v1/messages` with the
 * platform's Foundry connection (managed identity, or an API key in development).
 *
 * The tool is `web_search_20250305` with `allowed_domains`; the `_20260209` variant is a 400 on this
 * deployment (C3.1). `tool_choice` is left on auto: forcing a tool is not allowed with this tool.
 * When the model pauses (`pause_turn`) the conversation is continued, a few times at most. A cut-off
 * (`max_tokens`), a refusal, an HTTP error or running past the time budget is a {@see ResearchModelFailed}.
 */
final class FoundryResearchModel implements ResearchModel
{
    public const TOOL_TYPE = 'web_search_20250305';

    private const ANTHROPIC_VERSION = '2023-06-01';

    // Thinking counts toward max_tokens on models that think by default; this is a ceiling, not a cost.
    private const MAX_OUTPUT_TOKENS = 16_000;

    private const MAX_TURNS = 5;

    private const MAX_RETRY_DELAY_SECONDS = 5;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param int                      $timeoutSeconds budget for the whole exchange, and the limit on each HTTP call
     * @param (Closure(): float)|null  $clock          seconds, injectable so tests need not wait
     */
    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly FoundryAccessTokenProvider $accessTokens,
        private readonly string $endpoint,
        private readonly string $deployment,
        private readonly string $authMode,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 300,
        ?Closure $clock = null,
        private readonly bool $pauseBetweenRetries = true,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function research(string $system, string $user, array $allowedDomains, int $maxSearches): ResearchModelResponse
    {
        $messages = [['role' => 'user', 'content' => $user]];
        $content = [];
        $cost = new ResearchCost($this->deployment, 0, 0, 0);
        $startedAt = ($this->clock)();

        for ($turn = 1; $turn <= self::MAX_TURNS; $turn++) {
            if (($this->clock)() - $startedAt >= $this->timeoutSeconds) {
                throw new ResearchModelFailed('The research took longer than ' . $this->timeoutSeconds . ' seconds.');
            }
            $decoded = $this->post([
                'model' => $this->deployment,
                'max_tokens' => self::MAX_OUTPUT_TOKENS,
                'system' => $system,
                'tools' => [[
                    'type' => self::TOOL_TYPE,
                    'name' => 'web_search',
                    'max_uses' => $maxSearches,
                    'allowed_domains' => $allowedDomains,
                ]],
                'messages' => $messages,
            ]);

            $blocks = [];
            $raw = $decoded['content'] ?? null;
            foreach (is_array($raw) ? $raw : [] as $block) {
                if (is_array($block)) {
                    /** @var array<string, mixed> $block */
                    $blocks[] = $block;
                }
            }
            array_push($content, ...$blocks);
            $cost = $cost->plus(ResearchModelResponse::costOf($decoded));

            $stop = is_string($decoded['stop_reason'] ?? null) ? $decoded['stop_reason'] : '';
            switch ($stop) {
                case 'end_turn':
                case 'stop_sequence':
                    return new ResearchModelResponse($content, $cost);
                case 'pause_turn':
                    $messages[] = ['role' => 'assistant', 'content' => $blocks];
                    break;
                case 'max_tokens':
                    throw new ResearchModelFailed('The model ran out of output space before it finished.');
                case 'refusal':
                    throw new ResearchModelFailed('The model declined the request.');
                default:
                    throw new ResearchModelFailed('The model stopped unexpectedly (' . ($stop === '' ? 'no stop reason' : $stop) . ').');
            }
        }

        throw new ResearchModelFailed('The model did not finish after ' . self::MAX_TURNS . ' rounds.');
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        $url = rtrim($this->endpoint, '/') . '/anthropic/v1/messages';
        $headers = $this->headers();
        $response = ['status' => 0, 'body' => ''];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $this->http->postJson($url, $headers, $payload);
            } catch (Throwable) {
                throw new ResearchModelFailed('The model could not be reached or took too long.');
            }
            // Throttled or overloaded: one retry, honouring Retry-After (capped).
            if (($response['status'] === 429 || $response['status'] === 529) && $attempt === 1) {
                $this->pause($response);
                continue;
            }
            break;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ResearchModelFailed('The model refused the request (HTTP ' . $response['status'] . self::errorMessage($response['body']) . ').');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ResearchModelFailed('The model answered with something that was not JSON.');
        }
        if (!is_array($decoded)) {
            throw new ResearchModelFailed('The model answered with something that was not JSON.');
        }
        /** @var array<string, mixed> $decoded */

        return $decoded;
    }

    /** The service's own error text (never a secret), short, for the run's error line. */
    private static function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $message = $error['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? ': ' . mb_substr(trim($message), 0, 200) : '';
    }

    /** @param array{status: int, body: string, headers?: array<string, string>} $response */
    private function pause(array $response): void
    {
        $after = ($response['headers'] ?? [])['retry-after'] ?? null;
        $seconds = is_string($after) && preg_match('/^\d+$/', trim($after)) === 1 ? (int) trim($after) : 1;
        if ($this->pauseBetweenRetries) {
            sleep(max(1, min(self::MAX_RETRY_DELAY_SECONDS, $seconds)));
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = ['anthropic-version' => self::ANTHROPIC_VERSION];
        if ($this->authMode === Settings::FOUNDRY_AUTH_MODE_API_KEY) {
            if (trim($this->apiKey) === '') {
                throw new ResearchModelFailed('The Foundry API key is not configured.');
            }
            $headers['x-api-key'] = $this->apiKey;

            return $headers;
        }
        try {
            $headers['Authorization'] = 'Bearer ' . $this->accessTokens->accessToken();
        } catch (Throwable) {
            throw new ResearchModelFailed('The Foundry access token could not be obtained.');
        }

        return $headers;
    }
}
