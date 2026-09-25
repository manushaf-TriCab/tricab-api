<?php

declare(strict_types=1);

namespace TriCab\Api\PriceLookup\Persistence;

use TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository;

final class InMemoryPriceLookupPriceRepository implements PriceLookupPriceRepository
{
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
    ) {
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
            if (!preg_match($regex, $row['productCode'])) {
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
