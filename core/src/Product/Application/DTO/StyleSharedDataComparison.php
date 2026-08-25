<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class StyleSharedDataComparison
{
    /**
     * @param list<string> $childStyleCodes
     * @param list<string> $identicalSpecificationCodes
     * @param list<string> $differingSpecificationCodes
     * @param list<string> $missingSpecificationCodes
     * @param list<string> $commonMediaKeys
     * @param array<string, list<string>> $childSpecificMediaKeys
     */
    public function __construct(
        public string $proposedParentStyleCode,
        public array $childStyleCodes,
        public int $identicalSpecificationCount,
        public int $differingSpecificationCount,
        public int $missingSpecificationCount,
        public array $identicalSpecificationCodes,
        public array $differingSpecificationCodes,
        public array $missingSpecificationCodes,
        public int $commonMediaCount,
        public array $commonMediaKeys,
        public array $childSpecificMediaKeys,
    ) {
    }
}
