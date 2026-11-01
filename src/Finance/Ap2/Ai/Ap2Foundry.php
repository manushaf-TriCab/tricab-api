<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Ai;

use Throwable;
use TriCab\Api\Assistant\Infrastructure\Foundry\FoundryAccessTokenProvider;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiPricing;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUsage;
use TriCab\Core\Finance\Ap2\Contract\Ap2AiUsageLog;

/**
 * Tri-AP's connection to Claude on Foundry, over `/anthropic/v1/messages` with the platform's Foundry connection
 * (managed identity, or an API key locally), as {@see \TriCab\Api\Finance\Task\FoundryTaskDrafter} does. One instance
 * per purpose, each with its own deployment (FINANCE_AP2_AI_*_MODEL).
 *
 * {@see self::call()} runs one use of the model (one or more requests: the Copilot loops over its tools), adds up the
 * tokens and cost of every request, and logs that once, whether the call succeeded or failed. Answers are structured
 * outputs or `auto` tool use; `tool_choice` is never forced, as the 5.5 models answer 400 to it.
 */
final class Ap2Foundry
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const MAX_RETRY_DELAY_SECONDS = 5;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly FoundryAccessTokenProvider $accessTokens,
        private readonly string $endpoint,
        private readonly string $deployment,
        private readonly string $authMode,
        private readonly string $apiKey,
        private readonly Ap2AiPricing $pricing,
        private readonly Ap2AiUsageLog $usageLog,
        private readonly bool $pauseBetweenRetries = true,
    ) {
    }

    public function deployment(): string
    {
        return $this->deployment;
    }

    /**
     * Runs `$work` with a metered request function and logs what it used. A failure inside `$work` that is not an
     * {@see Ap2AiUnavailable} becomes one, so no provider text reaches a person.
     *
     * @template T
     * @param callable(Ap2FoundryCall): T $work
     * @return T
     */
    public function call(string $purpose, string $promptVersion, string $failurePrefix, callable $work): mixed
    {
        $call = new Ap2FoundryCall($this, $purpose, $promptVersion, $failurePrefix);
        try {
            $result = $work($call);
        } catch (Ap2AiUnavailable $failure) {
            $this->log($call->usage()->failed());
            throw $failure;
        } catch (Throwable $failure) {
            $this->log($call->usage()->failed());
            throw $call->failed('the answer could not be read', $failure);
        }
        $this->log($call->usage());

        return $result;
    }

    /**
     * One request, with one retry on a throttle (429) or overload (529), honouring Retry-After up to 5 s.
     *
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, headers?: array<string, string>, ms: int}
     * @internal for {@see Ap2FoundryCall}
     */
    public function post(array $payload, Ap2FoundryCall $call): array
    {
        $started = hrtime(true);
        $response = $this->postOnce($payload, $call);
        if ($response['status'] === 429 || $response['status'] === 529) {
            $this->pause($response);
            $response = $this->postOnce($payload, $call);
        }

        return $response + ['ms' => max(0, intdiv(hrtime(true) - $started, 1_000_000))];
    }

    /** @internal for {@see Ap2FoundryCall} */
    public function costOf(int $input, int $output, int $cacheRead, int $cacheWrite): float
    {
        return $this->pricing->cost($input, $output, $cacheRead, $cacheWrite);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string, headers?: array<string, string>}
     */
    private function postOnce(array $payload, Ap2FoundryCall $call): array
    {
        try {
            return $this->http->postJson(rtrim($this->endpoint, '/') . '/anthropic/v1/messages', $this->headers(), $payload);
        } catch (Throwable $failure) {
            throw $call->failed('the model could not be reached or took too long', $failure);
        }
    }

    private function log(Ap2AiUsage $usage): void
    {
        try {
            $this->usageLog->record($usage);
        } catch (Throwable) {
            // Measuring must never fail the call it measures.
        }
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
                throw new \RuntimeException('Foundry API key is not configured.');
            }
            $headers['x-api-key'] = $this->apiKey;

            return $headers;
        }
        $headers['Authorization'] = 'Bearer ' . $this->accessTokens->accessToken();

        return $headers;
    }
}
