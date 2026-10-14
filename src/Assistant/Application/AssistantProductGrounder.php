<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

use Throwable;
use TriCab\Core\Product\Application\DTO\ProductCustomerViewDetail;
use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\DTO\ProductPublicSpecificationItem;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\Service\GetAdminProductCustomerViewService;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Builds the deliberately small, permission-safe product projection shared by
 * assistant pre-search and the agent's product tools.
 */
final readonly class AssistantProductGrounder
{
    public function __construct(
        private GetPublicProductService $getPublicProduct,
        private GetAdminProductCustomerViewService $getStaffProduct,
    ) {
    }

    public function fromSearchResult(
        ProductSearchResult $item,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): GroundedProduct {
        if ($catalogueVisibility === AssistantCatalogueVisibility::STAFF) {
            try {
                return $this->fromCustomerView($this->getStaffProduct->execute($item->publicId, $regionCode));
            } catch (Throwable) {
                // The safe search projection remains usable if a richer preview
                // races with an update or has incomplete migrated subresources.
            }
        }

        if ($item->slug !== null) {
            try {
                return $this->fromDetail($this->getPublicProduct->bySlug($item->slug, $regionCode));
            } catch (Throwable) {
                // The public search projection remains safe if publication or
                // indexing changes between the search and detail reads.
            }
        }

        return new GroundedProduct(
            publicId: $item->publicId,
            productName: $item->productName,
            productCode: $item->productCode,
            slug: $item->slug,
            seriesName: $item->seriesName,
            categoryName: $item->categoryName,
            summary: null,
            applications: [],
            specifications: [],
            thumbnailUrl: $item->thumbnailImageUrl ?? $item->thumbnailUrl,
            statusCode: $item->statusCode,
        );
    }

    public function byIdentifier(
        string $identifier,
        ?string $regionCode,
        AssistantCatalogueVisibility $catalogueVisibility,
    ): GroundedProduct {
        $trimmed = trim($identifier);
        if ($catalogueVisibility === AssistantCatalogueVisibility::STAFF && ProductPublicId::isValid($trimmed)) {
            return $this->fromCustomerView($this->getStaffProduct->execute($trimmed, $regionCode));
        }

        return $this->fromDetail($this->getPublicProduct->bySlug($trimmed, $regionCode));
    }

    private function fromDetail(ProductPublicDetail $detail): GroundedProduct
    {
        return new GroundedProduct(
            publicId: $detail->publicId,
            productName: $detail->productName,
            productCode: $detail->productCode,
            slug: $detail->slug,
            seriesName: $detail->seriesName,
            categoryName: $detail->categoryName,
            summary: $detail->shortDescription ?? $detail->fullDescription,
            applications: $detail->applicationNames,
            specifications: $this->specifications($detail->specifications),
            thumbnailUrl: $detail->thumbnailImageUrl ?? $detail->thumbnailUrl,
            statusCode: 'published',
        );
    }

    private function fromCustomerView(ProductCustomerViewDetail $detail): GroundedProduct
    {
        return new GroundedProduct(
            publicId: $detail->publicId,
            productName: $detail->productName,
            productCode: $detail->productCode,
            slug: $detail->primarySlug,
            seriesName: $detail->seriesName,
            categoryName: $detail->categoryName,
            summary: $detail->shortDescription ?? $detail->fullDescription,
            applications: $detail->applicationNames,
            specifications: $this->specifications($detail->specifications),
            thumbnailUrl: $detail->thumbnailUrl,
            statusCode: $detail->statusCode,
        );
    }

    /**
     * @param list<ProductPublicSpecificationItem> $specifications
     * @return list<array{name: string, value: string}>
     */
    private function specifications(array $specifications): array
    {
        $result = [];
        foreach ($specifications as $specification) {
            $value = trim((string) $specification->displayValue);
            if ($value === '') {
                continue;
            }
            if ($specification->unit !== null && trim($specification->unit) !== '') {
                $value .= ' ' . trim($specification->unit);
            }
            $result[] = [
                'name' => $specification->name,
                'value' => $value,
            ];
        }

        return $result;
    }
}
