<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductCodeStyleMappingRow
{
    /**
     * @param list<string> $warnings
     * @param array<string, mixed>|null $proposedStyle
     * @param array<string, mixed>|null $proposedAssignment
     */
    public function __construct(
        public string $productPublicId,
        public string $productName,
        public ?string $rawProductCode,
        public ?string $canonicalProductCode,
        public string $population,
        public string $decision,
        public string $action,
        public bool $styleExists,
        public ?string $expectedStylePublicId,
        public ?string $expectedStyleCode,
        public ?string $currentAssignedStyleCode,
        public bool $applyEligible,
        public string $reason,
        public array $warnings,
        public string $applicationState,
        public ?array $proposedStyle,
        public ?array $proposedAssignment,
        public string $matchRule,
        public bool $expectedStyleExists,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'productPublicId' => $this->productPublicId,
            'productName' => $this->productName,
            'rawProductCode' => $this->rawProductCode,
            'canonicalProductCode' => $this->canonicalProductCode,
            'productCode' => $this->canonicalProductCode ?? $this->rawProductCode,
            'population' => $this->population,
            'decision' => $this->decision,
            'action' => $this->action,
            'styleExists' => $this->styleExists,
            'expectedStylePublicId' => $this->expectedStylePublicId,
            'expectedStyleCode' => $this->expectedStyleCode,
            'currentAssignedStyleCode' => $this->currentAssignedStyleCode,
            'applyEligible' => $this->applyEligible,
            'reason' => $this->reason,
            'warnings' => $this->warnings,
            'applicationState' => $this->applicationState,
            'proposedStyle' => $this->proposedStyle,
            'proposedAssignment' => $this->proposedAssignment,
            'matchRule' => $this->matchRule,
            'expectedStyleExists' => $this->expectedStyleExists,
        ];
    }
}
