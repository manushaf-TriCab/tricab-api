<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Action\Admin\CatalogueDtoSerializer;
use TriCab\Api\Product\Persistence\InMemory\InMemoryCatalogueNavigationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAdminQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Service\CatalogueNodeEffectiveProductsService;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class PublicCatalogueRegionFilteringTest extends TestCase
{
    private InMemoryProductStore $store;
    private InMemoryCatalogueNavigationRepository $repo;
    private GetPublicCatalogueService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $this->repo = new InMemoryCatalogueNavigationRepository($this->store);
        $this->service = new GetPublicCatalogueService(
            $this->repo,
            new CatalogueNodeEffectiveProductsService(
                $this->repo,
                new InMemoryProductStyleRepository(),
                new InMemoryProductAdminQueryRepository($this->store),
            ),
        );
        $this->seedRegions();
    }

    public function test_australia_shows_only_au_products_and_prunes_empty_nodes(): void
    {
        $this->buildControlCablesFixture();

        $panel = $this->service->getPanel('products', 'AU', includeProducts: true);
        $slugs = $this->collectSlugs($panel['tree']);

        self::assertContains('control-cables', $slugs);
        self::assertContains('ali-flex', $slugs);
        self::assertContains('flexicom', $slugs);
        self::assertContains('mining-cable', $slugs);
        self::assertNotContains('renewable', $slugs);
    }

    public function test_new_zealand_hides_au_only_products_and_empty_branches(): void
    {
        $this->buildControlCablesFixture();

        $panel = $this->service->getPanel('products', 'NZ', includeProducts: true);
        $slugs = $this->collectSlugs($panel['tree']);

        self::assertContains('control-cables', $slugs);
        self::assertContains('ali-flex', $slugs);
        self::assertContains('mining-cable', $slugs);
        self::assertNotContains('flexicom', $slugs);
        self::assertNotContains('renewable', $slugs);
    }

    public function test_united_states_hides_nz_only_products(): void
    {
        $this->buildControlCablesFixture();

        $panel = $this->service->getPanel('products', 'US', includeProducts: true);
        $slugs = $this->collectSlugs($panel['tree']);

        self::assertContains('control-cables', $slugs);
        self::assertContains('flexicom', $slugs);
        self::assertContains('mining-cable', $slugs);
        self::assertNotContains('ali-flex', $slugs);
    }

    public function test_asia_hides_unavailable_products(): void
    {
        $this->buildControlCablesFixture();

        $panel = $this->service->getPanel('products', 'AS', includeProducts: true);
        $slugs = $this->collectSlugs($panel['tree']);

        self::assertContains('control-cables', $slugs);
        self::assertContains('ali-flex', $slugs);
        self::assertContains('flexicom', $slugs);
        self::assertNotContains('mining-cable', $slugs);
        self::assertNotContains('renewable', $slugs);
    }

    public function test_product_count_reports_direct_region_visible_placements_without_loading_products(): void
    {
        $this->buildControlCablesFixture();
        $this->addCrimpersFixture();

        $panel = $this->service->getPanel('products', 'AU');
        $crimpers = $this->findNode($panel['tree'], 'crimpers-dies');
        self::assertNotNull($crimpers);
        self::assertSame([], $crimpers->products);
        self::assertSame(3, $crimpers->productCount);
        self::assertTrue($crimpers->hasProducts);

        $tools = $this->findNode($panel['tree'], 'tools-dies');
        self::assertNotNull($tools);
        self::assertSame(0, $tools->productCount);

        $nz = $this->findNode($this->service->getPanel('products', 'NZ')['tree'], 'crimpers-dies');
        self::assertNotNull($nz);
        self::assertSame(1, $nz->productCount);

        $serialized = CatalogueDtoSerializer::publicTreeNode($crimpers);
        self::assertSame(3, $serialized['productCount']);
        self::assertSame([], $serialized['products']);
        $serializedTools = CatalogueDtoSerializer::publicTreeNode($tools);
        self::assertSame(0, $serializedTools['productCount']);
        $serializedChildren = $serializedTools['children'];
        self::assertIsArray($serializedChildren);
        self::assertIsArray($serializedChildren[0]);
        self::assertSame(3, $serializedChildren[0]['productCount']);
    }

    public function test_product_count_survives_include_products_and_node_detail(): void
    {
        $this->buildControlCablesFixture();
        $this->addCrimpersFixture();

        $withProducts = $this->findNode($this->service->getPanel('products', 'AU', includeProducts: true)['tree'], 'crimpers-dies');
        self::assertNotNull($withProducts);
        self::assertSame(3, $withProducts->productCount);
        self::assertCount(3, $withProducts->products);

        $detail = $this->service->getNodeBySlug('crimpers-dies', null, 'AU');
        self::assertSame(3, $detail->productCount);
        self::assertCount(3, $detail->products);

        $tools = $this->findNode($this->service->getPanel('products', 'AU')['tree'], 'tools-dies');
        self::assertNotNull($tools);
        $children = $this->service->listChildren($tools->publicId, 'AU');
        self::assertSame(3, $children[0]->productCount);
    }

    private function addCrimpersFixture(): void
    {
        $actor = UserId::fromInt(1);
        $tab = $this->repo->findTabBySlug('products');
        self::assertNotNull($tab);
        $tools = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'TOOLS',
            'nodeName' => 'Tools & Dies',
            'slug' => 'tools-dies',
        ], $actor);
        $crimpers = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $tools->publicId,
            'nodeCode' => 'CRIMP',
            'nodeName' => 'Crimpers & Dies',
            'slug' => 'crimpers-dies',
        ], $actor);
        $this->seedPublishedProduct('11111111-1111-1111-1111-111111111201', 'Z05', ['region-au']);
        $this->seedPublishedProduct('11111111-1111-1111-1111-111111111202', 'Z24', ['region-au']);
        $this->seedPublishedProduct('11111111-1111-1111-1111-111111111203', 'Z38', ['region-au', 'region-nz']);
        $this->repo->replaceNodeProducts($crimpers->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111201', 'displayOrder' => 1],
            ['productPublicId' => '11111111-1111-1111-1111-111111111202', 'displayOrder' => 2],
            ['productPublicId' => '11111111-1111-1111-1111-111111111203', 'displayOrder' => 3],
        ], $actor);
    }

    /** @param list<\TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode> $tree */
    private function findNode(array $tree, string $slug): ?\TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode
    {
        foreach ($tree as $node) {
            if ($node->slug === $slug) {
                return $node;
            }
            $found = $this->findNode($node->children, $slug);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function buildControlCablesFixture(): void
    {
        $actor = UserId::fromInt(1);
        $tab = $this->repo->createTab([
            'tabCode' => 'PRODUCTS',
            'tabName' => 'Products',
            'slug' => 'products',
        ], $actor);

        $industrial = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'IND',
            'nodeName' => 'Industrial',
            'slug' => 'industrial',
        ], $actor);
        $control = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $industrial->publicId,
            'nodeCode' => 'CTRL',
            'nodeName' => 'Control Cables',
            'slug' => 'control-cables',
        ], $actor);
        $renewable = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $industrial->publicId,
            'nodeCode' => 'REN',
            'nodeName' => 'Renewable',
            'slug' => 'renewable',
        ], $actor);

        $aliFlex = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $control->publicId,
            'nodeCode' => 'ALI',
            'nodeName' => 'ALI FLEX',
            'slug' => 'ali-flex',
        ], $actor);
        $flexicom = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $control->publicId,
            'nodeCode' => 'FLEX',
            'nodeName' => 'Flexicom',
            'slug' => 'flexicom',
        ], $actor);
        $mining = $this->repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $control->publicId,
            'nodeCode' => 'MIN',
            'nodeName' => 'Mining Cable',
            'slug' => 'mining-cable',
        ], $actor);

        $this->seedPublishedProduct('11111111-1111-1111-1111-111111111101', 'ALI FLEX', ['region-au', 'region-nz', 'region-as']);
        $this->seedPublishedProduct('11111111-1111-1111-1111-111111111102', 'Flexicom', ['region-au', 'region-us', 'region-as']);
        $this->seedPublishedProduct('11111111-1111-1111-1111-111111111103', 'Mining Cable', ['region-au', 'region-nz', 'region-us']);

        $this->repo->replaceNodeProducts($aliFlex->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111101', 'displayOrder' => 1],
        ], $actor);
        $this->repo->replaceNodeProducts($flexicom->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111102', 'displayOrder' => 1],
        ], $actor);
        $this->repo->replaceNodeProducts($mining->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111103', 'displayOrder' => 1],
        ], $actor);
    }

    private function seedRegions(): void
    {
        foreach ([
            ['publicId' => 'region-au', 'code' => 'AU', 'name' => 'Australia'],
            ['publicId' => 'region-nz', 'code' => 'NZ', 'name' => 'New Zealand'],
            ['publicId' => 'region-us', 'code' => 'US', 'name' => 'United States'],
            ['publicId' => 'region-as', 'code' => 'AS', 'name' => 'Asia'],
        ] as $region) {
            $this->store->addRegion([
                'publicId' => $region['publicId'],
                'code' => $region['code'],
                'name' => $region['name'],
                'displayOrder' => 1,
                'isActive' => true,
            ]);
        }
    }

    /** @param list<string> $regionPublicIds */
    private function seedPublishedProduct(string $publicId, string $name, array $regionPublicIds): void
    {
        $productId = $this->store->allocateProductId();
        $product = new Product(
            id: $productId,
            publicId: ProductPublicId::fromString($publicId),
            productName: $name,
            status: ProductStatusCode::published(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
            rowVersion: RowVersionToken::fromBase64('AAAAAAAAAAA='),
        );
        $now = $this->store->nowUtc();
        $this->store->saveProductRecord($productId, [
            'product' => $product,
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'createdByUserId' => 1,
            'updatedByUserId' => 1,
        ]);
        $this->store->setProductRegions($publicId, $regionPublicIds);
    }

    /** @param list<\TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode> $tree
     * @return list<string>
     */
    private function collectSlugs(array $tree): array
    {
        $slugs = [];
        foreach ($tree as $node) {
            $slugs[] = $node->slug;
            $slugs = [...$slugs, ...$this->collectSlugs($node->children)];
        }

        return $slugs;
    }
}
