<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\CustomerContactReport;

use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportListCriteria;

final class CustomerContactReportListQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): CustomerContactReportListCriteria
    {
        return new CustomerContactReportListCriteria(
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            status: QueryParams::stringOrNull($query, 'status'),
            month: self::intOrNull($query, 'month'),
            year: self::intOrNull($query, 'year'),
            search: QueryParams::stringOrNull($query, 'search'),
            customer: QueryParams::stringOrNull($query, 'customer'),
            assignee: QueryParams::stringOrNull($query, 'assignee'),
            location: QueryParams::stringOrNull($query, 'location'),
            errorCode: QueryParams::stringOrNull($query, 'errorCode'),
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function intOrNull(array $query, string $key): ?int
    {
        if (!array_key_exists($key, $query) || $query[$key] === null || $query[$key] === '') {
            return null;
        }

        $value = $query[$key];
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
