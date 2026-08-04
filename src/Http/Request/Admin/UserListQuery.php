<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;

/**
 * Builds a UserListCriteria from GET /api/v1/admin/users query params.
 * Throws InvalidArgumentException (mapped to 422 VALIDATION_FAILED) for
 * out-of-range pagination or unsupported sort options.
 */
final class UserListQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): UserListCriteria
    {
        return new UserListCriteria(
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            search: QueryParams::stringOrNull($query, 'search'),
            status: QueryParams::stringOrNull($query, 'status'),
            roleCode: QueryParams::stringOrNull($query, 'role'),
            providerCode: QueryParams::stringOrNull($query, 'provider'),
            sortBy: QueryParams::string($query, 'sortBy', UserListCriteria::SORT_CREATED_AT),
            sortDirection: QueryParams::string($query, 'sortDirection', UserListCriteria::SORT_DESC),
        );
    }
}
