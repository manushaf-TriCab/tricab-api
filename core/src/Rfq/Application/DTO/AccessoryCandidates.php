<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * What Match Accessories found (parity plan E7.2). Contract
 * `rfq.accessory-candidates.v1`.
 *
 * `engineAvailable` false means no engine is connected: no candidates, and a
 * note for the rep (the source's `engine_available: false`). Each candidate is
 * an accessory line priced as it would be once added, with the token that adds
 * it; the line's `lineUid` is the one it takes when added. The cost on a
 * candidate is only for a holder of `RFQ.PRICING.VIEW`, which the endpoint
 * requires.
 */
final class AccessoryCandidates
{
    /**
     * @param list<array{lineUid: string, productCode: string, candidates: list<array{token: string, description: string, line: ParsedLine}>}> $parents
     * @param list<array{lineUid: string, code: string, reason: string}>                                                   $skipped
     */
    private function __construct(
        public readonly bool $engineAvailable,
        public readonly ?string $note,
        public readonly array $parents,
        public readonly array $skipped,
        public readonly ?string $pricingError,
    ) {
    }

    public static function unavailable(string $note): self
    {
        return new self(false, $note, [], [], null);
    }

    /**
     * @param list<array{lineUid: string, productCode: string, candidates: list<array{token: string, description: string, line: ParsedLine}>}> $parents
     * @param list<array{lineUid: string, code: string, reason: string}>                                                   $skipped
     */
    public static function available(array $parents, array $skipped, ?string $pricingError): self
    {
        return new self(true, null, $parents, $skipped, $pricingError);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'engineAvailable' => $this->engineAvailable,
            'note' => $this->note,
            'parents' => array_map(static fn (array $parent): array => [
                'lineUid' => $parent['lineUid'],
                'productCode' => $parent['productCode'],
                'candidates' => array_map(static fn (array $candidate): array => [
                    'token' => $candidate['token'],
                    'description' => $candidate['description'],
                    'line' => $candidate['line']->toArray(true),
                ], $parent['candidates']),
            ], $this->parents),
            'skipped' => $this->skipped,
            'pricingError' => $this->pricingError,
        ];
    }
}
