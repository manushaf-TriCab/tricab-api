<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use RuntimeException;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\QuoteReadiness;
use TriCab\Core\Rfq\Parser\ReadRequest;
use TriCab\Core\Rfq\Parser\ReadResponse;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\PythonJson;

/**
 * A parser that answers every parse with the real parser's recorded answer
 * (`parser-v1-paste.json`, `generate_parser_v1_paste.py`), read through the
 * same strict `ParseResponse::fromPayload` the HTTP client uses.
 *
 * Readiness answers `$ready`, or throws `$readinessFailure`. Every call is
 * recorded so a test can see what PHP asked.
 */
final class FixtureRfqParserClient implements RfqParserClient
{
    public ?RfqParserFailure $parseFailure = null;

    public bool $ready = false;

    public ?RfqParserFailure $readinessFailure = null;

    /** @var list<ParseRequest> */
    public array $parses = [];

    /**
     * Fields merged over the recorded answer for a documents parse, such as
     * `documents` (per-document summaries) and `coverage`.
     *
     * @var array<string, mixed>
     */
    public array $documentsAnswer = [];

    /** @var list<ReadRequest> */
    public array $reads = [];

    public ?RfqParserFailure $readFailure = null;

    /** @var list<array<string, mixed>>|null lines a read returns; null for the recorded paste lines */
    public ?array $readLines = null;

    /** @var list<array{rows: list<array<string, mixed>>, quoteDetails: array<string, mixed>}> */
    public array $readinessCalls = [];

    /** @return array<string, mixed> */
    public static function payload(): array
    {
        $json = file_get_contents(__DIR__ . '/parser-v1-paste.json');
        if ($json === false) {
            throw new RuntimeException('parser-v1-paste.json is missing.');
        }
        $payload = PythonJson::decode($json);
        if (!is_array($payload)) {
            throw new RuntimeException('parser-v1-paste.json is not an object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @return list<array<string, mixed>> */
    public static function lines(): array
    {
        return ParseResponse::fromPayload(
            ['correlationId' => 'fixture-correlation-0001'] + self::payload(),
            CorrelationId::fromHeaderOrGenerate('fixture-correlation-0001'),
        )->lines;
    }

    public function parse(ParseRequest $request): ParseResponse
    {
        $this->parses[] = $request;
        if ($this->parseFailure !== null) {
            throw $this->parseFailure;
        }

        $answer = self::payload();
        if ($request->documents !== []) {
            $answer = array_merge($answer, $this->documentsAnswer);
        }

        return ParseResponse::fromPayload(
            ['correlationId' => $request->correlationId->toString()] + $answer,
            $request->correlationId,
        );
    }

    public function read(ReadRequest $request): ReadResponse
    {
        $this->reads[] = $request;
        if ($this->readFailure !== null) {
            throw $this->readFailure;
        }
        $lines = $this->readLines ?? array_map(static function (array $line) use ($request): array {
            $line['source'] = ['input_type' => 'attachment_reader', 'file_name' => $request->fileName, 'row_number' => 1];

            return $line;
        }, self::lines());

        return ReadResponse::fromPayload([
            'contract' => 'rfq-parser.v1',
            'correlationId' => $request->correlationId->toString(),
            'lines' => $lines,
            'notes' => ['Read by gpt-test.'],
            'degraded' => false,
            'provider' => ['mode' => 'model', 'modelCalls' => 0, 'model' => 'gpt-test', 'models' => ['reader' => 'gpt-test'], 'answeredBy' => 'reader'],
        ], $request->correlationId);
    }

    public function readiness(CorrelationId $correlationId, array $rows, array $quoteDetails): QuoteReadiness
    {
        $this->readinessCalls[] = ['rows' => $rows, 'quoteDetails' => $quoteDetails];
        if ($this->readinessFailure !== null) {
            throw $this->readinessFailure;
        }

        return QuoteReadiness::fromPayload([
            'contract' => 'rfq-parser.v1',
            'correlationId' => $correlationId->toString(),
            'readiness' => ['ready' => $this->ready, 'blocking_issues' => [], 'row_states' => []],
        ], $correlationId);
    }

    /** @var list<array{string, int}> */
    public array $searches = [];

    public function searchProducts(CorrelationId $correlationId, string $query, int $limit): array
    {
        $this->searches[] = [$query, $limit];

        return [['code' => 'XL-PAXN/4C16OR', 'style' => 'XL-PAXN', 'description' => '4C 16mm2 XLPE/PVC']];
    }

    public function isAlive(): bool
    {
        return true;
    }

    public function isReady(): bool
    {
        return true;
    }
}
