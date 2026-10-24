<?php

declare(strict_types=1);

namespace TriCab\Api\DocumentGeneration;

final readonly class GeneratedPdf
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $contents,
        public string $filename,
        public string $templateName,
        public array $warnings,
    ) {
    }
}
