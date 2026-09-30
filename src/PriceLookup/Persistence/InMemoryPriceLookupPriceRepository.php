<?php

declare(strict_types=1);

namespace TriCab\Api\PriceLookup\Persistence;

use TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;

final class InMemoryPriceLookupPriceRepository implements PriceLookupPriceRepository
{
    /** @var array<string, mixed> product code → AU warehouse status, standing in for tpro/tstkwh_p (keys are the product master) */
    private array $warehouseStatuses = [];

    /**
     * @param list<array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed,
     *     dbco: string
     * }> $rows
     */
    public function __construct(
        private array $rows = [],
        private readonly PriceLookupColourMask $colourMask = new PriceLookupColourMask(),
    ) {
    }

    public function seedWarehouseStatus(string $code, mixed $status): void
    {
        $this->warehouseStatuses[strtoupper($code)] = $status;
    }

    /** Adds a product-master (tpro) code with no AU warehouse row. */
    public function seedProduct(string $code): void
    {
        $this->warehouseStatuses[strtoupper($code)] ??= null;
    }

    /**
     * @param array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed,
     *     dbco: string
     * } $row
     */
    public function seed(array $row): void
    {
        $this->rows[] = $row;
    }

    public function searchByPrefix(string $dbco, string $likePattern, int $limit): array
    {
        $regex = '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote($likePattern, '/')) . '$/i';
        $out = [];
        foreach ($this->rows as $row) {
            if ((string) $row['dbco'] !== $dbco) {
                continue;
            }
            if (!preg_match($regex, $row['productCode'])
                || $this->colourMask->isMask($row['productCode'])
                || $this->colourMask->isXxCodeOfOtherStyle($row['productCode'])
            ) {
                continue;
            }
            $out[] = $this->withoutDbco($row);
            if (count($out) >= $limit) {
                break;
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['productCode'], $b['productCode']));

        return $out;
    }

    public function findByCode(string $dbco, string $code): ?array
    {
        $needle = strtoupper($code);
        foreach ($this->rows as $row) {
            if ((string) $row['dbco'] === $dbco && strtoupper($row['productCode']) === $needle) {
                return $this->withoutDbco($row);
            }
        }

        return null;
    }

    public function findWarehouseStatusByCode(string $code): mixed
    {
        return $this->warehouseStatuses[strtoupper($code)] ?? null;
    }

    public function isProduct(string $code): bool
    {
        return array_key_exists(strtoupper($code), $this->warehouseStatuses);
    }

    public function listProductCodes(int $limit): array
    {
        $codes = array_values(array_filter(
            array_map('strval', array_keys($this->warehouseStatuses)),
            fn (string $code): bool => !$this->colourMask->isMask($code) && !$this->colourMask->isXxCodeOfOtherStyle($code),
        ));
        sort($codes);

        return array_slice($codes, 0, max(1, $limit));
    }

    /**
     * @param array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed,
     *     dbco: string
     * } $row
     * @return array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed
     * }
     */
    private function withoutDbco(array $row): array
    {
        return [
            'productCode' => $row['productCode'],
            'cost' => $row['cost'],
            'setupLength' => $row['setupLength'],
            'lastUpdated' => $row['lastUpdated'],
            'warehouseStatus' => $row['warehouseStatus'],
        ];
    }
}
