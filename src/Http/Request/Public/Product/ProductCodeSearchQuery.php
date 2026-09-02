<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Public\Product;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\Product\Application\DTO\ProductCodeSearchCriteria;

/**
 * Builds a ProductCodeSearchCriteria from GET /api/v1/product-codes/search.
 *
 * Unlike the product search, no parameter is required — a facet-only query is the
 * normal case here. What is enforced is that the request names at least one
 * constraint, so an empty query string cannot page through all 32,000 codes by
 * accident; asking for everything has to be explicit via ?all=true.
 *
 * publishedOnly is forced true, as on every public endpoint. It scopes the
 * catalogue decoration (name, slug, variant), not the codes themselves: an
 * unpublished or entirely unloaded code is still a real code and still returned.
 */
final class ProductCodeSearchQuery
{
    /**
     * @param array<string, mixed> $query
     */
    public static function fromQueryParams(array $query): ProductCodeSearchCriteria
    {
        $criteria = new ProductCodeSearchCriteria(
            text: QueryParams::stringOrNull($query, 'text'),
            styleCodes: QueryParams::stringList($query, 'styleCodes'),
            cableType: QueryParams::stringOrNull($query, 'cableType'),
            voltageClass: QueryParams::stringOrNull($query, 'voltageClass'),
            coreCount: QueryParams::intOrNull($query, 'coreCount'),
            coreCountMin: QueryParams::intOrNull($query, 'coreCountMin'),
            coreCountMax: QueryParams::intOrNull($query, 'coreCountMax'),
            coreType: QueryParams::stringOrNull($query, 'coreType'),
            crossSectionMm2Min: QueryParams::floatOrNull($query, 'crossSectionMm2Min'),
            crossSectionMm2Max: QueryParams::floatOrNull($query, 'crossSectionMm2Max'),
            conductorCode: QueryParams::stringOrNull($query, 'conductorCode'),
            jacketColour: QueryParams::stringOrNull($query, 'jacketColour'),
            braidArmourScreen: QueryParams::stringOrNull($query, 'braidArmourScreen'),
            isLszh: QueryParams::boolOrNull($query, 'isLszh'),
            isFireResistant: QueryParams::boolOrNull($query, 'isFireResistant'),
            isArmoured: QueryParams::boolOrNull($query, 'isArmoured'),
            hasScreen: QueryParams::boolOrNull($query, 'hasScreen'),
            stockStatuses: QueryParams::stringList($query, 'stockStatus'),
            isCurrent: QueryParams::boolOrNull($query, 'isCurrent'),
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            publishedOnly: true,
        );

        if (!self::namesAConstraint($criteria) && QueryParams::boolOrNull($query, 'all') !== true) {
            throw HttpRequestValidationFailed::unprocessable(
                'VALIDATION_FAILED',
                'At least one search parameter is required. Pass all=true to page the whole index.',
            );
        }

        return $criteria;
    }

    private static function namesAConstraint(ProductCodeSearchCriteria $criteria): bool
    {
        return $criteria->normalizedText() !== null
            || $criteria->styleCodes !== []
            || $criteria->stockStatuses !== []
            || $criteria->cableType !== null
            || $criteria->voltageClass !== null
            || $criteria->coreCount !== null
            || $criteria->coreCountMin !== null
            || $criteria->coreCountMax !== null
            || $criteria->coreType !== null
            || $criteria->crossSectionMm2Min !== null
            || $criteria->crossSectionMm2Max !== null
            || $criteria->conductorCode !== null
            || $criteria->jacketColour !== null
            || $criteria->braidArmourScreen !== null
            || $criteria->isLszh !== null
            || $criteria->isFireResistant !== null
            || $criteria->isArmoured !== null
            || $criteria->hasScreen !== null
            || $criteria->isCurrent !== null;
    }
}
