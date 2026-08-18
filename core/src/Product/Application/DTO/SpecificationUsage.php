<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class SpecificationUsage
{
    public function __construct(
        public string $publicId,
        public string $level,
        public int $productValueCount = 0,
        public int $variantValueCount = 0,
        public int $definitionCount = 0,
        public int $activeDefinitionCount = 0,
        public int $optionCount = 0,
        public int $templateCount = 0,
    ) {
    }

    public function hasBlockingReferencesForArchive(): bool
    {
        return match ($this->level) {
            'group' => $this->definitionCount > 0,
            'definition' => ($this->productValueCount + $this->variantValueCount) > 0,
            'option' => ($this->productValueCount + $this->variantValueCount) > 0,
            'unit' => $this->definitionCount > 0,
            default => ($this->productValueCount + $this->variantValueCount + $this->definitionCount) > 0,
        };
    }

    public function summaryMessage(): string
    {
        $parts = [];
        if ($this->definitionCount > 0) {
            $parts[] = sprintf('%d Definitions', $this->definitionCount);
        }
        if ($this->optionCount > 0) {
            $parts[] = sprintf('%d Options', $this->optionCount);
        }
        if ($this->productValueCount > 0) {
            $parts[] = sprintf('%d Product values', $this->productValueCount);
        }
        if ($this->variantValueCount > 0) {
            $parts[] = sprintf('%d Variant values', $this->variantValueCount);
        }
        if ($this->templateCount > 0) {
            $parts[] = sprintf('%d Templates', $this->templateCount);
        }

        return $parts === [] ? 'No references.' : implode(' and ', $parts) . ' use it.';
    }
}
