<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\LogNote;

use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\LogNote\Application\DTO\LogNoteListCriteria;

final class LogNoteListQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query, ?string $customerPublicId = null): LogNoteListCriteria
    {
        return new LogNoteListCriteria(
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            customerPublicId: $customerPublicId ?? QueryParams::stringOrNull($query, 'customerId') ?? QueryParams::stringOrNull($query, 'customerPublicId'),
            customerCode: QueryParams::stringOrNull($query, 'customerCode'),
            interactionType: QueryParams::stringOrNull($query, 'interactionType'),
            category: QueryParams::stringOrNull($query, 'category'),
            createdBy: QueryParams::stringOrNull($query, 'createdBy'),
            from: QueryParams::stringOrNull($query, 'from'),
            to: QueryParams::stringOrNull($query, 'to'),
            search: QueryParams::stringOrNull($query, 'search'),
            country: QueryParams::stringOrNull($query, 'country'),
            territory: QueryParams::stringOrNull($query, 'territory'),
        );
    }
}
