<?php

declare(strict_types=1);

namespace TriCab\Api\DocumentGeneration\Rendering;

/**
 * Pivots API conductorComparison rows into the legacy quotation matrix
 * (sizes across the top; TriCab / Class5 / Class6 as rows).
 *
 * Values are already resolved wire counts from the document data service —
 * this class does not query the database.
 */
final readonly class ConductorComparisonMatrix
{
    /** @var list<string> */
    public const LEGACY_SIZES_MM2 = [
        '50', '70', '95', '120', '150', '185', '240', '300', '400', '500', '630',
    ];

    /**
     * @param list<string> $sizes
     * @param list<string|null> $tricab
     * @param list<string|null> $class5
     * @param list<string|null> $class6
     */
    public function __construct(
        public array $sizes,
        public array $tricab,
        public array $class5,
        public array $class6,
    ) {
    }

    /**
     * @param list<array{sizeMm2: string, tricab: string|null, class5: string|null, class6: string|null}> $rows
     */
    public static function fromRows(array $rows): self
    {
        /** @var array<string, array{sizeMm2: string, tricab: string|null, class5: string|null, class6: string|null}> $bySize */
        $bySize = [];
        foreach ($rows as $row) {
            $key = self::normalizeSizeKey($row['sizeMm2']);
            if ($key === null || array_key_exists($key, $bySize)) {
                continue;
            }
            $bySize[$key] = $row;
        }

        $sizes = [];
        $tricab = [];
        $class5 = [];
        $class6 = [];
        foreach (self::LEGACY_SIZES_MM2 as $size) {
            if (!array_key_exists($size, $bySize)) {
                continue;
            }
            $row = $bySize[$size];
            $tricabCount = self::wireCount($row['tricab']);
            if ($tricabCount === null) {
                continue;
            }
            $sizes[] = $size;
            $tricab[] = $tricabCount;
            $class5[] = self::wireCount($row['class5']);
            $class6[] = self::wireCount($row['class6']);
        }

        return new self($sizes, $tricab, $class5, $class6);
    }

    public function isEmpty(): bool
    {
        return $this->sizes === [];
    }

    private static function normalizeSizeKey(string $sizeMm2): ?string
    {
        $trimmed = trim($sizeMm2);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        return (string) (int) (float) $trimmed;
    }

    private static function wireCount(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        if (preg_match('/^(.+?)\s+x\s+/i', $trimmed, $matches) === 1) {
            $count = trim($matches[1]);

            return $count !== '' ? $count : null;
        }

        return $trimmed;
    }
}
