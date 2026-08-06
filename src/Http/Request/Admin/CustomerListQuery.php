<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;

/**
 * Builds a CustomerListCriteria from GET /api/v1/admin/customers query params.
 * Throws InvalidArgumentException (mapped to 422 VALIDATION_FAILED) for
 * out-of-range pagination or unsupported sort options.
 */
final class CustomerListQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): CustomerListCriteria
    {
        return new CustomerListCriteria(
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            search: QueryParams::stringOrNull($query, 'search'),
            status: QueryParams::stringOrNull($query, 'status'),
            sortBy: self::normalizeSortBy(QueryParams::string($query, 'sortBy', CustomerListCriteria::SORT_CREATED_AT)),
            sortDirection: QueryParams::string($query, 'sortDirection', CustomerListCriteria::SORT_DESC),
            countryCode: QueryParams::stringOrNull($query, 'countryCode'),
            marketCode: QueryParams::stringOrNull($query, 'marketCode'),
            territoryCode: QueryParams::stringOrNull($query, 'territoryCode'),
        );
    }

    /**
     * Accepts both Core criteria values (`name`, `code`) and admin UI aliases
     * (`customerName`, `customerCode`).
     */
    private static function normalizeSortBy(string $sortBy): string
    {
        return match ($sortBy) {
            'customerName', 'CustomerName' => CustomerListCriteria::SORT_NAME,
            'customerCode', 'CustomerCode' => CustomerListCriteria::SORT_CODE,
            default => $sortBy,
        };
    }
}
