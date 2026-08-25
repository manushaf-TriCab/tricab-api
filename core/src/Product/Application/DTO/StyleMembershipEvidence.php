<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Read-only legacy membership snapshot for one style code.
 */
final readonly class StyleMembershipEvidence
{
    /**
     * @param list<string> $sampleTitles
     * @param list<string> $sampleProducts
     * @param list<string> $terms
     * @param list<string> $postTypes
     * @param list<string> $attachmentFiles
     * @param array<string, list<string>> $specificationValues
     * @param list<string> $descriptionSnippets
     * @param list<string> $sourcesAvailable
     */
    public function __construct(
        public string $styleCode,
        public int $wordpressProductCount,
        public int $tcVariantCount,
        public array $sampleTitles,
        public array $sampleProducts,
        public array $terms,
        public array $postTypes,
        public array $attachmentFiles,
        public array $specificationValues,
        public array $descriptionSnippets,
        public array $sourcesAvailable,
        public bool $wordpressAvailable,
        public bool $tcAvailable,
    ) {
    }

    public function productCount(): int
    {
        return max($this->wordpressProductCount, $this->tcVariantCount);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'styleCode' => $this->styleCode,
            'wordpressProductCount' => $this->wordpressProductCount,
            'tcVariantCount' => $this->tcVariantCount,
            'productCount' => $this->productCount(),
            'sampleTitles' => $this->sampleTitles,
            'sampleProducts' => $this->sampleProducts,
            'terms' => $this->terms,
            'postTypes' => $this->postTypes,
            'attachmentFiles' => $this->attachmentFiles,
            'specificationValues' => $this->specificationValues,
            'descriptionSnippets' => $this->descriptionSnippets,
            'sourcesAvailable' => $this->sourcesAvailable,
            'wordpressAvailable' => $this->wordpressAvailable,
            'tcAvailable' => $this->tcAvailable,
        ];
    }
}
