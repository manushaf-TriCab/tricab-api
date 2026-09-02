<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchResult;
use TriCab\Core\Product\Contract\ProductCodeSearchRepository;

/**
 * The memory-backed twin of AzureSqlProductCodeSearchRepository, so the API can be
 * exercised end to end without Azure SQL.
 *
 * Starts empty: there is no plausible fixture for a 32,000-code index, and a seeded
 * one would only invite tests that assert against invented cable codes. Callers seed
 * what they need with add().
 */
final class InMemoryProductCodeSearchRepository implements ProductCodeSearchRepository
{
    /** @var list<array{row: ProductCodeSearchResult, facets: array<string, string|bool>}> */
    private array $records = [];

    /**
     * @param array<string, string|bool> $facets Style-level facets keyed by definition code
     *                                           (CABLE-TYPE, IS-LSZH, ...). An absent key is
     *                                           unknown, exactly as a missing row is in SQL.
     */
    public function add(ProductCodeSearchResult $row, array $facets = []): void
    {
        $this->records[] = ['row' => $row, 'facets' => $facets];
    }

    public function search(ProductCodeSearchCriteria $criteria): ProductCodeSearchListResult
    {
        $matches = [];
        foreach ($this->records as $record) {
            if ($this->matches($record['row'], $record['facets'], $criteria)) {
                $matches[] = $record['row'];
            }
        }

        usort(
            $matches,
            static function (ProductCodeSearchResult $a, ProductCodeSearchResult $b): int {
                $rank = static fn (ProductCodeSearchResult $row): int => match ($row->stockStatus) {
                    'STOCK' => 0,
                    'MAKE' => 1,
                    default => 2,
                };

                return [!$a->isCurrent, $rank($a), $a->crossSectionMm2 ?? INF, $a->codeValue]
                    <=> [!$b->isCurrent, $rank($b), $b->crossSectionMm2 ?? INF, $b->codeValue];
            },
        );

        $total = count($matches);
        $offset = ($criteria->page - 1) * $criteria->pageSize;

        return new ProductCodeSearchListResult(
            items: array_slice($matches, $offset, $criteria->pageSize),
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function suggest(string $query, int $limit = 10): array
    {
        $trimmed = strtoupper(trim($query));
        if ($trimmed === '' || $limit < 1) {
            return [];
        }

        $suggestions = [];
        foreach ($this->records as $record) {
            $code = $record['row']->codeValue;
            if (str_starts_with(strtoupper($code), $trimmed) && !in_array($code, $suggestions, true)) {
                $suggestions[] = $code;
            }
        }

        sort($suggestions);

        return array_slice($suggestions, 0, $limit);
    }

    /**
     * @param array<string, string|bool> $facets
     */
    private function matches(
        ProductCodeSearchResult $row,
        array $facets,
        ProductCodeSearchCriteria $criteria,
    ): bool {
        foreach ($criteria->textTokens() as $token) {
            $needle = strtoupper($token);
            $haystacks = [$row->codeValue, $row->styleCode, $row->styleName ?? '', $row->description ?? ''];
            $hit = false;
            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && str_contains(strtoupper($haystack), $needle)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }

        $styleCodes = $criteria->normalizedStyleCodes();
        if ($styleCodes !== [] && !in_array(strtoupper($row->styleCode), $styleCodes, true)) {
            return false;
        }

        $stockStatuses = $criteria->normalizedStockStatuses();
        if ($stockStatuses !== [] && ($row->stockStatus === null || !in_array($row->stockStatus, $stockStatuses, true))) {
            return false;
        }

        if ($criteria->coreCount !== null && $row->coreCount !== $criteria->coreCount) {
            return false;
        }
        if ($criteria->coreCountMin !== null && ($row->coreCount === null || $row->coreCount < $criteria->coreCountMin)) {
            return false;
        }
        if ($criteria->coreCountMax !== null && ($row->coreCount === null || $row->coreCount > $criteria->coreCountMax)) {
            return false;
        }

        $size = $row->crossSectionNominalMm2 ?? $row->crossSectionMm2;
        if ($criteria->crossSectionMm2Min !== null && ($size === null || $size < $criteria->crossSectionMm2Min)) {
            return false;
        }
        if ($criteria->crossSectionMm2Max !== null && ($size === null || $size > $criteria->crossSectionMm2Max)) {
            return false;
        }

        foreach ([
            [$criteria->coreType, $row->coreTypeCode],
            [$criteria->conductorCode, $row->conductorCode],
            [$criteria->jacketColour, $row->jacketColour],
            [$criteria->braidArmourScreen, $row->braidArmourScreen],
        ] as [$wanted, $actual]) {
            if ($wanted === null || trim($wanted) === '') {
                continue;
            }
            if ($actual === null || strtoupper($actual) !== strtoupper(trim($wanted))) {
                return false;
            }
        }

        if ($criteria->isCurrent !== null && $row->isCurrent !== $criteria->isCurrent) {
            return false;
        }

        foreach ([
            'CABLE-TYPE' => $criteria->cableType,
            'VOLTAGE-CLASS' => $criteria->voltageClass,
        ] as $definitionCode => $wanted) {
            if ($wanted === null || trim($wanted) === '') {
                continue;
            }
            $actual = $facets[$definitionCode] ?? null;
            if (!is_string($actual) || strtoupper($actual) !== strtoupper(trim($wanted))) {
                return false;
            }
        }

        foreach ([
            'IS-LSZH' => $criteria->isLszh,
            'IS-FIRE-RESISTANT' => $criteria->isFireResistant,
            'IS-ARMOURED' => $criteria->isArmoured,
            'HAS-SCREEN' => $criteria->hasScreen,
        ] as $definitionCode => $wanted) {
            if ($wanted === null) {
                continue;
            }
            // Absent means unknown, and unknown matches neither true nor false.
            $actual = $facets[$definitionCode] ?? null;
            if (!is_bool($actual) || $actual !== $wanted) {
                return false;
            }
        }

        return true;
    }
}
