<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin\Product;

use TriCab\Api\Http\Request\Admin\RequestFields;

/**
 * Validated body for PUT /api/v1/admin/products/{productPublicId}/seo.
 */
final readonly class UpdateSeoRequest
{
    private function __construct(
        public ?string $regionPublicId,
        public ?string $pageTitle,
        public ?string $metaDescription,
        public ?string $canonicalUrl,
        public ?string $openGraphTitle,
        public ?string $openGraphDescription,
        public ?string $openGraphImageUrl,
        public ?string $searchKeywords,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            regionPublicId: RequestFields::stringOrNull($body, 'regionPublicId'),
            pageTitle: RequestFields::stringOrNull($body, 'pageTitle'),
            metaDescription: RequestFields::stringOrNull($body, 'metaDescription'),
            canonicalUrl: RequestFields::stringOrNull($body, 'canonicalUrl'),
            openGraphTitle: RequestFields::stringOrNull($body, 'openGraphTitle'),
            openGraphDescription: RequestFields::stringOrNull($body, 'openGraphDescription'),
            openGraphImageUrl: RequestFields::stringOrNull($body, 'openGraphImageUrl'),
            searchKeywords: RequestFields::stringOrNull($body, 'searchKeywords'),
        );
    }
}
