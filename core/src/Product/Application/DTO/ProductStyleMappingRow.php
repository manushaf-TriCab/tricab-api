<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductStyleMappingRow
{
    /**
     * @param list<string> $warnings
     * @param list<string> $variantRawStyleCodes
     */
    public function __construct(
        public string $productPublicId,
        public string $productName,
        public ?string $productCode,
        public string $origin,
        public string $decision,
        public string $confidence,
        public string $action,
        public ?string $rawLegacyStyleCode,
        public ?string $canonicalStyleCode,
        public ?string $matchedStylePublicId,
        public ?string $matchedStyleCode,
        public ?string $currentAssignedStyleCode,
        public string $evidenceSource,
        public bool $applyEligible,
        public string $reason,
        public array $warnings,
        public ?string $legacyProductId,
        public ?string $legacyKind,
        public array $variantRawStyleCodes,
        public string $applicationState,
        public bool $cableStyleOccurrence,
        public bool $numericStyle,
        public bool $multiValueStyle,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'productPublicId' => $this->productPublicId,
            'productName' => $this->productName,
            'productCode' => $this->productCode,
            'origin' => $this->origin,
            'decision' => $this->decision,
            'confidence' => $this->confidence,
            'action' => $this->action,
            'rawLegacyStyleCode' => $this->rawLegacyStyleCode,
            'canonicalStyleCode' => $this->canonicalStyleCode,
            'matchedStylePublicId' => $this->matchedStylePublicId,
            'matchedStyleCode' => $this->matchedStyleCode,
            'currentAssignedStyleCode' => $this->currentAssignedStyleCode,
            'evidenceSource' => $this->evidenceSource,
            'applyEligible' => $this->applyEligible,
            'reason' => $this->reason,
            'warnings' => $this->warnings,
            'legacyProductId' => $this->legacyProductId,
            'legacyKind' => $this->legacyKind,
            'variantRawStyleCodes' => $this->variantRawStyleCodes,
            'applicationState' => $this->applicationState,
            'cableStyleOccurrence' => $this->cableStyleOccurrence,
            'numericStyle' => $this->numericStyle,
            'multiValueStyle' => $this->multiValueStyle,
        ];
    }
}
