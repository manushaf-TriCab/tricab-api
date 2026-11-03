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
use TriCab\Core\Rfq\Parser\AliflexConversion;
use TriCab\Core\Rfq\Parser\QuoteExport;
use TriCab\Core\Rfq\Parser\QuotePlan;
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

    /** Refuses the next parse only, then answers normally. */
    public ?RfqParserFailure $firstParseFailure = null;

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

    /** @var list<array{rows: list<array<string, mixed>>, includePrices: bool}> */
    public array $exportCalls = [];

    public ?RfqParserFailure $exportFailure = null;

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
        if ($this->firstParseFailure !== null) {
            $failure = $this->firstParseFailure;
            $this->firstParseFailure = null;

            throw $failure;
        }
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

    /**
     * A small stand-in for the Python `quote_export`: one summary entry per
     * product code (in code order) and the quote cells, with a summary line
     * only for a product that has a sell price. The real shapes are asserted
     * in the Python tests; this one is read through the same strict
     * `QuoteExport::fromPayload`.
     */
    public function exportRows(CorrelationId $correlationId, array $rows, bool $includePrices): QuoteExport
    {
        $this->exportCalls[] = ['rows' => $rows, 'includePrices' => $includePrices];
        if ($this->exportFailure !== null) {
            throw $this->exportFailure;
        }
        $columns = ['Serial Number', 'Product Description', 'Product Code', 'Cuts', 'Cut Length', 'Total Length'];
        if ($includePrices) {
            $columns = [...$columns, 'Price', 'Total Price'];
        }
        $quote = [];
        $groups = [];
        foreach ($rows as $i => $row) {
            $code = (string) ($row['final_product_code'] ?? $row['selected_product_code'] ?? '');
            $length = is_array($row['length'] ?? null) ? $row['length'] : [];
            $cells = [(string) ($i + 1), (string) ($row['raw_text'] ?? ''), $code, (string) ($length['number_of_lengths'] ?? ''), (string) ($length['length_each'] ?? ''), (string) ($length['total_length'] ?? '')];
            if ($includePrices) {
                $cells = [...$cells, (string) ($row['final_unit_price'] ?? ''), (string) ($row['line_total'] ?? '')];
            }
            $quote[] = $cells;
            if ($code !== '') {
                $groups[strtoupper($code)][] = $i;
            }
        }
        ksort($groups);
        $summary = [];
        $summaryRows = [];
        foreach ($groups as $code => $indexes) {
            $total = 0.0;
            $sell = 0.0;
            $cuts = [];
            foreach ($indexes as $i) {
                $length = is_array($rows[$i]['length'] ?? null) ? $rows[$i]['length'] : [];
                $total += (float) ($length['total_length'] ?? 0);
                $sell += (float) ($rows[$i]['line_total'] ?? 0);
                $cuts[] = sprintf('(%s x %s)', $length['number_of_lengths'] ?? 1, $length['length_each'] ?? $length['total_length'] ?? 0);
            }
            $summary[] = [
                'product_code' => $code,
                'line_count' => count($indexes),
                'requested_total_length' => $total,
                'quoted_total_length' => $total,
                'line_indexes' => $indexes,
                'cost_status' => (string) ($rows[$indexes[0]]['pricing']['cost_status'] ?? ''),
                'unit_price' => $rows[$indexes[0]]['unit_price'] ?? null,
                'moq' => null,
                'moq_applied' => false,
                'prepack_applied' => false,
                'cuts_text' => implode(', ', $cuts),
            ];
            if ($sell > 0.0 && $total > 0.0) {
                $cells = ['1', 'Summary quote - ' . $code, $code, '1', (string) $total, (string) $total];
                $summaryRows[] = $includePrices ? [...$cells, (string) round($sell / $total, 3), (string) round($sell, 2)] : $cells;
            }
        }

        return QuoteExport::fromPayload([
            'contract' => ParserContract::VERSION,
            'correlationId' => $correlationId->toString(),
            'summary' => $summary,
            'quoteLines' => ['columns' => $columns, 'rows' => $quote],
            'summaryLines' => ['columns' => $columns, 'rows' => $summaryRows],
        ], $correlationId);
    }

    /** @var list<array{rows: list<array<string, mixed>>, quoteDetails: array<string, mixed>, label: string}> */
    public array $aliflexCalls = [];

    public ?RfqParserFailure $aliflexFailure = null;

    /** @var array<string, mixed>|null a conversion to answer instead of the stand-in one */
    public ?array $aliflexAnswer = null;

    /**
     * A small stand-in for the Python `aliflex.convert`: every row whose code
     * starts `XL-` is "changed" to the same code with `KL-` and its prices
     * cleared, the rest are unchanged. The real mapping is proved in the Python
     * tests; this answer is read through the same strict `AliflexConversion`.
     */
    public function aliflex(CorrelationId $correlationId, array $rows, array $quoteDetails, string $label): AliflexConversion
    {
        $this->aliflexCalls[] = ['rows' => $rows, 'quoteDetails' => $quoteDetails, 'label' => $label];
        if ($this->aliflexFailure !== null) {
            throw $this->aliflexFailure;
        }
        $counts = ['changed' => 0, 'unchanged' => 0, 'review' => 0];
        $out = [];
        foreach ($rows as $row) {
            unset($row['line_uid']);
            $code = (string) ($row['final_product_code'] ?? $row['selected_product_code'] ?? '');
            if (str_starts_with($code, 'XL-')) {
                ++$counts['changed'];
                $row['selected_product_code'] = 'KL-' . substr($code, 3);
                $row['final_product_code'] = 'KL-' . substr($code, 3);
                foreach (['pricing', 'unit_price', 'final_unit_price', 'margin_percent', 'line_total'] as $key) {
                    unset($row[$key]);
                }
                $row['line_note'] = trim(((string) ($row['line_note'] ?? '')) . '; ALIFLEX Alt', '; ');
                $row['aliflex'] = ['status' => 'changed', 'source_code' => $code];
            } else {
                ++$counts['unchanged'];
                $row['aliflex'] = ['status' => 'unchanged', 'source_code' => $code];
            }
            $out[] = $row;
        }
        $conversion = $this->aliflexAnswer ?? [
            'policy_version' => 'aliflex-map/2',
            'rows' => $out,
            'quote_details' => array_replace($quoteDetails, ['comment' => trim(((string) ($quoteDetails['comment'] ?? '')) . "\nALI-FLEX alternatives have been proposed.")]),
            'label' => 'ALI-FLEX alternative · ' . $label,
            'counts' => $counts,
            'message' => $counts['changed'] > 0 ? $counts['changed'] . ' line(s) changed to ALI-FLEX. Cable calculations must confirm sizing before ordering.' : 'No line matches the ALI-FLEX rule, so no alternative was made.',
        ];

        return AliflexConversion::fromPayload(['contract' => ParserContract::VERSION, 'correlationId' => $correlationId->toString(), 'conversion' => $conversion], $correlationId);
    }

    /** @var list<array{rows: list<array<string, mixed>>, quoteDetails: array<string, mixed>, margins: array<string, int|string>, today: string, existingRefno: ?string, version: ?int, origin: string}> */
    public array $syncPlanCalls = [];

    public ?RfqParserFailure $syncPlanFailure = null;

    /** @var array{status: int, payload: array<string, mixed>}|null what the parser says stops the quote; null for a plan */
    public ?array $syncProblem = null;

    /** @var array<string, mixed>|null a plan to answer instead of the stand-in one */
    public ?array $syncPlan = null;

    /**
     * A small stand-in for the Python `quote_sync`: one line per row that has a
     * code, priced at its sell price, under the header the details give, or
     * the plan / problem a test set. The real rules are in the Python tests;
     * this one is read through the same strict `QuotePlan::fromPayload`.
     */
    public function syncPlan(CorrelationId $correlationId, array $rows, array $quoteDetails, array $margins, string $today, ?string $existingRefno, ?int $version, string $origin): QuotePlan
    {
        $this->syncPlanCalls[] = ['rows' => $rows, 'quoteDetails' => $quoteDetails, 'margins' => $margins, 'today' => $today, 'existingRefno' => $existingRefno, 'version' => $version, 'origin' => $origin];
        if ($this->syncPlanFailure !== null) {
            throw $this->syncPlanFailure;
        }
        $body = ['contract' => ParserContract::VERSION, 'correlationId' => $correlationId->toString()];
        if ($this->syncProblem !== null) {
            return QuotePlan::fromPayload($body + ['problem' => $this->syncProblem], $correlationId);
        }
        $update = $existingRefno !== null && $version !== null;
        $items = [];
        foreach ($rows as $index => $row) {
            $code = (string) ($row['final_product_code'] ?? $row['selected_product_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $length = is_array($row['length'] ?? null) ? $row['length'] : [];
            $items[] = [
                'code' => $code,
                'quantity' => (float) ($length['total_length'] ?? $length['total_length_m'] ?? 1),
                'price' => (float) ($row['final_unit_price'] ?? 0),
                'cprc' => (float) ($row['unit_price'] ?? 0),
                'comment' => (string) ($row['line_note'] ?? ''),
                'unitsys' => ($row['line_kind'] ?? '') === 'accessory' ? 'U' : 'M',
                'row_number' => $index + 1,
                'row_id' => $row['line_uid'] ?? null,
            ];
        }
        $plan = $this->syncPlan ?? [
            'is_update' => $update,
            'existing_refno' => (string) $existingRefno,
            'push_version' => $update ? (string) $version : '0',
            'hdr_params' => [
                'ref' => $update ? (string) $existingRefno : '0',
                'cust' => (string) ($quoteDetails['customer_id'] ?? ''),
                'debtor' => 'TCA',
                'currency' => (string) ($quoteDetails['currency'] ?? 'AUD'),
                'unitsys' => 'M',
                'salesoffice' => (string) ($quoteDetails['salesoffice'] ?? ''),
                'payterms' => (string) ($quoteDetails['payterms'] ?? ''),
                'author' => (string) ($quoteDetails['author'] ?? ''),
                'validtill' => (string) ($quoteDetails['validtill'] ?? '09/10/26'),
                'carrier' => '',
                'contact' => (string) ($quoteDetails['contact'] ?? ''),
                'phone' => '',
                'comment' => $origin === 'portal' ? trim((string) ($quoteDetails['comment'] ?? '') . ' - Auto-created from RFQ portal by ' . ($quoteDetails['author'] ?? ''), ' -') : (string) ($quoteDetails['comment'] ?? ''),
                'cusref' => (string) ($quoteDetails['cusref'] ?? ''),
                'status' => 'DRAFT',
                'date' => substr($today, 8, 2) . '/' . substr($today, 5, 2) . '/' . substr($today, 2, 2),
                'version' => $update ? (string) $version : '0',
            ],
            'line_items' => $items,
            'excluded' => [],
            'fallback_margin' => [],
            'customer_id' => (string) ($quoteDetails['customer_id'] ?? ''),
            'cusref' => (string) ($quoteDetails['cusref'] ?? ''),
        ];

        return QuotePlan::fromPayload($body + ['plan' => $plan], $correlationId);
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
