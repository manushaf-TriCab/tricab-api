<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductCodePreviewResult
{
    /**
     * @param list<string> $validationErrors
     * @param list<string> $warnings
     */
    public function __construct(
        public string $generatedCode,
        public string $explanation,
        public array $validationErrors,
        public array $warnings,
        public bool $isUnique,
    ) {
    }
}
