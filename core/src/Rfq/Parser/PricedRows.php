<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The parser's answer to `POST /v1/price`, read strictly: a draft's rows
 * priced as the source editor prices them (cost, MOQ, prepack, currency, then
 * margin, sell price and line total), in the order they were sent. Contract
 * `rfq-parser.v1`, parity plan E5.
 *
 * `unpriced` counts the lines that ought to have a price and have none, which
 * is the editor's "No price for X of N lines".
 */
final class PricedRows
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private function __construct(
        public readonly array $rows,
        public readonly int $unpriced,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent, int $expectedRows): self
    {
        ParseResponse::assertEnvelope($payload, $sent);
        $rows = $payload['rows'] ?? null;
        $unpriced = $payload['unpriced'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== $expectedRows || !is_int($unpriced) || $unpriced < 0 || $unpriced > $expectedRows) {
            throw RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned unreadable prices.', $sent);
        }
        $typed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned unreadable prices.', $sent);
            }
            /** @var array<string, mixed> $row */
            $typed[] = $row;
        }

        return new self($typed, $unpriced);
    }
}
