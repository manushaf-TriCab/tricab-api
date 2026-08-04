<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Core\Customer\Application\DTO\ContactListCriteria;

/**
 * Builds a ContactListCriteria from GET .../contacts query params.
 * Throws InvalidArgumentException (mapped to 422 VALIDATION_FAILED) for
 * out-of-range pagination or unsupported sort options.
 */
final class ContactListQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): ContactListCriteria
    {
        return new ContactListCriteria(
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            search: QueryParams::stringOrNull($query, 'search'),
            isActive: QueryParams::boolOrNull($query, 'isActive'),
            portalAccessStatus: QueryParams::stringOrNull($query, 'portalAccessStatus'),
            sortBy: QueryParams::string($query, 'sortBy', ContactListCriteria::SORT_CREATED_AT),
            sortDirection: QueryParams::string($query, 'sortDirection', ContactListCriteria::SORT_DESC),
        );
    }
}
