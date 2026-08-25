<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class StyleHierarchyEvidenceRow
{
    /**
     * @param list<string> $warnings
     * @param list<string> $prefixCandidates
     */
    public function __construct(
        public string $parentStyleCode,
        public string $childStyleCode,
        public string $originalConfidence,
        public string $reviewedConfidence,
        public string $decision,
        public string $existingParentState,
        public bool $applyEligible,
        public int $parentProductCount,
        public int $childProductCount,
        public string $codeEvidence,
        public string $classificationEvidence,
        public string $descriptionEvidence,
        public string $specificationEvidence,
        public string $mediaEvidence,
        public ?string $conflict,
        public string $reason,
        public array $warnings,
        public array $prefixCandidates,
        public ?string $alternativeParentStyleCode,
        public ?string $parentPublicId,
        public ?string $childPublicId,
        public string $applicationState,
        public bool $selected,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'parentStyleCode' => $this->parentStyleCode,
            'childStyleCode' => $this->childStyleCode,
            'originalConfidence' => $this->originalConfidence,
            'reviewedConfidence' => $this->reviewedConfidence,
            'decision' => $this->decision,
            'existingParentState' => $this->existingParentState,
            'applyEligible' => $this->applyEligible,
            'parentProductCount' => $this->parentProductCount,
            'childProductCount' => $this->childProductCount,
            'codeEvidence' => $this->codeEvidence,
            'classificationEvidence' => $this->classificationEvidence,
            'descriptionEvidence' => $this->descriptionEvidence,
            'specificationEvidence' => $this->specificationEvidence,
            'mediaEvidence' => $this->mediaEvidence,
            'conflict' => $this->conflict,
            'reason' => $this->reason,
            'warnings' => $this->warnings,
            'prefixCandidates' => $this->prefixCandidates,
            'alternativeParentStyleCode' => $this->alternativeParentStyleCode,
            'parentPublicId' => $this->parentPublicId,
            'childPublicId' => $this->childPublicId,
            'applicationState' => $this->applicationState,
            'selected' => $this->selected,
        ];
    }
}
