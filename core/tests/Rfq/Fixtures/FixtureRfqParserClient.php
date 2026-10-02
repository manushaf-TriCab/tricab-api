<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use RuntimeException;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\PricedRows;
use TriCab\Core\Rfq\Parser\QuoteReadiness;
use TriCab\Core\Rfq\Parser\ReadRequest;
use TriCab\Core\Rfq\Parser\ReadResponse;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\ScreenshotText;
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

    /** @var list<list<array{text: string, productCode: string}>> */
    public array $correctionKeyCalls = [];

    /** @var list<string>|null product codes the catalogue carries, for correction keys; null for all */
    public ?array $catalogue = null;

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

    /** @var list<array{string, string, int}> */
    public array $screenshots = [];

    public ?RfqParserFailure $priceFailure = null;

    /** @var array<string, float> cost per metre by product code; any other code is not carried */
    public array $costs = [];

    /** @var list<array{rows: list<array<string, mixed>>, region: string, currency: string, defaultMargins: array<string, int|float|string>, reprice: bool}> */
    public array $priceCalls = [];

    /** @var array<string, string> style by product code */
    public array $styles = [];

    public function readScreenshot(CorrelationId $correlationId, string $fileName, string $contentType, string $bytes): ScreenshotText
    {
        $this->screenshots[] = [$fileName, $contentType, strlen($bytes)];

        return ScreenshotText::fromPayload([
            'contract' => ParserContract::VERSION,
            'correlationId' => $correlationId->toString(),
            'text' => "4C 16mm2 XLPE/PVC 120m\n3C 2.5mm TPS 4 x 55m",
            'rowCount' => 2,
            'confidence' => 0.93,
            'model' => 'fixture-vision',
            'escalated' => false,
        ], $correlationId);
    }

    public function priceRows(CorrelationId $correlationId, array $rows, string $region, string $currency, array $defaultMargins, bool $reprice): PricedRows
    {
        $this->priceCalls[] = ['rows' => $rows, 'region' => $region, 'currency' => $currency, 'defaultMargins' => $defaultMargins, 'reprice' => $reprice];
        if ($this->priceFailure !== null) {
            throw $this->priceFailure;
        }
        $unpriced = 0;
        foreach ($rows as $i => $row) {
            $code = strtoupper((string) ($row['final_product_code'] ?? $row['selected_product_code'] ?? ''));
            if ($reprice) {
                $cost = $this->costs[$code] ?? null;
                $row['pricing'] = ['status' => $cost === null ? 'not_found' : 'available', 'unit_price' => $cost, 'region' => $region, 'quote_currency' => $currency, 'cost_status' => $cost === null ? null : 'STOCK'];
                $row['unit_price'] = $cost;
            }
            $locked = ($row['margin_locked'] ?? false) === true;
            $margin = $locked ? (int) ($row['margin_percent'] ?? 0) : (int) ($defaultMargins[$code] ?? $row['margin_percent'] ?? 0);
            $row['margin_percent'] = $margin;
            $cost = $row['unit_price'] ?? null;
            if (($row['sell_price_locked'] ?? false) !== true) {
                $row['final_unit_price'] = $cost === null ? null : ceil((float) $cost * 100 / (100 - $margin) * 1000 - 1e-6) / 1000;
            }
            $total = $row['length']['total_length'] ?? $row['length']['total_length_m'] ?? null;
            $row['line_total'] = ($row['final_unit_price'] ?? null) === null || $total === null ? null : round((float) $total * (float) $row['final_unit_price'], 2);
            if ($cost === null && $code !== '') {
                ++$unpriced;
            }
            $rows[$i] = $row;
        }

        return PricedRows::fromPayload(['contract' => ParserContract::VERSION, 'correlationId' => $correlationId->toString(), 'rows' => array_values($rows), 'unpriced' => $unpriced], $correlationId, count($rows));
    }

    public function productStyles(CorrelationId $correlationId, array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            if (isset($this->styles[strtoupper($code)])) {
                $out[strtoupper($code)] = $this->styles[strtoupper($code)];
            }
        }

        return $out;
    }

    /**
     * Keys as the source makes them, near enough for a test: the text lower-cased
     * with its spaces collapsed. Codes not in `$catalogue` (when set) are skipped.
     */
    public function correctionKeys(CorrelationId $correlationId, array $lines): array
    {
        $this->correctionKeyCalls[] = $lines;
        $out = [];
        foreach ($lines as $index => $line) {
            $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $line['text'])));
            $code = strtoupper(trim($line['productCode']));
            if ($key === '' || $code === '' || ($this->catalogue !== null && !in_array($code, $this->catalogue, true))) {
                continue;
            }
            $out[] = ['index' => $index, 'key' => $key, 'productCode' => $code];
        }

        return $out;
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
