<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Action\Admin\CatalogueDtoSerializer;
use TriCab\Api\Product\Persistence\InMemory\InMemoryCatalogueNavigationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAdminQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;
use TriCab\Core\Product\Application\Service\CatalogueNodeEffectiveProductsService;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;

/**
 * The bulk tree enrichment (summarizePublicNodes) must return exactly what the per-node
 * listPublicProductCards / hasPublicVisibleProducts calls returned, for every region.
 */
final class PublicCatalogueBulkEffectiveProductsTest extends TestCase
{
    private InMemoryProductStore $store;
    private InMemoryCatalogueNavigationRepository $repo;
    private InMemoryProductStyleRepository $styles;
    private CountingProductAdminQueryRepository $products;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $this->repo = new InMemoryCatalogueNavigationRepository($this->store);
        $this->styles = new InMemoryProductStyleRepository();
        $this->products = new CountingProductAdminQueryRepository(new InMemoryProductAdminQueryRepository($this->store));
        $this->seedRegions();
        $this->buildFixture();
    }

    public function test_bulk_summary_matches_per_node_results_for_every_region(): void
    {
        foreach ([null, 'AU', 'NZ', 'US', 'AS'] as $region) {
            $tree = $this->repo->getPublicTabTree('products', $region, false);
            $directHasProducts = [];
            $this->collect($tree, $directHasProducts);
            self::assertGreaterThan(5, count($directHasProducts));

            $legacy = $this->newEffectiveProducts();
            $expectedCards = [];
            $expectedHas = [];
            foreach ($directHasProducts as $nodeId => $directHas) {
                $expectedCards[$nodeId] = $legacy->listPublicProductCards((string) $nodeId, $region);
                $expectedHas[$nodeId] = $legacy->hasPublicVisibleProducts((string) $nodeId, $directHas, $region);
            }

            $summary = $this->newEffectiveProducts()->summarizePublicNodes($directHasProducts, $region, true);

            self::assertEquals($expectedCards, $summary['cards'], 'cards differ for region ' . ($region ?? 'none'));
            self::assertSame($expectedHas, $summary['hasProducts'], 'hasProducts differ for region ' . ($region ?? 'none'));
        }
    }

    public function test_fixture_exercises_style_links_publication_and_regions(): void
    {
        $tree = $this->repo->getPublicTabTree('products', 'AU', false);
        $directHasProducts = [];
        $this->collect($tree, $directHasProducts);
        $summary = $this->newEffectiveProducts()->summarizePublicNodes($directHasProducts, 'AU', true);

        $styled = $this->nodeId($tree, 'styled-family');
        $names = array_map(static fn ($card): string => $card->name, $summary['cards'][$styled]);
        sort($names);
        self::assertSame(['Direct Styled', 'Style Child AU'], $names, 'style-linked products resolved, NZ-only and draft hidden');
        self::assertTrue($summary['hasProducts'][$styled]);

        $scheduled = $this->nodeId($tree, 'scheduled');
        self::assertSame([], $summary['cards'][$scheduled], 'future-publish and draft products stay hidden');
    }

    public function test_bulk_summary_reads_product_details_in_bulk_not_per_product(): void
    {
        $tree = $this->repo->getPublicTabTree('products', 'AU', false);
        $directHasProducts = [];
        $this->collect($tree, $directHasProducts);

        $this->products->reset();
        $this->newEffectiveProducts()->summarizePublicNodes($directHasProducts, 'AU', true);
        self::assertSame(0, $this->products->singleDetailCalls);
        self::assertLessThanOrEqual(2, $this->products->bulkDetailCalls);

        $this->products->reset();
        $legacy = $this->newEffectiveProducts();
        foreach (array_keys($directHasProducts) as $nodeId) {
            $legacy->listPublicProductCards((string) $nodeId, 'AU');
        }
        self::assertGreaterThan(5, $this->products->singleDetailCalls, 'per-node path still reads per product');
    }

    public function test_panel_with_products_serializes_identically_to_per_node_enrichment(): void
    {
        foreach (['AU', 'NZ'] as $region) {
            $service = new GetPublicCatalogueService($this->repo, $this->newEffectiveProducts());
            $panel = $service->getPanel('products', $region, includeProducts: true);

            $legacy = $this->newEffectiveProducts();
            $expected = $this->legacyEnrich($this->repo->getPublicTabTree('products', $region, true), $legacy, $region);
            $expected = (new \TriCab\Core\Product\Application\Service\RegionAwareCatalogueTreeFilter())->filterTree($expected);

            self::assertSame(
                CatalogueDtoSerializer::publicTreeList($expected),
                CatalogueDtoSerializer::publicTreeList($panel['tree']),
                'panel tree differs for ' . $region,
            );
        }
    }

    /**
     * The enrichment GetPublicCatalogueService performed before the bulk path.
     *
     * @param list<PublicCatalogueTreeNode> $tree
     * @return list<PublicCatalogueTreeNode>
     */
    private function legacyEnrich(array $tree, CatalogueNodeEffectiveProductsService $effective, string $region): array
    {
        $out = [];
        foreach ($tree as $node) {
            $children = $this->legacyEnrich($node->children, $effective, $region);
            $out[] = new PublicCatalogueTreeNode(
                publicId: $node->publicId,
                nodeCode: $node->nodeCode,
                nodeName: $node->nodeName,
                slug: $node->slug,
                description: $node->description,
                nodeTypeCode: $node->nodeTypeCode,
                imageUrl: $node->imageUrl,
                imageAltText: $node->imageAltText,
                displayOrder: $node->displayOrder,
                depth: $node->depth,
                hasChildren: $children !== [],
                hasProducts: $effective->hasPublicVisibleProducts($node->publicId, $node->hasProducts, $region),
                children: $children,
                products: $effective->listPublicProductCards($node->publicId, $region),
                parentPublicId: $node->parentPublicId,
                productThumbnailUrl: $node->productThumbnailUrl,
                productCount: $node->productCount,
            );
        }

        return $out;
    }

    private function newEffectiveProducts(): CatalogueNodeEffectiveProductsService
    {
        return new CatalogueNodeEffectiveProductsService($this->repo, $this->styles, $this->products);
    }

    /**
     * @param list<PublicCatalogueTreeNode> $tree
     * @param array<string, bool> $out
     */
    private function collect(array $tree, array &$out): void
    {
        foreach ($tree as $node) {
            $out[$node->publicId] = $node->hasProducts;
            $this->collect($node->children, $out);
        }
    }

    /** @param list<PublicCatalogueTreeNode> $tree */
    private function nodeId(array $tree, string $slug): string
    {
        foreach ($tree as $node) {
            if ($node->slug === $slug) {
                return $node->publicId;
            }
            $found = $this->nodeIdOrNull($node->children, $slug);
            if ($found !== null) {
                return $found;
            }
        }
        self::fail('node not found: ' . $slug);
    }

    /** @param list<PublicCatalogueTreeNode> $tree */
    private function nodeIdOrNull(array $tree, string $slug): ?string
    {
        foreach ($tree as $node) {
            if ($node->slug === $slug) {
                return $node->publicId;
            }
            $found = $this->nodeIdOrNull($node->children, $slug);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function buildFixture(): void
    {
        $actor = UserId::fromInt(1);
        $tab = $this->repo->createTab(['tabCode' => 'PRODUCTS', 'tabName' => 'Products', 'slug' => 'products'], $actor);
        $node = fn (string $code, string $slug, ?string $parent = null, int $order = 0) => $this->repo->createNode(array_filter([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $parent,
            'nodeCode' => $code,
            'nodeName' => ucfirst($slug),
            'slug' => $slug,
            'displayOrder' => $order,
        ], static fn ($value): bool => $value !== null), $actor);

        $industrial = $node('IND', 'industrial');
        $control = $node('CTRL', 'control-cables', $industrial->publicId);
        $aliFlex = $node('ALI', 'ali-flex', $control->publicId, 2);
        $flexicom = $node('FLEX', 'flexicom', $control->publicId, 1);
        $node('EMPTY', 'empty-leaf', $control->publicId, 3);
        $scheduled = $node('SCHED', 'scheduled', $industrial->publicId);
        $styled = $node('STYLE', 'styled-family', $industrial->publicId);
        $styledOnly = $node('STYLEONLY', 'style-only', $industrial->publicId);

        $this->seedProduct('11111111-1111-1111-1111-111111111101', 'Ali Flex B', ['region-au', 'region-nz']);
        $this->seedProduct('11111111-1111-1111-1111-111111111102', 'Ali Flex A', ['region-au', 'region-us']);
        $this->seedProduct('11111111-1111-1111-1111-111111111103', 'Flexicom', ['region-au', 'region-as']);
        $this->seedProduct('11111111-1111-1111-1111-111111111104', 'Draft', ['region-au'], ProductStatusCode::draft());
        $this->seedProduct(
            '11111111-1111-1111-1111-111111111105',
            'Future',
            ['region-au'],
            publishAtUtc: new DateTimeImmutable('+30 days', new DateTimeZone('UTC')),
        );
        $this->seedProduct('11111111-1111-1111-1111-111111111106', 'Direct Styled', ['region-au', 'region-nz']);
        $this->seedProduct('11111111-1111-1111-1111-111111111107', 'Style Child AU', ['region-au']);
        $this->seedProduct('11111111-1111-1111-1111-111111111108', 'Style Child NZ', ['region-nz']);
        $this->seedProduct('11111111-1111-1111-1111-111111111109', 'Style Draft', ['region-au'], ProductStatusCode::draft());

        $this->repo->replaceNodeProducts($aliFlex->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111101', 'displayOrder' => 1],
            ['productPublicId' => '11111111-1111-1111-1111-111111111102', 'displayOrder' => 1],
            ['productPublicId' => '11111111-1111-1111-1111-111111111104', 'displayOrder' => 2],
        ], $actor);
        $this->repo->replaceNodeProducts($flexicom->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111103', 'displayOrder' => 1],
        ], $actor);
        $this->repo->replaceNodeProducts($scheduled->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111105', 'displayOrder' => 1],
            ['productPublicId' => '11111111-1111-1111-1111-111111111104', 'displayOrder' => 2],
        ], $actor);
        $this->repo->replaceNodeProducts($styled->publicId, [
            ['productPublicId' => '11111111-1111-1111-1111-111111111106', 'displayOrder' => 1],
        ], $actor);

        $parentStyle = $this->createStyle('FAM');
        $childStyle = $this->createStyle('FAM-CHILD', $parentStyle);
        foreach (['106', '107', '108', '109'] as $suffix) {
            $this->styles->assignProduct('11111111-1111-1111-1111-111111111' . $suffix, $childStyle, $actor);
        }
        $this->repo->addNodeProductStyle($styled->publicId, $parentStyle, 1, $actor);
        $this->repo->addNodeProductStyle($styledOnly->publicId, $childStyle, 1, $actor);
    }

    /**
     * The in-memory catalogue repository records a link's style code as the style public id
     * (and the resolver upper-cases codes), so the style code is set to the upper-cased id.
     */
    private function createStyle(string $code, ?string $parentPublicId = null): string
    {
        $actor = UserId::fromInt(1);
        $created = $this->styles->create([
            'styleCode' => $code,
            'displayName' => $code,
            'parentPublicId' => $parentPublicId,
            'sortOrder' => 0,
            'isActive' => true,
            'inheritance' => [],
        ], $actor);
        $this->styles->update($created->publicId, ['styleCode' => strtoupper($created->publicId)], $created->rowVersion, $actor);

        return $created->publicId;
    }

    private function seedRegions(): void
    {
        foreach (['au' => 'AU', 'nz' => 'NZ', 'us' => 'US', 'as' => 'AS'] as $id => $code) {
            $this->store->addRegion([
                'publicId' => 'region-' . $id,
                'code' => $code,
                'name' => $code,
                'displayOrder' => 1,
                'isActive' => true,
            ]);
        }
    }

    /** @param list<string> $regionPublicIds */
    private function seedProduct(
        string $publicId,
        string $name,
        array $regionPublicIds,
        ?ProductStatusCode $status = null,
        ?DateTimeImmutable $publishAtUtc = null,
    ): void {
        $productId = $this->store->allocateProductId();
        $product = new Product(
            id: $productId,
            publicId: ProductPublicId::fromString($publicId),
            productName: $name,
            status: $status ?? ProductStatusCode::published(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
            rowVersion: RowVersionToken::fromBase64('AAAAAAAAAAA='),
            publishAtUtc: $publishAtUtc,
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
}

/**
 * @internal
 */
final class CountingProductAdminQueryRepository implements ProductAdminQueryRepository
{
    public int $singleDetailCalls = 0;

    public int $bulkDetailCalls = 0;

    public function __construct(private readonly ProductAdminQueryRepository $inner)
    {
    }

    public function reset(): void
    {
        $this->singleDetailCalls = 0;
        $this->bulkDetailCalls = 0;
    }

    public function list(ProductListCriteria $criteria): ProductListResult
    {
        return $this->inner->list($criteria);
    }

    public function findDetailByPublicId(ProductPublicId $publicId): ?ProductAdminDetail
    {
        ++$this->singleDetailCalls;

        return $this->inner->findDetailByPublicId($publicId);
    }

    public function findDetailsByPublicIds(array $publicIds): array
    {
        ++$this->bulkDetailCalls;

        return $this->inner->findDetailsByPublicIds($publicIds);
    }

    public function listVariantSummariesByProduct(
        ProductPublicId $productPublicId,
        bool $includeArchived = true,
        bool $includeDeleted = false,
    ): array {
        return $this->inner->listVariantSummariesByProduct($productPublicId, $includeArchived, $includeDeleted);
    }

    public function findVariantSummaryByPublicId(
        ProductVariantPublicId $publicId,
        bool $includeDeleted = false,
    ): ?ProductVariantSummary {
        return $this->inner->findVariantSummaryByPublicId($publicId, $includeDeleted);
    }
}
