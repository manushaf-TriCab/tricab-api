<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;

/**
 * Builds a ProductListCriteria from GET /api/v1/admin/products query params.
 * Throws InvalidArgumentException (mapped to 422 VALIDATION_FAILED) for
 * out-of-range pagination or unsupported sort options.
 */
final class ProductListQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): ProductListCriteria
    {
        $query = self::normalizeAliases($query);

        return new ProductListCriteria(
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            search: QueryParams::stringOrNull($query, 'search'),
            familyPublicId: QueryParams::stringOrNull($query, 'familyPublicId'),
            categoryPublicId: QueryParams::stringOrNull($query, 'categoryPublicId'),
            seriesPublicId: QueryParams::stringOrNull($query, 'seriesPublicId'),
            regionPublicId: QueryParams::stringOrNull($query, 'regionPublicId'),
            marketPublicId: QueryParams::stringOrNull($query, 'marketPublicId'),
            industryPublicId: QueryParams::stringOrNull($query, 'industryPublicId'),
            applicationPublicId: QueryParams::stringOrNull($query, 'applicationPublicId'),
            statusCode: QueryParams::stringOrNull($query, 'statusCode'),
            lifecycleStatusCode: QueryParams::stringOrNull($query, 'lifecycleStatusCode'),
            hasVariants: QueryParams::boolOrNull($query, 'hasVariants'),
            hasDocuments: QueryParams::boolOrNull($query, 'hasDocuments'),
            hasMedia: QueryParams::boolOrNull($query, 'hasMedia'),
            hasPricing: QueryParams::boolOrNull($query, 'hasPricing'),
            missingRequiredInformation: QueryParams::boolOrNull($query, 'missingRequiredInformation'),
            missingRegion: QueryParams::boolOrNull($query, 'missingRegion'),
            missingSeo: QueryParams::boolOrNull($query, 'missingSeo'),
            missingSpecifications: QueryParams::boolOrNull($query, 'missingSpecifications'),
            publicationBlockers: QueryParams::boolOrNull($query, 'publicationBlockers'),
            isActive: QueryParams::boolOrNull($query, 'isActive'),
            isArchived: QueryParams::boolOrNull($query, 'isArchived'),
            includeDeleted: QueryParams::boolOrNull($query, 'includeDeleted') ?? false,
            updatedFromUtc: QueryParams::stringOrNull($query, 'updatedFromUtc'),
            updatedToUtc: QueryParams::stringOrNull($query, 'updatedToUtc'),
            sortBy: QueryParams::string($query, 'sortBy', ProductListCriteria::SORT_UPDATED_AT),
            sortDirection: QueryParams::string($query, 'sortDirection', ProductListCriteria::SORT_DESC),
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private static function normalizeAliases(array $query): array
    {
        $aliases = [
            'status' => 'statusCode',
            'lifecycleStatus' => 'lifecycleStatusCode',
            'archived' => 'isArchived',
            'sort' => 'sortBy',
            'direction' => 'sortDirection',
            'familyId' => 'familyPublicId',
            'categoryId' => 'categoryPublicId',
            'seriesId' => 'seriesPublicId',
            'regionId' => 'regionPublicId',
            'marketId' => 'marketPublicId',
            'industryId' => 'industryPublicId',
            'applicationId' => 'applicationPublicId',
        ];

        foreach ($aliases as $from => $to) {
            if (!array_key_exists($to, $query) && array_key_exists($from, $query)) {
                $query[$to] = $query[$from];
            }
        }

        return $query;
    }
}
