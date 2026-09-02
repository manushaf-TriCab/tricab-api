<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Action\Admin\CatalogueDtoSerializer;
use TriCab\Api\Http\Action\Admin\ProductManagementDtoSerializer;
use TriCab\Api\Product\Catalogue\CatalogueStaticMediaResolver;
use TriCab\Core\Product\Application\DTO\ProductApprovalCertificateItem;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Application\DTO\PublicCatalogueProductCard;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;

final class CataloguePublicMediaDtoTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/tricab-catalogue-media-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/media/catalogue', 0777, true);
        mkdir($this->publicDir . '/media/certificates', 0777, true);
        file_put_contents($this->publicDir . '/media/catalogue/bval.png', 'png');
        file_put_contents($this->publicDir . '/media/catalogue/r6.png', 'png');
        file_put_contents($this->publicDir . '/media/certificates/DNV_logo_RGB_tcm8-197625.webp', 'webp');
        CatalogueStaticMediaResolver::setInstance(new CatalogueStaticMediaResolver($this->publicDir));
    }

    protected function tearDown(): void
    {
        CatalogueStaticMediaResolver::reset();
    }

    public function test_public_panel_rewrites_website_assets_to_media_when_copy_exists(): void
    {
        $payload = CatalogueDtoSerializer::publicTreeNode($this->node('/assets/bval.png'));

        self::assertSame('/media/catalogue/bval.png', $payload['imageUrl']);
        self::assertArrayHasKey('imageAltText', $payload);
        self::assertArrayHasKey('children', $payload);
        self::assertArrayHasKey('products', $payload);
    }

    public function test_public_panel_normalizes_assests_typo(): void
    {
        $payload = CatalogueDtoSerializer::publicTreeNode($this->node('/assests/r6.png'));

        self::assertSame('/media/catalogue/r6.png', $payload['imageUrl']);
    }

    public function test_public_panel_falls_back_to_normalized_assets_when_media_missing(): void
    {
        $payload = CatalogueDtoSerializer::publicTreeNode($this->node('/assets/missing-file.png'));

        self::assertSame('/assets/missing-file.png', $payload['imageUrl']);
    }

    public function test_public_panel_keeps_null_image_when_node_has_none(): void
    {
        $payload = CatalogueDtoSerializer::publicTreeNode($this->node(null));

        self::assertNull($payload['imageUrl']);
    }

    public function test_product_card_keeps_blob_thumbnail_and_null_when_missing(): void
    {
        $blob = 'https://tricabv2.blob.core.windows.net/products/x.png';
        $withThumb = CatalogueDtoSerializer::publicProductCard(new PublicCatalogueProductCard(
            publicId: '11111111-1111-1111-1111-111111111111',
            name: 'BV',
            slug: 'bv',
            internalCode: 'BV',
            thumbnailUrl: $blob,
            displayOrder: 0,
            isFeatured: false,
        ));
        $without = CatalogueDtoSerializer::publicProductCard(new PublicCatalogueProductCard(
            publicId: '11111111-1111-1111-1111-111111111112',
            name: 'Empty',
            slug: 'empty',
            internalCode: null,
            thumbnailUrl: null,
            displayOrder: 1,
            isFeatured: false,
        ));

        self::assertSame($blob, $withThumb['thumbnailUrl']);
        self::assertNull($without['thumbnailUrl']);
    }

    public function test_search_rewrites_catalogue_assets_without_touching_blobs(): void
    {
        $blob = 'https://tricabv2.blob.core.windows.net/products/x.png';
        $rewritten = ProductManagementDtoSerializer::searchResult(new ProductSearchResult(
            publicId: '11111111-1111-1111-1111-111111111111',
            productName: 'R6',
            productCode: 'R6',
            slug: 'r6',
            categoryName: null,
            seriesName: null,
            statusCode: 'published',
            thumbnailUrl: '/assests/r6.png',
        ));
        $unchanged = ProductManagementDtoSerializer::searchResult(new ProductSearchResult(
            publicId: '11111111-1111-1111-1111-111111111113',
            productName: 'BV',
            productCode: 'BV',
            slug: 'bv',
            categoryName: null,
            seriesName: null,
            statusCode: 'published',
            thumbnailUrl: $blob,
            thumbnailImageUrl: $blob,
        ));

        self::assertSame('/media/catalogue/r6.png', $rewritten['thumbnailUrl']);
        self::assertSame($blob, $unchanged['thumbnailUrl']);
        self::assertSame($blob, $unchanged['thumbnailImageUrl']);
    }

    public function test_public_certificate_rewrites_website_logo_when_copy_exists(): void
    {
        $payload = ProductManagementDtoSerializer::approvalCertificate(new ProductApprovalCertificateItem(
            publicId: '11111111-1111-1111-1111-111111111114',
            code: 'DNV',
            name: 'DNV',
            issuer: 'DNV',
            imageUrl: '/certificates/DNV_logo_RGB_tcm8-197625.webp',
            statusCode: 'approved',
            displayOrder: 1,
        ));

        self::assertSame('/media/certificates/DNV_logo_RGB_tcm8-197625.webp', $payload['imageUrl']);
    }

    public function test_admin_node_record_keeps_stored_website_path(): void
    {
        $record = new \TriCab\Core\Product\Application\DTO\CatalogueNodeRecord(
            publicId: '11111111-1111-1111-1111-111111111110',
            tabPublicId: '11111111-1111-1111-1111-111111111100',
            tabSlug: 'products',
            tabName: 'Products',
            parentPublicId: null,
            parentName: null,
            nodeCode: 'BV',
            nodeName: 'BV',
            slug: 'bv',
            description: null,
            nodeTypeCode: 'category',
            imageUrl: '/assets/bval.png',
            imageAltText: null,
            imageMediaPublicId: null,
            displayOrder: 0,
            isActive: true,
            isPublic: true,
            isDeleted: false,
            seoTitle: null,
            seoDescription: null,
            rowVersion: 'v1',
        );

        $payload = CatalogueDtoSerializer::nodeRecord($record);
        self::assertSame('/assets/bval.png', $payload['imageUrl']);
    }

    private function node(?string $imageUrl): PublicCatalogueTreeNode
    {
        return new PublicCatalogueTreeNode(
            publicId: '11111111-1111-1111-1111-111111111110',
            nodeCode: 'BV',
            nodeName: 'BV',
            slug: 'bv',
            description: null,
            nodeTypeCode: 'category',
            imageUrl: $imageUrl,
            imageAltText: null,
            displayOrder: 0,
            depth: 1,
            hasChildren: false,
            hasProducts: false,
        );
    }
}
