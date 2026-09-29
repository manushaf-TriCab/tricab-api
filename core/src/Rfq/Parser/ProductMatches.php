<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The parser's answer to `POST /v1/products/search`, read strictly. Contract
 * `rfq-parser.v1`. The source's items are `{code, style, desc2}`; `desc2` is
 * the catalogue's description, and is named that here.
 */
final class ProductMatches
{
    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<array{code: string, style: string, description: string}>
     *
     * @throws RfqParserFailure
     */
    public static function fromPayload(array $payload, CorrelationId $sent): array
    {
        ParseResponse::assertEnvelope($payload, $sent);
        $items = $payload['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned an unreadable product search.', $sent);
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['code'] ?? null) || $item['code'] === '') {
                throw RfqParserFailure::of(RfqParserFailure::INTERNAL_ERROR, 'The RFQ parser returned an unreadable product search.', $sent);
            }
            $out[] = [
                'code' => $item['code'],
                'style' => is_string($item['style'] ?? null) ? $item['style'] : '',
                'description' => is_string($item['desc2'] ?? null) ? $item['desc2'] : '',
            ];
        }

        return $out;
    }
}
