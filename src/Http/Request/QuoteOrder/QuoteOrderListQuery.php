<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\QuoteOrder;

use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteOrderSearchCriteria;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteOrderType;

final class QuoteOrderListQuery
{
    /**
     * @param array<string, mixed> $params
     */
    public static function fromQueryParams(array $params): QuoteOrderSearchCriteria
    {
        $page = QueryParams::int($params, 'page', 1);
        $pageSize = QueryParams::int($params, 'pageSize', 25);
        $type = strtolower(QueryParams::string($params, 'type', QuoteOrderType::ALL));

        return new QuoteOrderSearchCriteria(
            page: $page,
            pageSize: $pageSize,
            reference: QueryParams::stringOrNull($params, 'reference'),
            customerCode: QueryParams::stringOrNull($params, 'customerCode'),
            representative: QueryParams::stringOrNull($params, 'representative'),
            from: QueryParams::stringOrNull($params, 'from'),
            to: QueryParams::stringOrNull($params, 'to'),
            type: $type,
            statusCode: QueryParams::stringOrNull($params, 'status'),
        );
    }
}
