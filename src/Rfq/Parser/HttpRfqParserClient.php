<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Parser;

use JsonException;
use stdClass;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\ProductMatches;
use TriCab\Core\Rfq\Parser\QuoteReadiness;
use TriCab\Core\Rfq\Parser\ReadRequest;
use TriCab\Core\Rfq\Parser\ReadResponse;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;
use TriCab\Core\Rfq\Storage\PythonJson;

/**
 * The RFQ parser over loopback HTTP: `core/python/rfq_parser` on
 * 127.0.0.1:8002 (`startup.sh`), contract `rfq-parser.v1`.
 *
 * Same hop as `HttpProductFinderClient` — same instance, no nginx location,
 * plain HTTP on loopback — with the opposite failure posture: this **throws**
 * a typed {@see RfqParserFailure} (see {@see RfqParserClient} for why).
 *
 * The socket timeout is the interactive deadline plus
 * `RfqServiceLimits::TRANSPORT_GRACE_MS`, set on the injected client. The
 * stream client reports a refused connection and an expired read the same
 * way, so the two are told apart by how long the call took.
 *
 * **JSON keeps the source's shape.** Answers are read with `PythonJson`, so a
 * row's `{}` stays an object, `NaN` survives and a large integer keeps its
 * digits; `json_decode` would turn the first into a list and refuse the
 * second. Rows sent back (readiness) travel as one `PythonJson`-encoded
 * string for the same reason.
 *
 * Neither the token nor a request body is ever logged; the correlation id is.
 */
final class HttpRfqParserClient implements RfqParserClient
{
    /**
     * @param (callable(): float)|null $clock seconds, monotonic; `hrtime` unless a test fixes it
     */
    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $baseUrl,
        private readonly string $internalToken,
        private readonly mixed $clock = null,
    ) {
    }

    public function parse(ParseRequest $request): ParseResponse
    {
        $body = $this->post(ParserContract::PATH_PARSE, $request->toPayload(), $request->correlationId, $request->deadlineMs);

        return ParseResponse::fromPayload($body, $request->correlationId);
    }

    public function read(ReadRequest $request): ReadResponse
    {
        $body = $this->post(ParserContract::PATH_READ, $request->toPayload(), $request->correlationId, $request->deadlineMs);

        return ReadResponse::fromPayload($body, $request->correlationId);
    }

    public function readiness(CorrelationId $correlationId, array $rows, array $quoteDetails): QuoteReadiness
    {
        $body = $this->post(ParserContract::PATH_READINESS, [
            'contract' => ParserContract::VERSION,
            'correlationId' => $correlationId->toString(),
            'draftJson' => PythonJson::encode([
                'rows' => $rows,
                'quote_details' => $quoteDetails === [] ? new stdClass() : $quoteDetails,
            ]),
        ], $correlationId, RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS);

        return QuoteReadiness::fromPayload($body, $correlationId);
    }

    public function searchProducts(CorrelationId $correlationId, string $query, int $limit): array
    {
        $body = $this->post(ParserContract::PATH_PRODUCT_SEARCH, [
            'contract' => ParserContract::VERSION,
            'correlationId' => $correlationId->toString(),
            'q' => $query,
            'limit' => $limit,
        ], $correlationId, RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS);

        return ProductMatches::fromPayload($body, $correlationId);
    }

    public function isAlive(): bool
    {
        return $this->probe(ParserContract::PATH_HEALTH);
    }

    public function isReady(): bool
    {
        return $this->probe(ParserContract::PATH_READY);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function post(string $path, array $payload, CorrelationId $id, int $deadlineMs): array
    {
        $started = $this->now();
        try {
            $response = $this->http->postJson($this->url($path), $this->headers($id, $deadlineMs), $payload);
        } catch (Throwable) {
            $elapsedMs = (int) (($this->now() - $started) * 1000);
            if ($elapsedMs >= $deadlineMs) {
                error_log(sprintf('RFQ parser timed out after %d ms (correlation %s)', $elapsedMs, $id->toString()));

                throw RfqParserFailure::of(RfqParserFailure::TIMEOUT, 'The RFQ parser did not answer in time. Try a shorter paste, or try again.', $id);
            }
            error_log(sprintf('RFQ parser unreachable (correlation %s)', $id->toString()));

            throw RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'The RFQ parser is not answering. Try again in a minute.', $id);
        }

        $decoded = $this->decode($response['body']);
        if ($decoded === null) {
            error_log(sprintf('RFQ parser answered HTTP %d with no readable body (correlation %s)', $response['status'], $id->toString()));

            throw RfqParserFailure::of(
                $response['status'] === 502 || $response['status'] === 503 ? RfqParserFailure::UNAVAILABLE : RfqParserFailure::INTERNAL_ERROR,
                'The RFQ parser returned an unreadable answer.',
                $id,
            );
        }
        if ($response['status'] === 401) {
            // The token does not match: a deployment fault, never the person's.
            error_log(sprintf('RFQ parser refused the internal token (correlation %s)', $id->toString()));

            throw RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser is misconfigured.', $id);
        }

        return $decoded;
    }

    private function probe(string $path): bool
    {
        $id = CorrelationId::generate();
        try {
            $response = $this->http->getJson($this->url($path), $this->headers($id, RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS));
        } catch (Throwable) {
            return false;
        }
        $decoded = $this->decode($response['body']);

        return $response['status'] === 200
            && $decoded !== null
            && ($decoded['contract'] ?? null) === ParserContract::VERSION
            && !isset($decoded['error']);
    }

    /** @return array<string, string> */
    private function headers(CorrelationId $id, int $deadlineMs): array
    {
        return [
            ParserContract::HEADER_CONTRACT => ParserContract::VERSION,
            ParserContract::HEADER_CORRELATION_ID => $id->toString(),
            ParserContract::HEADER_DEADLINE_MS => (string) $deadlineMs,
            ParserContract::HEADER_AUTHORIZATION => 'Bearer ' . $this->internalToken,
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
            $decoded = PythonJson::decode($body);
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
