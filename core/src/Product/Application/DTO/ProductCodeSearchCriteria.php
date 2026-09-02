<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

use InvalidArgumentException;

/**
 * A structured query against [product].[ProductCodeIndex] — the code-level
 * projection built in Phase 1.
 *
 * Every field is optional. Unlike ProductSearchCriteria, which requires a free-text
 * query, a code search is legitimately facet-only: "LSZH marine, 4-25 mm2, 4 cores
 * or more" names no text at all. Text, where given, narrows; it does not gate.
 *
 * Size is expressed in mm2 against [CrossSectionMm2], the decimal projection that
 * exists precisely because CSA is stored as `text` in the EAV tables and cannot be
 * range-queried there.
 */
final readonly class ProductCodeSearchCriteria
{
    public const STOCK_STATUSES = ['STOCK', 'MAKE', 'NOT FOUND'];

    /**
     * @param list<string> $styleCodes Restrict to these style codes (segment 1).
     * @param list<string> $stockStatuses One or more of self::STOCK_STATUSES.
     */
    public function __construct(
        public ?string $text = null,
        public array $styleCodes = [],
        public ?string $cableType = null,
        public ?string $voltageClass = null,
        public ?int $coreCount = null,
        public ?int $coreCountMin = null,
        public ?int $coreCountMax = null,
        public ?string $coreType = null,
        public ?float $crossSectionMm2Min = null,
        public ?float $crossSectionMm2Max = null,
        public ?string $conductorCode = null,
        public ?string $jacketColour = null,
        public ?string $braidArmourScreen = null,
        public ?bool $isLszh = null,
        public ?bool $isFireResistant = null,
        public ?bool $isArmoured = null,
        public ?bool $hasScreen = null,
        public array $stockStatuses = [],
        public ?bool $isCurrent = null,
        public int $page = 1,
        public int $pageSize = 25,
        public bool $publishedOnly = true,
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException('page must be >= 1.');
        }
        if ($this->pageSize < 1 || $this->pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100.');
        }

        foreach (['coreCount' => $this->coreCount, 'coreCountMin' => $this->coreCountMin, 'coreCountMax' => $this->coreCountMax] as $field => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException($field . ' must be >= 1.');
            }
        }
        if ($this->coreCountMin !== null && $this->coreCountMax !== null && $this->coreCountMin > $this->coreCountMax) {
            throw new InvalidArgumentException('coreCountMin must not exceed coreCountMax.');
        }

        foreach (['crossSectionMm2Min' => $this->crossSectionMm2Min, 'crossSectionMm2Max' => $this->crossSectionMm2Max] as $field => $value) {
            if ($value !== null && $value <= 0.0) {
                throw new InvalidArgumentException($field . ' must be greater than 0.');
            }
        }
        if (
            $this->crossSectionMm2Min !== null
            && $this->crossSectionMm2Max !== null
            && $this->crossSectionMm2Min > $this->crossSectionMm2Max
        ) {
            throw new InvalidArgumentException('crossSectionMm2Min must not exceed crossSectionMm2Max.');
        }

        foreach ($this->styleCodes as $styleCode) {
            if (trim($styleCode) === '') {
                throw new InvalidArgumentException('styleCodes must contain non-empty strings.');
            }
        }

        foreach ($this->stockStatuses as $stockStatus) {
            if (!in_array(strtoupper(trim($stockStatus)), self::STOCK_STATUSES, true)) {
                throw new InvalidArgumentException(
                    'stockStatuses must contain one of: ' . implode(', ', self::STOCK_STATUSES) . '.',
                );
            }
        }
    }

    public function normalizedText(): ?string
    {
        if ($this->text === null) {
            return null;
        }

        $trimmed = trim($this->text);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Free text split on whitespace. The adapter requires every token to match
     * somewhere — AND across tokens, OR across columns — so "marine 3.3kv" is two
     * constraints rather than one opaque substring nothing contains.
     *
     * @return list<string>
     */
    public function textTokens(): array
    {
        $text = $this->normalizedText();
        if ($text === null) {
            return [];
        }

        $tokens = preg_split('/\s+/', $text);
        if ($tokens === false) {
            return [$text];
        }

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    /** @return list<string> */
    public function normalizedStyleCodes(): array
    {
        return $this->uniqueUpper($this->styleCodes);
    }

    /** @return list<string> */
    public function normalizedStockStatuses(): array
    {
        return $this->uniqueUpper($this->stockStatuses);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function uniqueUpper(array $values): array
    {
        $normalized = array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            $values,
        );

        return array_values(array_unique($normalized));
    }
}
