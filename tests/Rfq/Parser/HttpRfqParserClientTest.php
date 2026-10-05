<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Parser;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use TriCab\Api\Rfq\Parser\HttpRfqParserClient;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * Plan 3.7: PHP → the parser over loopback, contract `rfq-parser.v1`.
 * The Python half of the same contract is `core/python/tests/rfq_parser/test_contract.py`.
 */
final class HttpRfqParserClientTest extends TestCase
{
    private const TOKEN = 'internal-token-for-tests';

    private CorrelationId $id;

    protected function setUp(): void
    {
        $this->id = CorrelationId::fromHeaderOrGenerate('corr-client-000001');
    }

    private static function fixture(string $correlationId): string
    {
        $json = file_get_contents(dirname(__DIR__, 3) . '/core/tests/Rfq/Fixtures/parser-v1-paste.json');
        self::assertIsString($json);

        return str_replace('fixture-correlation-0001', $correlationId, $json);
    }

    /** @param list<float> $times */
    private function client(FakeJsonHttpClient $http, array $times = [0.0, 0.1]): HttpRfqParserClient
    {
        return new HttpRfqParserClient($http, 'http://127.0.0.1:8002/', self::TOKEN, static function () use (&$times): float {
            return array_shift($times) ?? 0.0;
        });
    }

    public function test_a_parse_sends_the_contract_and_reads_the_answer(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = self::fixture('corr-client-000001');

        $response = $this->client($http)->parse(ParseRequest::forText($this->id, "4C 16mm2\n3C 2.5mm", false));

        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8002/v1/parse', $call['url']);
        self::assertSame([
            'X-Rfq-Contract' => 'rfq-parser.v1',
            'X-Correlation-Id' => 'corr-client-000001',
            'X-Rfq-Deadline-Ms' => '60000',
            'Authorization' => 'Bearer ' . self::TOKEN,
        ], $call['headers']);
        self::assertSame("4C 16mm2\n3C 2.5mm", $call['payload']['text'] ?? null);
        $options = $call['payload']['options'] ?? null;
        self::assertIsArray($options);
        self::assertFalse($options['allowModel']);

        self::assertCount(3, $response->lines);
        self::assertTrue($response->isCoverageComplete());
        // The unresolved line's empty `fields` stays an object, as the source stores it.
        self::assertInstanceOf(stdClass::class, $response->lines[2]['fields']);
    }

    public function test_an_answer_to_another_request_is_refused(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = self::fixture('somebody-elses-request');

        $this->expectExceptionObject(RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned an unreadable result (correlationId).'));
        $this->client($http)->parse(ParseRequest::forText($this->id, '4C 16mm2', false));
    }

    public function test_the_parsers_typed_refusal_is_kept(): void
    {
        $http = new FakeJsonHttpClient();
        $http->status = 415;
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "error": {"code": "unsupported_format", "message": "Documents are not accepted yet."}}';

        try {
            $this->client($http)->parse(ParseRequest::forText($this->id, '4C 16mm2', false));
            self::fail('expected a failure');
        } catch (RfqParserFailure $failure) {
            self::assertSame('unsupported_format', $failure->failureCode);
            self::assertSame(415, $failure->httpStatus());
            self::assertSame('corr-client-000001', $failure->correlationId);
        }
    }

    public function test_a_refused_connection_is_unavailable_and_retryable(): void
    {
        $http = new FakeJsonHttpClient();
        $http->transportError = new RuntimeException('HTTP request failed.');

        try {
            $this->client($http, [0.0, 0.01])->parse(ParseRequest::forText($this->id, '4C 16mm2', false));
            self::fail('expected a failure');
        } catch (RfqParserFailure $failure) {
            self::assertSame('unavailable', $failure->failureCode);
            self::assertTrue($failure->isRetryable());
        }
    }

    public function test_a_read_that_ran_past_the_deadline_is_a_timeout(): void
    {
        $http = new FakeJsonHttpClient();
        $http->transportError = new RuntimeException('HTTP request failed.');

        try {
            $this->client($http, [0.0, 65.0])->parse(ParseRequest::forText($this->id, '4C 16mm2', false));
            self::fail('expected a failure');
        } catch (RfqParserFailure $failure) {
            self::assertSame('timeout', $failure->failureCode);
            self::assertSame(504, $failure->httpStatus());
        }
    }

    public function test_a_wrong_token_is_a_deployment_fault_not_the_persons(): void
    {
        $http = new FakeJsonHttpClient();
        $http->status = 401;
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": null, "error": {"code": "invalid_request", "message": "The caller is not the platform API."}}';

        try {
            $this->client($http)->parse(ParseRequest::forText($this->id, '4C 16mm2', false));
            self::fail('expected a failure');
        } catch (RfqParserFailure $failure) {
            self::assertSame('internal_error', $failure->failureCode);
            self::assertSame('The RFQ parser is misconfigured.', $failure->getMessage());
        }
    }

    public function test_readiness_sends_the_rows_as_the_store_holds_them(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "readiness": {"ready": false, "blocking_issues": [{"issue": "missing_header", "field": "contact"}], "row_states": []}}';

        $rows = [['line_uid' => 'a', 'confidence' => NAN, 'fields' => new stdClass()]];
        $readiness = $this->client($http)->readiness($this->id, $rows, []);

        self::assertFalse($readiness->ready);
        self::assertSame('contact', $readiness->blockingIssues[0]['field']);
        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8002/v1/readiness', $call['url']);
        self::assertSame(
            '{"rows": [{"line_uid": "a", "confidence": NaN, "fields": {}}], "quote_details": {}}',
            $call['payload']['draftJson'] ?? null,
        );
    }

    public function test_correction_keys_are_asked_of_the_parser_and_checked(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "entries": [{"index": 0, "key": "4c 16mm2", "productCode": "XL-PAXN/4C16OR"}]}';

        $keys = $this->client($http)->correctionKeys($this->id, [['text' => '4C 16mm2', 'productCode' => 'XL-PAXN/4C16OR']]);

        self::assertSame([['index' => 0, 'key' => '4c 16mm2', 'productCode' => 'XL-PAXN/4C16OR']], $keys);
        self::assertSame('http://127.0.0.1:8002/v1/corrections/keys', $http->calls[0]['url']);
        self::assertSame([], $this->client(new FakeJsonHttpClient())->correctionKeys($this->id, []), 'nothing to key, nothing asked');

        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "entries": [{"index": 4, "key": "x", "productCode": "Y"}]}';
        $this->expectException(RfqParserFailure::class);
        $this->client($http)->correctionKeys($this->id, [['text' => 'x', 'productCode' => 'Y']]);
    }

    public function test_a_parse_carries_the_correction_memory_as_an_object(): void
    {
        $request = ParseRequest::forText($this->id, '4C 16mm2', false)
            ->withCorrections(['4c 16mm2' => ['product_code' => 'XL-PAXN/4C16OR', 'confirmations' => 2]]);
        self::assertSame(
            '{"4c 16mm2":{"product_code":"XL-PAXN/4C16OR","confirmations":2}}',
            json_encode($request->toPayload()['corrections'], JSON_UNESCAPED_SLASHES),
        );
        self::assertArrayNotHasKey('corrections', ParseRequest::forText($this->id, 'x', false)->toPayload());
    }

    public function test_product_search_names_the_description(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "items": [{"code": "XL-PAXN/4C16OR", "style": "XL-PAXN", "desc2": "4C 16mm2"}]}';

        $items = $this->client($http)->searchProducts($this->id, 'xl-paxn', 20);

        self::assertSame([['code' => 'XL-PAXN/4C16OR', 'style' => 'XL-PAXN', 'description' => '4C 16mm2']], $items);
        self::assertSame('http://127.0.0.1:8002/v1/products/search', $http->calls[0]['url']);
        self::assertSame('xl-paxn', $http->calls[0]['payload']['q'] ?? null);
    }

    public function test_a_screenshot_goes_to_ocr_and_comes_back_as_text(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "text": "4C 16mm2 120m", "rowCount": 1, "confidence": 0.9, "model": "qwen-test", "escalated": false}';

        $text = $this->client($http)->readScreenshot($this->id, 'shot.png', 'image/png', "\x89PNG-bytes");

        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8002/v1/ocr', $call['url']);
        $document = $call['payload']['document'] ?? null;
        self::assertIsArray($document);
        self::assertSame(base64_encode("\x89PNG-bytes"), $document['contentBase64']);
        self::assertSame('image/png', $document['contentType']);
        self::assertSame(['text' => '4C 16mm2 120m', 'rowCount' => 1, 'confidence' => 0.9, 'model' => 'qwen-test', 'escalated' => false], $text->toArray());
    }

    public function test_a_screenshot_over_the_limit_never_reaches_the_parser(): void
    {
        $http = new FakeJsonHttpClient();
        try {
            $this->client($http)->readScreenshot($this->id, 'big.png', 'image/png', str_repeat('x', 8 * 1024 * 1024 + 1));
            self::fail('expected a failure');
        } catch (RfqParserFailure $failure) {
            self::assertSame(RfqParserFailure::INPUT_TOO_LARGE, $failure->failureCode);
        }
        self::assertSame([], $http->calls);
    }

    public function test_an_ocr_provider_refusal_keeps_its_type_and_words(): void
    {
        $http = new FakeJsonHttpClient();
        $http->status = 502;
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "error": {"code": "provider_rejected", "message": "Screenshot reading is not switched on."}}';

        try {
            $this->client($http)->readScreenshot($this->id, 'shot.png', 'image/png', 'x');
            self::fail('expected a failure');
        } catch (RfqParserFailure $failure) {
            self::assertSame(RfqParserFailure::PROVIDER_REJECTED, $failure->failureCode);
            self::assertSame('Screenshot reading is not switched on.', $failure->getMessage());
        }
    }

    public function test_an_empty_ocr_text_is_not_an_answer(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "text": "  ", "rowCount": 0, "confidence": 0.9, "model": "qwen-test", "escalated": false}';

        $this->expectException(RfqParserFailure::class);
        $this->client($http)->readScreenshot($this->id, 'shot.png', 'image/png', 'x');
    }

    public function test_pricing_sends_the_rows_as_one_string_and_reads_them_back_in_order(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "rows": [{"unit_price": 10.0, "length": {}}, {"unit_price": null}], "unpriced": 1}';

        $priced = $this->client($http)->priceRows($this->id, [['final_product_code' => 'A', 'length' => new stdClass()], ['final_product_code' => 'B']], 'australia', 'NZD', ['A' => 33, 'B' => 'cost'], true);

        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8002/v1/price', $call['url']);
        $draftJson = $call['payload']['draftJson'] ?? null;
        self::assertIsString($draftJson);
        $draft = json_decode($draftJson, true);
        self::assertIsArray($draft);
        self::assertSame(['A' => 33, 'B' => 'cost'], $draft['defaultMargins']);
        self::assertSame(['australia', 'NZD', true], [$draft['region'], $draft['currency'], $draft['reprice']]);
        self::assertMatchesRegularExpression('/"length":\s*\{\}/', $draftJson, 'an empty object stays an object');
        self::assertCount(2, $priced->rows);
        self::assertSame(1, $priced->unpriced);
    }

    public function test_prices_for_a_different_number_of_rows_are_refused(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "rows": [{}], "unpriced": 0}';

        $this->expectException(RfqParserFailure::class);
        $this->client($http)->priceRows($this->id, [['a' => 1], ['b' => 2]], 'australia', 'AUD', [], false);
    }

    public function test_the_export_sends_the_rows_as_one_string_with_the_price_flag(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "summary": [], "quoteLines": {"columns": ["A"], "rows": [["1"]]}, "summaryLines": {"columns": ["A"], "rows": []}}';

        $export = $this->client($http)->exportRows($this->id, [['final_product_code' => 'A', 'length' => new stdClass()]], false);

        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8002/v1/export', $call['url']);
        $draftJson = $call['payload']['draftJson'] ?? null;
        self::assertIsString($draftJson);
        self::assertMatchesRegularExpression('/"includePrices":\s*false/', $draftJson);
        self::assertMatchesRegularExpression('/"length":\s*\{\}/', $draftJson, 'an empty object stays an object');
        self::assertSame([['1']], $export->quoteLines['rows']);
    }

    public function test_the_sync_plan_sends_the_draft_as_one_string_and_reads_the_plan_strictly(): void
    {
        $http = new FakeJsonHttpClient();
        $plan = '{"is_update": false, "existing_refno": "", "push_version": "0", "hdr_params": {"ref": "0"}, "line_items": [], "excluded": [], "fallback_margin": [], "customer_id": "1", "cusref": ""}';
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "plan": ' . $plan . '}';

        $answer = $this->client($http)->syncPlan($this->id, [['final_product_code' => 'A']], ['customer_id' => '1'], ['A' => 25], '2026-10-02', '721999', 3, 'portal');

        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8002/v1/sync-plan', $call['url']);
        $draftJson = $call['payload']['draftJson'] ?? null;
        self::assertIsString($draftJson);
        self::assertMatchesRegularExpression('/"today":\s*"2026-10-02"/', $draftJson);
        self::assertMatchesRegularExpression('/"existing_refno":\s*"721999"/', $draftJson);
        self::assertMatchesRegularExpression('/"version":\s*3/', $draftJson);
        self::assertMatchesRegularExpression('/"margins":\s*\{"A":\s*25\}/', $draftJson);
        self::assertNotNull($answer->plan);
        self::assertNull($answer->problem);
        self::assertSame('0', $answer->plan['push_version']);
    }

    public function test_a_sync_problem_is_an_answer_and_a_malformed_one_is_a_failure(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "problem": {"status": 400, "payload": {"ok": false, "error": "no"}}}';
        $answer = $this->client($http)->syncPlan($this->id, [], [], [], '2026-10-02', null, null, '');
        self::assertNull($answer->plan);
        self::assertSame(400, $answer->problem['status'] ?? null);

        foreach (['{"plan": {"hdr_params": []}}', '{"plan": {}, "problem": {"status": 400, "payload": {}}}', '{}', '{"problem": {"status": "400", "payload": {}}}'] as $bad) {
            $http = new FakeJsonHttpClient();
            $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", ' . substr($bad, 1);
            try {
                $this->client($http)->syncPlan($this->id, [], [], [], '2026-10-02', null, null, '');
                self::fail('expected an unreadable plan to be refused: ' . $bad);
            } catch (\TriCab\Core\Rfq\Parser\RfqParserFailure $failure) {
                self::assertSame('internal_error', $failure->failureCode);
            }
        }
    }

    public function test_aliflex_sends_the_quote_as_one_string_and_reads_the_conversion_strictly(): void
    {
        $http = new FakeJsonHttpClient();
        $conversion = '{"policy_version": "aliflex-map/2", "rows": [{"final_product_code": "KL-PAXA/1C35BK"}], "quote_details": {"comment": "n"}, "label": "ALI-FLEX alternative", "counts": {"changed": 1, "unchanged": 0, "review": 0}, "message": "1 line(s) changed to ALI-FLEX."}';
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "conversion": ' . $conversion . '}';

        $answer = $this->client($http)->aliflex($this->id, [['final_product_code' => 'XL-PAXN/1C25OR', 'length' => new stdClass()]], ['customer_id' => '1'], 'Westelec');

        $call = $http->calls[0];
        self::assertSame('http://127.0.0.1:8002/v1/aliflex', $call['url']);
        $draftJson = $call['payload']['draftJson'] ?? null;
        self::assertIsString($draftJson);
        self::assertMatchesRegularExpression('/"label":\s*"Westelec"/', $draftJson);
        self::assertMatchesRegularExpression('/"length":\s*\{\}/', $draftJson, 'an empty object stays an object');
        self::assertSame(1, $answer->counts['changed']);
        self::assertSame('KL-PAXA/1C35BK', $answer->rows[0]['final_product_code']);
        self::assertSame('aliflex-map/2', $answer->policyVersion);

        foreach (['{}', '{"conversion": {"rows": "x"}}', '{"conversion": {"rows": [], "quote_details": {}, "label": "l", "counts": {"changed": -1, "unchanged": 0, "review": 0}, "message": "m", "policy_version": "p"}}'] as $bad) {
            $http = new FakeJsonHttpClient();
            $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", ' . substr($bad, 1);
            try {
                $this->client($http)->aliflex($this->id, [], [], '');
                self::fail('expected an unreadable conversion to be refused: ' . $bad);
            } catch (\TriCab\Core\Rfq\Parser\RfqParserFailure $failure) {
                self::assertSame('internal_error', $failure->failureCode);
            }
        }
    }

    public function test_product_styles_are_asked_only_when_there_are_codes(): void
    {
        $http = new FakeJsonHttpClient();
        self::assertSame([], $this->client($http)->productStyles($this->id, []));
        self::assertSame([], $http->calls);

        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "styles": {"XL-PAXN/4C16OR": "XL"}}';
        self::assertSame(['XL-PAXN/4C16OR' => 'XL'], $this->client($http)->productStyles($this->id, ['XL-PAXN/4C16OR']));
    }

    public function test_probes_answer_true_only_for_the_contract(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "x", "status": "ok"}';
        self::assertTrue($this->client($http)->isAlive());
        self::assertSame('http://127.0.0.1:8002/v1/health', $http->calls[0]['url']);

        $http->body = '{"success": true, "data": {"status": "ok"}}';
        self::assertFalse($this->client($http)->isReady(), 'a parity route answering is not the contract');

        $http->transportError = new RuntimeException('down');
        self::assertFalse($this->client($http)->isAlive());
    }
}
