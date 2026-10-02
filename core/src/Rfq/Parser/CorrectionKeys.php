<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The parser's answer to `POST /v1/corrections/keys`, read strictly. Contract
 * `rfq-parser.v1` (parity plan E5.5).
 *
 * One entry per line Save Review may learn from, in the order sent: the
 * line's index in the request, its correction-memory key (the source's
 * `correction_key`) and its product code as the catalogue holds it. A line
 * with no key, or a code the catalogue does not carry, has no entry, as the
 * source's `learn_approved_corrections` skips it.
 */
final class CorrectionKeys
{
    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<array{index: int, key: string, productCode: string}>
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent, int $lineCount): array
    {
        ParseResponse::assertEnvelope($payload, $sent);
        $entries = $payload['entries'] ?? null;
        if (!is_array($entries) || !array_is_list($entries)) {
            throw self::unreadable($sent);
        }
        $out = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)
                || !is_int($entry['index'] ?? null) || $entry['index'] < 0 || $entry['index'] >= $lineCount
                || !is_string($entry['key'] ?? null) || $entry['key'] === '' || mb_strlen($entry['key']) > 400
                || !is_string($entry['productCode'] ?? null) || $entry['productCode'] === '' || mb_strlen($entry['productCode']) > 50) {
                throw self::unreadable($sent);
            }
            $out[] = ['index' => $entry['index'], 'key' => $entry['key'], 'productCode' => $entry['productCode']];
        }

        return $out;
    }

    private static function unreadable(CorrelationId $sent): RfqParserFailure
    {
        return RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned unreadable correction keys.', $sent);
    }
}
