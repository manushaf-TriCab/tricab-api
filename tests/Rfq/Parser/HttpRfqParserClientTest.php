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

    public function test_product_search_names_the_description(): void
    {
        $http = new FakeJsonHttpClient();
        $http->body = '{"contract": "rfq-parser.v1", "correlationId": "corr-client-000001", "items": [{"code": "XL-PAXN/4C16OR", "style": "XL-PAXN", "desc2": "4C 16mm2"}]}';

        $items = $this->client($http)->searchProducts($this->id, 'xl-paxn', 20);

        self::assertSame([['code' => 'XL-PAXN/4C16OR', 'style' => 'XL-PAXN', 'description' => '4C 16mm2']], $items);
        self::assertSame('http://127.0.0.1:8002/v1/products/search', $http->calls[0]['url']);
        self::assertSame('xl-paxn', $http->calls[0]['payload']['q'] ?? null);
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
