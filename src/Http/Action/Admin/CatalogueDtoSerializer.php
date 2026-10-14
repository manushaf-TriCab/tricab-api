<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use TriCab\Core\Product\Application\DTO\CatalogueNodeProductRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeProductStyleRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeResolvedProductRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeTreeNode;
use TriCab\Core\Product\Application\DTO\CatalogueNodeUsage;
use TriCab\Core\Product\Application\DTO\CatalogueProductPlacementRecord;
use TriCab\Core\Product\Application\DTO\CatalogueTabRecord;
use TriCab\Api\Product\Catalogue\CatalogueStaticMediaResolver;
use TriCab\Core\Product\Application\DTO\PublicCatalogueProductCard;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTab;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;

final class CatalogueDtoSerializer
{
    /** @return array<string, mixed> */
    public static function tabRecord(CatalogueTabRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'tabCode' => $record->tabCode,
            'tabName' => $record->tabName,
            'description' => $record->description,
            'slug' => $record->slug,
            'displayOrder' => $record->displayOrder,
            'isActive' => $record->isActive,
            'isPublic' => $record->isPublic,
            'isDeleted' => $record->isDeleted,
            'rowVersion' => $record->rowVersion,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
            'rootNodeCount' => $record->rootNodeCount,
        ];
    }

    /** @param list<CatalogueTabRecord> $items
     * @return list<array<string, mixed>>
     */
    public static function tabList(array $items): array
    {
        return array_map(self::tabRecord(...), $items);
    }

    /** @return array<string, mixed> */
    public static function nodeRecord(CatalogueNodeRecord $record): array
    {
        return [
            'publicId' => $record->publicId,
            'tabPublicId' => $record->tabPublicId,
            'tabSlug' => $record->tabSlug,
            'tabName' => $record->tabName,
            'parentPublicId' => $record->parentPublicId,
            'parentName' => $record->parentName,
            'nodeCode' => $record->nodeCode,
            'nodeName' => $record->nodeName,
            'slug' => $record->slug,
            'description' => $record->description,
            'nodeTypeCode' => $record->nodeTypeCode,
            'imageUrl' => $record->imageUrl,
            'imageAltText' => $record->imageAltText,
            'imageMediaPublicId' => $record->imageMediaPublicId,
            'displayOrder' => $record->displayOrder,
            'isActive' => $record->isActive,
            'isPublic' => $record->isPublic,
            'isDeleted' => $record->isDeleted,
            'seoTitle' => $record->seoTitle,
            'seoDescription' => $record->seoDescription,
            'rowVersion' => $record->rowVersion,
            'depth' => $record->depth,
            'childCount' => $record->childCount,
            'productCount' => $record->productCount,
            'regionCodes' => $record->regionCodes,
            'createdAtUtc' => $record->createdAtUtc,
            'updatedAtUtc' => $record->updatedAtUtc,
        ];
    }

    /** @param list<CatalogueNodeRecord> $items
     * @return list<array<string, mixed>>
     */
    public static function nodeList(array $items): array
    {
        return array_map(self::nodeRecord(...), $items);
    }

    /** @return array<string, mixed> */
    public static function nodeTreeNode(CatalogueNodeTreeNode $node): array
    {
        return [
            'publicId' => $node->publicId,
            'nodeCode' => $node->nodeCode,
            'nodeName' => $node->nodeName,
            'slug' => $node->slug,
            'description' => $node->description,
            'nodeTypeCode' => $node->nodeTypeCode,
            'imageUrl' => $node->imageUrl,
            'imageAltText' => $node->imageAltText,
            'displayOrder' => $node->displayOrder,
            'isActive' => $node->isActive,
            'isPublic' => $node->isPublic,
            'depth' => $node->depth,
            'childCount' => $node->childCount,
            'productCount' => $node->productCount,
            'regionCodes' => $node->regionCodes,
            'rowVersion' => $node->rowVersion,
            'parentPublicId' => $node->parentPublicId,
            'children' => array_map(self::nodeTreeNode(...), $node->children),
        ];
    }

    /** @param list<CatalogueNodeTreeNode> $items
     * @return list<array<string, mixed>>
     */
    public static function nodeTreeList(array $items): array
    {
        return array_map(self::nodeTreeNode(...), $items);
    }

    /** @return array<string, mixed> */
    public static function nodeUsage(CatalogueNodeUsage $usage): array
    {
        return [
            'publicId' => $usage->publicId,
            'childCount' => $usage->childCount,
            'activeChildCount' => $usage->activeChildCount,
            'productCount' => $usage->productCount,
            'regionCount' => $usage->regionCount,
            'summary' => $usage->summary,
        ];
    }

    /** @return array<string, mixed> */
    public static function nodeProduct(CatalogueNodeProductRecord $record): array
    {
        return [
            'productPublicId' => $record->productPublicId,
            'productName' => $record->productName,
            'productSlug' => $record->productSlug,
            'internalCode' => $record->internalCode,
            'thumbnailUrl' => $record->thumbnailUrl,
            'displayOrder' => $record->displayOrder,
            'isFeatured' => $record->isFeatured,
            'isActive' => $record->isActive,
            'publicationStatus' => $record->publicationStatus,
        ];
    }

    /** @param list<CatalogueNodeProductRecord> $items
     * @return list<array<string, mixed>>
     */
    public static function nodeProductList(array $items): array
    {
        return array_map(self::nodeProduct(...), $items);
    }

    /** @return array<string, mixed> */
    public static function nodeProductStyle(CatalogueNodeProductStyleRecord $record): array
    {
        return [
            'productStylePublicId' => $record->productStylePublicId,
            'styleCode' => $record->styleCode,
            'displayName' => $record->displayName,
            'displayOrder' => $record->displayOrder,
            'isActive' => $record->isActive,
            'childStyleCount' => $record->childStyleCount,
            'assignedProductCount' => $record->assignedProductCount,
        ];
    }

    /** @param list<CatalogueNodeProductStyleRecord> $items
     * @return list<array<string, mixed>>
     */
    public static function nodeProductStyleList(array $items): array
    {
        return array_map(self::nodeProductStyle(...), $items);
    }

    /** @return array<string, mixed> */
    public static function resolvedNodeProduct(CatalogueNodeResolvedProductRecord $record): array
    {
        return [
            'productPublicId' => $record->productPublicId,
            'productName' => $record->productName,
            'productCode' => $record->productCode,
            'provenance' => $record->provenance,
            'resolvedViaStyleCode' => $record->resolvedViaStyleCode,
            'linkedStyleCode' => $record->linkedStyleCode,
            'publicationStatus' => $record->publicationStatus,
            'publicVisible' => $record->publicVisible,
            'regionVisible' => $record->regionVisible,
            'displayOrder' => $record->displayOrder,
        ];
    }

    /** @param list<CatalogueNodeResolvedProductRecord> $items
     * @return list<array<string, mixed>>
     */
    public static function resolvedNodeProductList(array $items): array
    {
        return array_map(self::resolvedNodeProduct(...), $items);
    }

    /** @return array<string, mixed> */
    public static function productPlacement(CatalogueProductPlacementRecord $record): array
    {
        return [
            'nodePublicId' => $record->nodePublicId,
            'nodeName' => $record->nodeName,
            'nodeSlug' => $record->nodeSlug,
            'tabPublicId' => $record->tabPublicId,
            'tabName' => $record->tabName,
            'tabSlug' => $record->tabSlug,
            'displayOrder' => $record->displayOrder,
            'isFeatured' => $record->isFeatured,
            'isActive' => $record->isActive,
            'breadcrumbNames' => $record->breadcrumbNames,
        ];
    }

    /** @param list<CatalogueProductPlacementRecord> $items
     * @return list<array<string, mixed>>
     */
    public static function productPlacementList(array $items): array
    {
        return array_map(self::productPlacement(...), $items);
    }

    /** @return array<string, mixed> */
    public static function publicTab(PublicCatalogueTab $tab): array
    {
        return [
            'publicId' => $tab->publicId,
            'tabCode' => $tab->tabCode,
            'tabName' => $tab->tabName,
            'description' => $tab->description,
            'slug' => $tab->slug,
            'displayOrder' => $tab->displayOrder,
        ];
    }

    /** @param list<PublicCatalogueTab> $items
     * @return list<array<string, mixed>>
     */
    public static function publicTabList(array $items): array
    {
        return array_map(self::publicTab(...), $items);
    }

    /** @return array<string, mixed> */
    public static function publicTreeNode(PublicCatalogueTreeNode $node): array
    {
        return [
            'publicId' => $node->publicId,
            'nodeCode' => $node->nodeCode,
            'nodeName' => $node->nodeName,
            'slug' => $node->slug,
            'description' => $node->description,
            'nodeTypeCode' => $node->nodeTypeCode,
            'imageUrl' => CatalogueStaticMediaResolver::instance()->toPublicUrl($node->imageUrl),
            'productThumbnailUrl' => CatalogueStaticMediaResolver::instance()->toPublicUrl($node->productThumbnailUrl),
            'imageAltText' => $node->imageAltText,
            'displayOrder' => $node->displayOrder,
            'depth' => $node->depth,
            'hasChildren' => $node->hasChildren,
            'hasProducts' => $node->hasProducts,
            'parentPublicId' => $node->parentPublicId,
            'children' => array_map(self::publicTreeNode(...), $node->children),
            'products' => array_map(self::publicProductCard(...), $node->products),
        ];
    }

    /** @param list<PublicCatalogueTreeNode> $items
     * @return list<array<string, mixed>>
     */
    public static function publicTreeList(array $items): array
    {
        return array_map(self::publicTreeNode(...), $items);
    }

    /** @return array<string, mixed> */
    public static function publicProductCard(PublicCatalogueProductCard $card): array
    {
        return [
            'publicId' => $card->publicId,
            'name' => $card->name,
            'slug' => $card->slug,
            'internalCode' => $card->internalCode,
            'thumbnailUrl' => CatalogueStaticMediaResolver::instance()->toPublicUrl($card->thumbnailUrl),
            'displayOrder' => $card->displayOrder,
            'isFeatured' => $card->isFeatured,
        ];
    }

    /**
     * @param array{
     *   tabs: list<PublicCatalogueTab>,
     *   selectedTabSlug: ?string,
     *   tree: list<PublicCatalogueTreeNode>
     * } $panel
     * @return array<string, mixed>
     */
    public static function publicPanel(array $panel): array
    {
        return [
            'tabs' => self::publicTabList($panel['tabs']),
            'selectedTabSlug' => $panel['selectedTabSlug'],
            'tree' => self::publicTreeList($panel['tree']),
        ];
    }
}
