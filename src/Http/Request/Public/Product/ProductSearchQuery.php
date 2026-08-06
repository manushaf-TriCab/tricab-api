<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Public\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;

/**
 * Builds a ProductSearchCriteria from GET /api/v1/product-search query params.
 * publishedOnly is always forced to true on this public endpoint — the query
 * string cannot be used to bypass it.
 */
final class ProductSearchQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): ProductSearchCriteria
    {
        $searchQuery = QueryParams::stringOrNull($query, 'query');
        if ($searchQuery === null) {
            throw HttpRequestValidationFailed::unprocessable('VALIDATION_FAILED', 'query is required.');
        }

        return new ProductSearchCriteria(
            query: $searchQuery,
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            regionCode: QueryParams::stringOrNull($query, 'regionCode'),
            publishedOnly: true,
        );
    }
}
