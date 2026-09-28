<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Parser;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Infrastructure\UndeployedRfqParserClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\RfqServiceLimits;

/**
 * Plans 1.1 and 1.7 — the versioned parser contract, parsed strictly, and the
 * limits PHP enforces before anything reaches Python.
 */
final class ParserContractTest extends TestCase
{
    private CorrelationId $id;

    protected function setUp(): void
    {
        $this->id = CorrelationId::fromHeaderOrGenerate('test-correlation-0001');
    }

    /** @return array<string, mixed> */
    private function validResponse(): array
    {
        return [
            'contract' => ParserContract::VERSION,
            'correlationId' => 'test-correlation-0001',
            'lines' => [['requestedText' => '3C+E 2.5mm2 TPS', 'productCode' => 'X']],
            'coverage' => ['inputRows' => 3, 'keptRows' => 1, 'excludedRows' => 1, 'skippedRows' => 1],
            'engine' => ['version' => '387834e', 'rulesVersion' => 'r1', 'catalogueVersion' => 'c1'],
            'provider' => ['mode' => 'deterministic', 'modelCalls' => 0, 'model' => null],
            'degraded' => false,
            'notes' => [],
        ];
    }

    public function test_a_text_request_carries_the_contract_deadline_and_options(): void
    {
        $payload = ParseRequest::forText($this->id, "3C+E 2.5mm2 TPS 100m\n", allowModel: false)->toPayload();

        self::assertSame(ParserContract::VERSION, $payload['contract']);
        self::assertSame('test-correlation-0001', $payload['correlationId']);
        self::assertSame(RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS, $payload['deadlineMs']);
        self::assertSame(['allowModel' => false, 'maxModelCalls' => RfqServiceLimits::MAX_MODEL_CALLS_PER_PARSE], $payload['options']);
    }

    /** No actor, role or scope crosses the boundary: the parser is not an authority. */
    public function test_the_request_names_nobody(): void
    {
        $payload = ParseRequest::forText($this->id, 'line', allowModel: true)->toPayload();

        foreach (['actor', 'user', 'role', 'scope', 'branch', 'permissions'] as $key) {
            self::assertArrayNotHasKey($key, $payload);
        }
    }

    public function test_empty_text_is_refused(): void
    {
        $this->expectException(InvalidRfqValue::class);
        ParseRequest::forText($this->id, "  \n ", allowModel: false);
    }

    public function test_oversized_text_is_refused_before_it_is_sent(): void
    {
        $this->expectException(InvalidRfqValue::class);
        ParseRequest::forText($this->id, str_repeat('a', RfqServiceLimits::MAX_PASTED_CHARACTERS + 1), allowModel: false);
    }

    public function test_an_image_over_eight_megabytes_is_refused(): void
    {
        $this->expectException(InvalidRfqValue::class);
        ParseRequest::forDocuments($this->id, [[
            'documentPublicId' => 'd1',
            'fileName' => 'photo.jpg',
            'contentType' => 'image/jpeg',
            'bytes' => str_repeat('x', RfqServiceLimits::MAX_IMAGE_BYTES + 1),
        ]], allowModel: false);
    }

    public function test_documents_travel_as_base64_with_their_display_name(): void
    {
        $payload = ParseRequest::forDocuments($this->id, [[
            'documentPublicId' => 'd1',
            'fileName' => '../../etc/schedule.xlsx',
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'bytes' => 'PK...',
        ]], allowModel: false)->toPayload();

        $documents = $payload['documents'];
        self::assertIsArray($documents);
        $first = $documents[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(base64_encode('PK...'), $first['contentBase64'] ?? null);
        self::assertArrayNotHasKey('path', $first);
    }

    public function test_a_deadline_outside_the_bounds_is_refused(): void
    {
        $this->expectException(InvalidRfqValue::class);
        ParseRequest::forText($this->id, 'line', allowModel: false, deadlineMs: 10);
    }

    // --------------------------------------------------------------- response

    public function test_a_valid_response_is_read(): void
    {
        $response = ParseResponse::fromPayload($this->validResponse(), $this->id);

        self::assertCount(1, $response->lines);
        self::assertTrue($response->isCoverageComplete());
        self::assertSame('deterministic', $response->provider['mode']);
    }

    /** Invariant 3: rows unaccounted for are visible as a fact, not averaged away. */
    public function test_coverage_that_does_not_add_up_is_reported_incomplete(): void
    {
        $payload = $this->validResponse();
        $payload['coverage'] = ['inputRows' => 5, 'keptRows' => 1, 'excludedRows' => 1, 'skippedRows' => 1];

        self::assertFalse(ParseResponse::fromPayload($payload, $this->id)->isCoverageComplete());
    }

    public function test_another_contract_version_is_refused_not_interpreted(): void
    {
        $payload = $this->validResponse();
        $payload['contract'] = 'rfq-parser.v2';

        try {
            ParseResponse::fromPayload($payload, $this->id);
            self::fail('A v2 response was read.');
        } catch (RfqParserFailure $failure) {
            self::assertSame(RfqParserFailure::CONTRACT_MISMATCH, $failure->failureCode);
            self::assertFalse($failure->isRetryable());
        }
    }

    public function test_a_response_for_another_request_is_refused(): void
    {
        $payload = $this->validResponse();
        $payload['correlationId'] = 'somebody-elses-request';

        $this->expectException(RfqParserFailure::class);
        ParseResponse::fromPayload($payload, $this->id);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformed(): iterable
    {
        yield 'lines not a list' => ['lines', ['a' => []]];
        yield 'negative coverage' => ['coverage', ['inputRows' => -1, 'keptRows' => 0, 'excludedRows' => 0, 'skippedRows' => 0]];
        yield 'float coverage' => ['coverage', ['inputRows' => 1.0, 'keptRows' => 1, 'excludedRows' => 0, 'skippedRows' => 0]];
        yield 'engine missing rules' => ['engine', ['version' => 'x', 'catalogueVersion' => 'c']];
        yield 'unknown provider mode' => ['provider', ['mode' => 'magic', 'modelCalls' => 0, 'model' => null]];
        yield 'degraded as a string' => ['degraded', 'no'];
        yield 'notes with a number' => ['notes', ['ok', 3]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_a_malformed_response_is_a_failure_not_a_partial_result(string $field, mixed $value): void
    {
        $payload = $this->validResponse();
        $payload[$field] = $value;

        try {
            ParseResponse::fromPayload($payload, $this->id);
            self::fail('Malformed ' . $field . ' was read.');
        } catch (RfqParserFailure $failure) {
            self::assertSame(RfqParserFailure::INTERNAL_ERROR, $failure->failureCode);
            self::assertSame('test-correlation-0001', $failure->correlationId);
        }
    }

    public function test_a_parser_error_envelope_becomes_a_typed_failure(): void
    {
        $payload = [
            'contract' => ParserContract::VERSION,
            'correlationId' => 'test-correlation-0001',
            'error' => ['code' => 'unsupported_format', 'message' => 'No reader handles .pages files.'],
        ];

        try {
            ParseResponse::fromPayload($payload, $this->id);
            self::fail('An error envelope was read as a result.');
        } catch (RfqParserFailure $failure) {
            self::assertSame(RfqParserFailure::UNSUPPORTED_FORMAT, $failure->failureCode);
            self::assertSame(415, $failure->httpStatus());
            self::assertSame('No reader handles .pages files.', $failure->getMessage());
        }
    }

    public function test_an_unknown_failure_code_is_an_internal_error(): void
    {
        self::assertSame(RfqParserFailure::INTERNAL_ERROR, RfqParserFailure::of('mystery', 'x')->failureCode);
    }

    public function test_retryable_failures_are_the_transient_ones(): void
    {
        self::assertTrue(RfqParserFailure::of(RfqParserFailure::TIMEOUT, 'x')->isRetryable());
        self::assertTrue(RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'x')->isRetryable());
        self::assertFalse(RfqParserFailure::of(RfqParserFailure::PROVIDER_REJECTED, 'x')->isRetryable());
    }

    // ------------------------------------------------------ correlation, client

    public function test_a_plain_inbound_correlation_id_is_kept_and_anything_else_replaced(): void
    {
        self::assertSame('abc12345-XYZ', CorrelationId::fromHeaderOrGenerate(' abc12345-XYZ ')->toString());

        foreach ([null, '', 'short', "evil\nFAKE LOG LINE", str_repeat('a', 65), '<script>1234567'] as $bad) {
            $id = CorrelationId::fromHeaderOrGenerate($bad)->toString();
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id, var_export($bad, true));
        }
    }

    /** "No lines" must never be the answer to "the parser is not there". */
    public function test_the_undeployed_client_refuses_rather_than_answering_empty(): void
    {
        $client = new UndeployedRfqParserClient();

        self::assertFalse($client->isAlive());
        self::assertFalse($client->isReady());

        try {
            $client->parse(ParseRequest::forText($this->id, 'line', allowModel: false));
            self::fail('The undeployed parser answered.');
        } catch (RfqParserFailure $failure) {
            self::assertSame(RfqParserFailure::UNAVAILABLE, $failure->failureCode);
            self::assertSame(503, $failure->httpStatus());
        }
    }
}
