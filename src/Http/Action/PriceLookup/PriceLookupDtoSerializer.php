<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\PriceLookup;

use TriCab\Core\PriceLookup\Application\DTO\PriceLookupQuoteResult;
use TriCab\Core\PriceLookup\Application\DTO\PriceLookupSearchResult;

final class PriceLookupDtoSerializer
{
    /**
     * @return array{items: list<array<string, mixed>>, count: int, company: string, query: string}
     */
    public static function search(PriceLookupSearchResult $result): array
    {
        $items = [];
        foreach ($result->items as $item) {
            $items[] = [
                'productCode' => $item->productCode,
                'status' => $item->status,
                'setupLength' => $item->setupLength,
                'lastUpdated' => $item->lastUpdated,
            ];
        }

        return [
            'items' => $items,
            'count' => $result->count,
            'company' => $result->company,
            'query' => $result->query,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function quote(PriceLookupQuoteResult $result): array
    {
        return [
            'productCode' => $result->productCode,
            'status' => $result->status,
            'company' => $result->company,
            'currency' => $result->currency,
            'metric' => $result->metric,
            'adjustedCost' => $result->adjustedCost,
            'sell' => $result->sell,
            'baseCost' => $result->baseCost,
            'setupLength' => $result->setupLength,
            'setupPremium' => $result->setupPremium,
            'lastUpdated' => $result->lastUpdated,
            'costSource' => $result->costSource,
        ];
    }
}
