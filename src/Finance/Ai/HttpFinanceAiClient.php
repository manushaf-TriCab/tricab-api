<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ai;

use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiContract;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Ai\FinanceAiResult;

/**
 * The finance AI service over loopback HTTP: `core/python/finance_ai` on
 * 127.0.0.1:8003 (`startup.sh`), contract `finance-ai.v1`. The same hop as
 * `HttpRfqParserClient`, throwing a typed {@see FinanceAiFailure}.
 *
 * The socket timeout is the deadline plus `TRANSPORT_GRACE_MS`, set on the injected
 * client by the container. The stream client reports a refused connection and an
 * expired read the same way, so the two are told apart by how long the call took.
 *
 * Neither the token, nor the data, nor the answer is ever logged; the correlation id is.
 */
final class HttpFinanceAiClient implements FinanceAiClient
{
    /**
     * @param (callable(): float)|null $clock seconds, monotonic; `hrtime` unless a test fixes it
     */
    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $baseUrl,
        private readonly string $internalToken,
        private readonly mixed $clock = null,
        /** @var array<string, string> */
        private readonly array $models = [],
    ) {
    }

    public function complete(string $purpose, array $data, ?int $deadlineMs = null): FinanceAiResult
    {
        $id = bin2hex(random_bytes(16));
        if (preg_match(FinanceAiContract::PURPOSE_PATTERN, $purpose) !== 1) {
            throw FinanceAiFailure::of(FinanceAiFailure::INVALID_REQUEST, 'That is not a purpose name.', $id);
        }
        $deadline = $deadlineMs ?? FinanceAiContract::DEFAULT_DEADLINE_MS;
        if ($deadline < FinanceAiContract::MIN_DEADLINE_MS || $deadline > FinanceAiContract::MAX_DEADLINE_MS) {
            throw FinanceAiFailure::of(FinanceAiFailure::INVALID_REQUEST, 'The deadline is out of range.', $id);
        }

        $started = $this->now();
        try {
            $model = $this->models[$purpose] ?? null;
            $response = $this->http->postJson(
                $this->url(FinanceAiContract::PATH_COMPLETE . $purpose),
                $this->headers($id),
                [
                    'contract' => FinanceAiContract::VERSION,
                    'correlationId' => $id,
                    'deadlineMs' => $deadline,
                    'data' => $data === [] ? new \stdClass() : $data,
                    ...($model !== null ? ['model' => $model] : []),
                ],
            );
        } catch (Throwable) {
            $elapsedMs = (int) (($this->now() - $started) * 1000);
            if ($elapsedMs >= $deadline) {
                error_log(sprintf('finance AI timed out after %d ms (purpose %s, correlation %s)', $elapsedMs, $purpose, $id));

                throw FinanceAiFailure::of(FinanceAiFailure::TIMEOUT, 'The finance AI service did not answer in time.', $id);
            }
            error_log(sprintf('finance AI unreachable (purpose %s, correlation %s)', $purpose, $id));

            throw FinanceAiFailure::of(FinanceAiFailure::UNAVAILABLE, 'The finance AI service is not answering. Try again in a minute.', $id);
        }

        $body = $this->decode($response['body']);
        if ($body === null) {
            error_log(sprintf('finance AI answered HTTP %d with no readable body (purpose %s, correlation %s)', $response['status'], $purpose, $id));

            throw FinanceAiFailure::of(
                $response['status'] === 502 || $response['status'] === 503 ? FinanceAiFailure::UNAVAILABLE : FinanceAiFailure::INTERNAL_ERROR,
                'The finance AI service returned an unreadable answer.',
                $id,
            );
        }
        if ($response['status'] === 401) {
            // The token does not match: a deployment fault, never the person's.
            error_log(sprintf('finance AI refused the internal token (correlation %s)', $id));

            throw FinanceAiFailure::of(FinanceAiFailure::INTERNAL_ERROR, 'The finance AI service is misconfigured.', $id);
        }

        if (isset($body['error']) && is_array($body['error'])) {
            $code = is_string($body['error']['code'] ?? null) ? $body['error']['code'] : FinanceAiFailure::INTERNAL_ERROR;
            $message = is_string($body['error']['message'] ?? null) ? mb_substr($body['error']['message'], 0, 300) : 'The finance AI service failed.';

            throw FinanceAiFailure::of($code, $message, $id);
        }

        $result = $body['result'] ?? null;
        $provider = $body['provider'] ?? null;
        if (
            ($body['contract'] ?? null) !== FinanceAiContract::VERSION
            || ($body['correlationId'] ?? null) !== $id
            || ($body['purpose'] ?? null) !== $purpose
            || !is_array($result)
            || !is_array($provider)
            || !is_string($provider['name'] ?? null)
            || !is_string($provider['model'] ?? null)
        ) {
            throw FinanceAiFailure::of(FinanceAiFailure::CONTRACT_MISMATCH, 'The finance AI service answered in a form this platform does not understand.', $id);
        }

        return new FinanceAiResult($purpose, $result, $id, $provider['name'], $provider['model']);
    }

    public function isAlive(): bool
    {
        $id = bin2hex(random_bytes(16));
        try {
            $response = $this->http->getJson($this->url(FinanceAiContract::PATH_HEALTH), $this->headers($id));
        } catch (Throwable) {
            return false;
        }
        $body = $this->decode($response['body']);

        return $response['status'] === 200
            && $body !== null
            && ($body['contract'] ?? null) === FinanceAiContract::VERSION
            && !isset($body['error']);
    }

    /** @return array<string, string> */
    private function headers(string $correlationId): array
    {
        return [
            FinanceAiContract::HEADER_CONTRACT => FinanceAiContract::VERSION,
            FinanceAiContract::HEADER_CORRELATION_ID => $correlationId,
            FinanceAiContract::HEADER_AUTHORIZATION => 'Bearer ' . $this->internalToken,
        ];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    /** @return array<array-key, mixed>|null */
    private function decode(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1e9;
    }
}
