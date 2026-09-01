<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\DTO;

final readonly class CableCalculationResult
{
    /**
     * @param list<string> $warnings
     * @param list<CableCalculationBand> $bands
     */
    public function __construct(
        public CableCalculationRequest $request,
        public array $bands,
        public array $warnings,
        public bool $isPreview,
        public string $calculatedAtUtc,
        public string $parityBaseline = 'LOCAL_LEGACY_PARITY_BASELINE',
    ) {
    }

    public function primaryBand(): ?CableCalculationBand
    {
        return $this->bands[0] ?? null;
    }

    /** @param array<string, mixed> $legacyOutput */
    public static function fromLegacyOutput(
        CableCalculationRequest $request,
        array $legacyOutput,
        bool $isPreview,
    ): self {
        $bands = [];
        if (!empty($legacyOutput['result90'])) {
            $bands[] = CableCalculationBand::fromLegacyBand('90', $legacyOutput['result90']);
        }
        if (!empty($legacyOutput['result110'])) {
            $bands[] = CableCalculationBand::fromLegacyBand('110', $legacyOutput['result110']);
        }

        $warnings = [
            'Engine result from LOCAL_LEGACY_PARITY_BASELINE — not AUTHORITATIVE_PRODUCTION_PARITY until Phase 1.6.',
        ];

        return new self(
            request: $request,
            bands: $bands,
            warnings: $warnings,
            isPreview: $isPreview,
            calculatedAtUtc: gmdate('c'),
        );
    }
}
