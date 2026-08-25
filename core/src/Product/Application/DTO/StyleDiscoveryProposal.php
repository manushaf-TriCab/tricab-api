<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class StyleDiscoveryProposal
{
    /**
     * @param list<string> $warnings
     * @param list<string> $prefixCandidates
     */
    public function __construct(
        public string $parentStyleCode,
        public string $childStyleCode,
        public string $reason,
        public string $confidence,
        public int $productCount,
        public ?int $sharedSpecificationEvidence,
        public ?int $sharedMediaEvidence,
        public string $candidateSource,
        public array $warnings = [],
        public ?string $familyEvidence = null,
        public string $sourceEvidence = '',
        public string $applicationState = 'NOT_APPLIED',
        public bool $ambiguousParent = false,
        public bool $cycleConflict = false,
        public ?string $persistedParentStyleCode = null,
        public array $prefixCandidates = [],
    ) {
    }
}
