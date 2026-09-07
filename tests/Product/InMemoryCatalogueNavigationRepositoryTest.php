<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryCatalogueNavigationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class InMemoryCatalogueNavigationRepositoryTest extends TestCase
{
    public function test_tab_node_tree_and_public_reads(): void
    {
        $store = new InMemoryProductStore();
        $repo = new InMemoryCatalogueNavigationRepository($store);
        $actor = UserId::fromInt(1);

        $tab = $repo->createTab([
            'tabCode' => 'SHOP',
            'tabName' => 'Shop',
            'slug' => 'shop',
            'displayOrder' => 10,
        ], $actor);

        $root = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'ROOT',
            'nodeName' => 'Root',
            'slug' => 'root',
        ], $actor);
        $child = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $root->publicId,
            'nodeCode' => 'CHILD',
            'nodeName' => 'Child',
            'slug' => 'child',
        ], $actor);

        self::assertSame(1, $repo->nodeDepth($child->publicId));
        self::assertTrue($repo->wouldCreateCycle($root->publicId, $child->publicId));
        self::assertFalse($repo->wouldCreateCycle($child->publicId, $root->publicId));

        $forest = $repo->buildAdminForest($tab->publicId);
        self::assertCount(1, $forest);
        self::assertSame('root', $forest[0]->slug);
        self::assertCount(1, $forest[0]->children);

        $publicTabs = $repo->listPublicTabs();
        self::assertNotEmpty($publicTabs);
        $tree = $repo->getPublicTabTree('shop', null, false);
        self::assertCount(1, $tree);
        self::assertSame('root', $tree[0]->slug);
    }

    public function test_public_product_cards_use_preferred_product_image_thumbnail(): void
    {
        $store = new InMemoryProductStore();
        $repo = new InMemoryCatalogueNavigationRepository($store);
        $actor = UserId::fromInt(1);
        $productPublicId = '11111111-1111-1111-1111-111111111201';
        $this->seedPublishedProduct($store, $productPublicId, 'BV Cable');
        $store->addMedia([
            'publicId' => 'media-gallery',
            'productPublicId' => $productPublicId,
            'mediaCategoryCode' => 'gallery',
            'mediaTypeCode' => 'gallery-image',
            'title' => 'Gallery',
            'publicUrl' => 'https://cdn.example/gallery.jpg',
            'isPrimary' => false,
            'displayOrder' => 0,
        ]);
        $store->addMedia([
            'publicId' => 'media-thumb',
            'productPublicId' => $productPublicId,
            'mediaCategoryCode' => 'product-thumbnail',
            'mediaTypeCode' => 'primary-image',
            'title' => 'Thumb',
            'publicUrl' => 'https://cdn.example/thumb.jpg',
            'isPrimary' => false,
            'displayOrder' => 1,
        ]);

        $tab = $repo->createTab([
            'tabCode' => 'SHOP',
            'tabName' => 'Shop',
            'slug' => 'shop',
        ], $actor);
        $leaf = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'BV',
            'nodeName' => 'BV',
            'slug' => 'bv',
        ], $actor);
        $repo->replaceNodeProducts($leaf->publicId, [
            ['productPublicId' => $productPublicId, 'displayOrder' => 1],
        ], $actor);

        $cards = $repo->listPublicNodeProducts($leaf->publicId);
        self::assertCount(1, $cards);
        self::assertSame('https://cdn.example/thumb.jpg', $cards[0]->thumbnailUrl);

        $batch = $repo->listPublicProductThumbnailUrls([$productPublicId, 'missing']);
        self::assertSame('https://cdn.example/thumb.jpg', $batch[$productPublicId]);
        self::assertArrayNotHasKey('missing', $batch);
    }

    public function test_public_node_attaches_products_to_requested_node_not_every_descendant(): void
    {
        $store = new InMemoryProductStore();
        $repo = new InMemoryCatalogueNavigationRepository($store);
        $actor = UserId::fromInt(1);
        $rootProduct = '11111111-1111-1111-1111-111111111211';
        $childProduct = '11111111-1111-1111-1111-111111111212';
        $this->seedPublishedProduct($store, $rootProduct, 'Root Cable');
        $this->seedPublishedProduct($store, $childProduct, 'Child Cable');

        $tab = $repo->createTab([
            'tabCode' => 'SHOP',
            'tabName' => 'Shop',
            'slug' => 'shop',
        ], $actor);
        $root = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'MARINE',
            'nodeName' => 'Marine IEC LSZH',
            'slug' => 'marine-iec-lszh',
        ], $actor);
        $child = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $root->publicId,
            'nodeCode' => 'SHIP',
            'nodeName' => 'Shipboard',
            'slug' => 'marine-shipboard',
        ], $actor);
        $repo->replaceNodeProducts($root->publicId, [
            ['productPublicId' => $rootProduct, 'displayOrder' => 1],
        ], $actor);
        $repo->replaceNodeProducts($child->publicId, [
            ['productPublicId' => $childProduct, 'displayOrder' => 1],
        ], $actor);

        $parent = $repo->getPublicNodeBySlug('marine-iec-lszh');
        self::assertNotNull($parent);
        self::assertCount(1, $parent->products);
        self::assertSame($rootProduct, $parent->products[0]->publicId);
        self::assertCount(1, $parent->children);
        self::assertSame([], $parent->children[0]->products);
        self::assertTrue($parent->children[0]->hasProducts);

        $openedChild = $repo->getPublicNodeBySlug('marine-shipboard');
        self::assertNotNull($openedChild);
        self::assertCount(1, $openedChild->products);
        self::assertSame($childProduct, $openedChild->products[0]->publicId);
        self::assertSame('Child Cable', $openedChild->products[0]->name);
    }

    private function seedPublishedProduct(InMemoryProductStore $store, string $publicId, string $name): void
    {
        $productId = $store->allocateProductId();
        $product = new Product(
            id: $productId,
            publicId: ProductPublicId::fromString($publicId),
            productName: $name,
            status: ProductStatusCode::published(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
            rowVersion: RowVersionToken::fromBase64('AAAAAAAAAAA='),
        );
        $now = $store->nowUtc();
        $store->saveProductRecord($productId, [
            'product' => $product,
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'createdByUserId' => 1,
            'updatedByUserId' => 1,
        ]);
    }
}
