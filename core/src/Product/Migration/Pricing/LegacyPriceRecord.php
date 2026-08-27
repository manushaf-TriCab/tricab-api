<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Phase 13.2/13.3 input contract for one legacy tc.price row.
 * Read-only mapping — no persistence.
 */
final readonly class LegacyPriceRecord
{
    public function __construct(
        public string $sourceRowId,
        public string $code,
        public string $normalizedCode,
        public int $dbco,
        public string $cost,
        public ?string $dc,
        public ?string $br,
        public ?string $uwt,
        public ?string $uwtcu,
        public ?string $setup,
    ) {
    }

    /**
     * @param array{
     *   sourceRowId?: string|int|null,
     *   my_row_id?: string|int|null,
     *   code?: string|null,
     *   dbco?: string|int|null,
     *   cost?: string|int|float|null,
     *   dc?: string|null,
     *   br?: string|int|float|null,
     *   uwt?: string|int|float|null,
     *   uwtcu?: string|int|float|null,
     *   setup?: string|int|float|null
     * } $row
     */
    public static function fromLegacyRow(array $row): self
    {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        $sourceRowId = (string) ($row['sourceRowId'] ?? $row['my_row_id'] ?? '');

        return new self(
            sourceRowId: $sourceRowId,
            code: (string) ($row['code'] ?? ''),
            normalizedCode: $code,
            dbco: (int) ($row['dbco'] ?? 0),
            cost: is_numeric($row['cost'] ?? null) ? (string) $row['cost'] : (string) ($row['cost'] ?? ''),
            dc: isset($row['dc']) && $row['dc'] !== '' ? (string) $row['dc'] : null,
            br: self::nullableString($row['br'] ?? null),
            uwt: self::nullableString($row['uwt'] ?? null),
            uwtcu: self::nullableString($row['uwtcu'] ?? null),
            setup: self::nullableString($row['setup'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
