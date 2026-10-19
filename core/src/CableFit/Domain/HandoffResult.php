<?php

declare(strict_types=1);

namespace TriCab\Core\CableFit\Domain;

/**
 * The outcome of translating NPR Section 5 answers into CableFit parameters.
 */
final readonly class HandoffResult
{
    /**
     * @param array<string, mixed>                $construction       Partial design parameters.
     * @param array<string, mixed>                $provenance         Which parameter came from which answer
     *                                                                (sales_provided, default, derived_from_class, unconfigured).
     * @param list<array<string, mixed>>          $unresolved         Requirements needing engineering review.
     * @param array<string, array<string, mixed>> $alternatives       Fields with several acceptable options.
     * @param array<string, mixed>                $chosenAlternatives The option picked for each alternative field.
     */
    public function __construct(
        public string $mappingVersion,
        public string $variantId,
        public array $construction,
        public array $provenance,
        public array $unresolved,
        public array $alternatives,
        public array $chosenAlternatives,
    ) {
    }

    /**
     * The design document stored on a candidate revision.
     *
     * @return array<string, mixed>
     */
    public function toDesign(): array
    {
        return [
            'construction' => $this->construction,
            'provenance' => $this->provenance,
            'unresolvedRequirements' => $this->unresolved,
            'chosenAlternatives' => $this->chosenAlternatives,
        ];
    }
}
