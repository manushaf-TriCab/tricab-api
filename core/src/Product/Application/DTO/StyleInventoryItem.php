<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class StyleInventoryItem
{
    /**
     * @param list<string> $rawStyleCodes
     * @param list<string> $sources
     * @param list<string> $sampleProducts
     * @param list<string> $warnings
     */
    public function __construct(
        public string $rawStyleCode,
        public ?string $normalizedStyleCode,
        public string $normalisationReason,
        public array $rawStyleCodes,
        public array $sources,
        public int $sourceOccurrences,
        public int $productCount,
        public array $sampleProducts,
        public ?string $familyEvidence,
        public ?string $existingTargetStyleId,
        public string $status,
        public string $targetMatch,
        public string $targetClassification,
        public string $displayName,
        public string $displayNameSource,
        public array $warnings,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rawStyleCode' => $this->rawStyleCode,
            'normalizedStyleCode' => $this->normalizedStyleCode,
            'normalisationReason' => $this->normalisationReason,
            'rawStyleCodes' => $this->rawStyleCodes,
            'sources' => $this->sources,
            'sourceOccurrences' => $this->sourceOccurrences,
            'productCount' => $this->productCount,
            'sampleProducts' => $this->sampleProducts,
            'familyEvidence' => $this->familyEvidence,
            'existingTargetStyleId' => $this->existingTargetStyleId,
            'status' => $this->status,
            'targetMatch' => $this->targetMatch,
            'targetClassification' => $this->targetClassification,
            'displayName' => $this->displayName,
            'displayNameSource' => $this->displayNameSource,
            'warnings' => $this->warnings,
        ];
    }
}
