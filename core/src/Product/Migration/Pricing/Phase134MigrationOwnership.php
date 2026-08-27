<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Encodes Phase 13.4 migration ownership into ErpSourceReference (NVARCHAR(100)).
 *
 * Compact format (must stay ≤ 100 chars):
 *   source:legacy-tc|p134|<batchId>|<normalizedCode>|<dcYYYYMMDD>|<baseCost>
 *
 * Source row IDs live in the protected reconciliation artifact, not in this column.
 */
final class Phase134MigrationOwnership
{
    public const SOURCE_PREFIX = 'source:legacy-tc|p134|';
    public const PRICE_SOURCE_CODE = 'legacy-tc';
    public const MAX_LENGTH = 100;

    /**
     * @param list<string> $sourceRowIds ignored in column (kept for API compatibility)
     */
    public static function encode(
        string $batchId,
        string $normalizedCode,
        int $dbco,
        ?string $dc,
        string $baseCost,
        array $sourceRowIds = [],
    ): string {
        unset($dbco, $sourceRowIds); // ownership column omits these; validated separately
        $dcCompact = self::compactDc($dc);
        $encoded = self::SOURCE_PREFIX . implode('|', [
            self::sanitize($batchId),
            self::sanitize($normalizedCode),
            $dcCompact,
            self::sanitize($baseCost),
        ]);
        if (strlen($encoded) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(
                'PRICE_OWNERSHIP_OVERFLOW: ErpSourceReference exceeds ' . self::MAX_LENGTH
            );
        }

        return $encoded;
    }

    /**
     * @return array{
     *   isPilotOwned: bool,
     *   batchId: ?string,
     *   normalizedCode: ?string,
     *   dbco: ?int,
     *   dc: ?string,
     *   baseCost: ?string,
     *   sourceRowIds: list<string>
     * }
     */
    public static function decode(?string $erpSourceReference): array
    {
        if ($erpSourceReference === null || !str_starts_with($erpSourceReference, self::SOURCE_PREFIX)) {
            return [
                'isPilotOwned' => false,
                'batchId' => null,
                'normalizedCode' => null,
                'dbco' => null,
                'dc' => null,
                'baseCost' => null,
                'sourceRowIds' => [],
            ];
        }

        $payload = substr($erpSourceReference, strlen(self::SOURCE_PREFIX));
        $parts = explode('|', $payload);

        $dcRaw = $parts[2] ?? '';
        $dc = $dcRaw !== '' ? self::expandDc($dcRaw) : null;

        return [
            'isPilotOwned' => true,
            'batchId' => $parts[0] ?? null,
            'normalizedCode' => $parts[1] ?? null,
            'dbco' => 2507,
            'dc' => $dc,
            'baseCost' => $parts[3] ?? null,
            'sourceRowIds' => [],
        ];
    }

    public static function isBatchOwned(?string $erpSourceReference, string $batchId): bool
    {
        $decoded = self::decode($erpSourceReference);

        return ($decoded['isPilotOwned'] ?? false)
            && ($decoded['batchId'] ?? null) === $batchId;
    }

    public static function candidateHash(
        string $normalizedCode,
        int $dbco,
        ?string $dc,
        string $baseCost,
        string $productVariantId,
        string $priceListCode,
    ): string {
        return hash('sha256', implode('|', [
            strtoupper(trim($normalizedCode)),
            (string) $dbco,
            (string) ($dc ?? ''),
            $baseCost,
            $productVariantId,
            $priceListCode,
        ]));
    }

    private static function compactDc(?string $dc): string
    {
        if ($dc === null || $dc === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', substr($dc, 0, 10)) ?? '';

        return substr($digits, 0, 8);
    }

    private static function expandDc(string $compact): string
    {
        if (strlen($compact) === 8) {
            return substr($compact, 0, 4) . '-' . substr($compact, 4, 2) . '-' . substr($compact, 6, 2);
        }

        return $compact;
    }

    private static function sanitize(string $value): string
    {
        return str_replace(['|', "\n", "\r"], ['/', '', ''], trim($value));
    }
}
