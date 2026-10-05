<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The parser's answer to `POST /v1/aliflex` (parity plan E7.4), read strictly: a
 * finished quote's rows converted to the ALI-FLEX alternative, with the header
 * (carrying the customer-facing calculation note), the label and the counts.
 * Contract `rfq-parser.v1`.
 *
 * `rows` are one per source row, in order, nothing dropped. A changed row has
 * had its source prices cleared for the caller to price afresh; an unchanged
 * row is the source row. The counts are `changed`, `unchanged` and `review`.
 */
final class AliflexConversion
{
    /**
     * @param list<array<string, mixed>>                                        $rows
     * @param array<string, mixed>                                              $quoteDetails
     * @param array{changed: int, unchanged: int, review: int}                  $counts
     */
    private function __construct(
        public readonly array $rows,
        public readonly array $quoteDetails,
        public readonly string $label,
        public readonly array $counts,
        public readonly string $message,
        public readonly string $policyVersion,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent): self
    {
        ParseResponse::assertEnvelope($payload, $sent);
        $conversion = $payload['conversion'] ?? null;
        if (!is_array($conversion) || !is_array($conversion['rows'] ?? null) || !array_is_list($conversion['rows'])
            || !is_array($conversion['quote_details'] ?? null) || !is_string($conversion['label'] ?? null)
            || !is_array($conversion['counts'] ?? null) || !is_string($conversion['message'] ?? null) || !is_string($conversion['policy_version'] ?? null)) {
            throw self::malformed($sent);
        }
        $counts = [];
        foreach (['changed', 'unchanged', 'review'] as $key) {
            $value = $conversion['counts'][$key] ?? null;
            if (!is_int($value) || $value < 0) {
                throw self::malformed($sent);
            }
            $counts[$key] = $value;
        }
        $rows = [];
        foreach ($conversion['rows'] as $row) {
            if (!is_array($row) || ($row !== [] && array_is_list($row))) {
                throw self::malformed($sent);
            }
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }
        /** @var array<string, mixed> $details */
        $details = $conversion['quote_details'];
        /** @var array{changed: int, unchanged: int, review: int} $counts */

        return new self($rows, $details, $conversion['label'], $counts, $conversion['message'], $conversion['policy_version']);
    }

    private static function malformed(CorrelationId $sent): RfqParserFailure
    {
        return RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned an unreadable ALI-FLEX conversion.', $sent);
    }
}
