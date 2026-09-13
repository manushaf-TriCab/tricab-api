<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Product\Persistence\AzureSqlProductAdminQueryRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductPricingRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductPublicQueryRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductSearchRepository;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class ProductPersistenceTest extends TestCase
{
    private const SAMPLE_PRODUCT_PUBLIC_ID = '550e8400-e29b-41d4-a716-446655440000';
    private const SAMPLE_ROW_VERSION = 'AAAAAAAAAAA=';

    public function test_product_insert_sql_uses_output_inserted_and_parameterized_values(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['ProductStatusId' => 1]]);
        $connection->queueFetch([['ProductLifecycleStatusId' => 2]]);
        $connection->queueFetch([['PublicId' => self::SAMPLE_PRODUCT_PUBLIC_ID]]);
        $connection->queueFetch([$this->productAggregateRow()]);

        $repository = new AzureSqlProductRepository($connection);
        $saved = $repository->save(
            $this->unsavedProduct(),
            UserId::fromInt(1),
        );

        self::assertSame(self::SAMPLE_PRODUCT_PUBLIC_ID, $saved->publicId()->toString());
        self::assertTrue($connection->committed);

        $insertCall = $connection->calls[2];
        self::assertStringContainsString('INSERT INTO [product].[Products]', $insertCall['sql']);
        self::assertStringContainsString('OUTPUT', $insertCall['sql']);
        self::assertStringContainsString('INSERTED.[PublicId]', $insertCall['sql']);
        self::assertStringNotContainsString('Widget Pro', $insertCall['sql']);
        self::assertSame('Widget Pro', $insertCall['params'][':productName']);
    }

    public function test_product_update_includes_row_version_in_where_clause(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['ProductStatusId' => 1]]);
        $connection->queueFetch([['ProductLifecycleStatusId' => 2]]);
        $connection->queueFetch([['PublicId' => self::SAMPLE_PRODUCT_PUBLIC_ID]]);
        $connection->queueFetch([$this->productAggregateRow()]);

        $repository = new AzureSqlProductRepository($connection);
        $expected = RowVersionToken::fromBase64(self::SAMPLE_ROW_VERSION);

        $repository->save(
            $this->savedProduct(),
            UserId::fromInt(1),
            $expected,
        );

        $updateCall = $connection->calls[2];
        self::assertStringContainsString('[RowVersion] = :expectedRowVersion', $updateCall['sql']);
        self::assertInstanceOf(SqlBinary::class, $updateCall['params'][':expectedRowVersion']);
    }

    public function test_product_update_row_version_conflict_when_update_returns_empty(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['ProductStatusId' => 1]]);
        $connection->queueFetch([['ProductLifecycleStatusId' => 2]]);
        $connection->queueFetch([]);
        $connection->queueFetch([['ProductId' => 42]]);

        $repository = new AzureSqlProductRepository($connection);

        $this->expectException(ProductConcurrencyException::class);

        $repository->save(
            $this->savedProduct(),
            UserId::fromInt(1),
            RowVersionToken::fromBase64(self::SAMPLE_ROW_VERSION),
        );
    }

    public function test_admin_list_builds_allowlisted_order_by_without_raw_user_sort(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 1]]);
        $connection->queueFetch([$this->adminListRow()]);

        $repository = new AzureSqlProductAdminQueryRepository($connection);
        $criteria = new ProductListCriteria(
            page: 1,
            pageSize: 10,
            search: "O'Brien",
            sortBy: ProductListCriteria::SORT_NAME,
            sortDirection: ProductListCriteria::SORT_ASC,
        );

        $result = $repository->list($criteria);

        self::assertSame(1, $result->pagination->totalItems);
        self::assertCount(1, $result->items);

        $listSql = $connection->calls[1]['sql'];
        $listParams = $connection->calls[1]['params'];

        self::assertStringContainsString('ORDER BY p.[ProductName] ASC', $listSql);
        self::assertStringNotContainsString("O'Brien", $listSql);
        self::assertSame("%O'Brien%", $listParams[':searchName']);
        self::assertSame(0, $listParams[':offset']);
        self::assertSame(10, $listParams[':pageSize']);
    }

    public function test_public_find_by_public_id_sql_contains_published_deleted_and_active_filters(): void
    {
        $connection = new FakeSqlConnection([$this->publicDetailRow()]);

        $repository = new AzureSqlProductPublicQueryRepository(
            $connection,
            $this->createStub(Product3DModelRepository::class),
            product3dEnabled: false,
        );
        $detail = $repository->findByPublicId(
            ProductPublicId::fromString(self::SAMPLE_PRODUCT_PUBLIC_ID),
        );

        self::assertNotNull($detail);
        self::assertSame('widget-pro', $detail->slug);

        $sql = $connection->calls[0]['sql'];
        self::assertStringContainsString("ps.[StatusCode] = N'published'", $sql);
        self::assertStringContainsString('p.[DeletedAtUtc] IS NULL', $sql);
        self::assertStringContainsString('p.[IsActive] = 1', $sql);
    }

    public function test_pricing_upsert_binds_decimal_values_as_strings(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['CurrencyId' => 1]]);
        $connection->queueFetch([['ProductId' => 42]]);
        $connection->queueFetch([]);
        $connection->queueFetch([['PublicId' => '880e8400-e29b-41d4-a716-446655440999']]);
        $connection->queueFetch([[
            'PublicId' => '880e8400-e29b-41d4-a716-446655440999',
            'CurrencyCode' => 'AUD',
            'BaseCost' => '19.9900',
            'MinimumQuantity' => '1.0000',
            'PriceListCode' => null,
            'EffectiveFromUtc' => null,
            'EffectiveToUtc' => null,
        ]]);

        $repository = new AzureSqlProductPricingRepository($connection);
        $summary = $repository->upsert(
            ProductPublicId::fromString(self::SAMPLE_PRODUCT_PUBLIC_ID),
            [
                'currencyCode' => 'AUD',
                'baseCost' => '19.99',
                'minimumQuantity' => '1.0000',
                'discountPercent' => null,
                'taxInclusive' => false,
                'effectiveFromUtc' => null,
                'effectiveToUtc' => null,
            ],
            UserId::fromInt(1),
        );

        self::assertSame('19.9900', $summary->baseCost);
        self::assertTrue($connection->committed);

        $insertCall = $connection->calls[3];
        self::assertStringContainsString('INSERT INTO [product].[ProductPrices]', $insertCall['sql']);
        self::assertSame('19.99', $insertCall['params'][':baseCost']);
        self::assertSame('1.0000', $insertCall['params'][':minimumQuantity']);
        self::assertIsString($insertCall['params'][':baseCost']);
    }

    public function test_search_suggest_does_not_use_unbound_top_parameter(): void
    {
        $connection = new FakeSqlConnection([
            ['suggestion' => 'Widget Pro'],
        ]);

        $repository = new AzureSqlProductSearchRepository($connection);
        $suggestions = $repository->suggest('Widget', 5);

        self::assertSame(['Widget Pro'], $suggestions);

        $sql = $connection->calls[0]['sql'];
        $params = $connection->calls[0]['params'];

        self::assertStringContainsString('SELECT TOP 25 suggestion', $sql);
        self::assertStringNotContainsString('TOP :', $sql);
        self::assertArrayNotHasKey(':limit', $params);
        self::assertArrayNotHasKey(':top', $params);
    }

    public function test_search_can_be_restricted_to_authorized_product_statuses(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([['Total' => 0]]);
        $connection->queueFetch([]);

        $repository = new AzureSqlProductSearchRepository($connection);
        $repository->search(new ProductSearchCriteria(
            query: 'VFD',
            page: 1,
            pageSize: 5,
            publishedOnly: false,
            statusCodes: ['published', 'draft'],
        ));

        self::assertCount(2, $connection->calls);
        foreach ($connection->calls as $call) {
            self::assertStringContainsString(
                'LOWER(ps.[StatusCode]) IN (:allowedStatus0, :allowedStatus1)',
                $call['sql'],
            );
            self::assertSame('published', $call['params'][':allowedStatus0']);
            self::assertSame('draft', $call['params'][':allowedStatus1']);
        }
    }

    private function unsavedProduct(): Product
    {
        return new Product(
            id: null,
            publicId: ProductPublicId::fromString(self::SAMPLE_PRODUCT_PUBLIC_ID),
            productName: 'Widget Pro',
            status: ProductStatusCode::draft(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
        );
    }

    private function savedProduct(): Product
    {
        return new Product(
            id: \TriCab\Core\Product\Domain\ValueObject\ProductId::fromInt(42),
            publicId: ProductPublicId::fromString(self::SAMPLE_PRODUCT_PUBLIC_ID),
            productName: 'Widget Pro Updated',
            status: ProductStatusCode::draft(),
            lifecycleStatus: ProductLifecycleStatusCode::active(),
            rowVersion: RowVersionToken::fromBase64(self::SAMPLE_ROW_VERSION),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productAggregateRow(): array
    {
        return [
            'ProductId' => 42,
            'PublicId' => self::SAMPLE_PRODUCT_PUBLIC_ID,
            'ProductFamilyId' => null,
            'ProductCategoryId' => null,
            'ProductSeriesId' => null,
            'StatusCode' => 'draft',
            'LifecycleStatusCode' => 'active',
            'ProductName' => 'Widget Pro',
            'InternalName' => null,
            'InternalCode' => null,
            'ShortDescription' => null,
            'FullDescription' => null,
            'LegacyProductId' => null,
            'ErpReference' => null,
            'IsFeatured' => 0,
            'PublishAtUtc' => null,
            'UnpublishAtUtc' => null,
            'DiscontinueAtUtc' => null,
            'IsActive' => 1,
            'DeletedAtUtc' => null,
            'DeletedByUserId' => null,
            'RowVersion' => base64_decode(self::SAMPLE_ROW_VERSION, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminListRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_PRODUCT_PUBLIC_ID,
            'ProductName' => 'Widget Pro',
            'InternalCode' => 'WP-001',
            'PrimaryProductCode' => 'WP-001',
            'ThumbnailUrl' => null,
            'RegionCodesCsv' => 'AU',
            'FamilyName' => 'Widgets',
            'CategoryName' => 'Industrial',
            'SeriesName' => 'Pro',
            'VariantCount' => 1,
            'StatusCode' => 'draft',
            'LifecycleStatusCode' => 'active',
            'UpdatedAtUtc' => '2026-01-01T00:00:00.0000000',
            'UpdatedByDisplayName' => 'Admin',
            'IsArchived' => 0,
            'RowVersion' => base64_decode(self::SAMPLE_ROW_VERSION, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicDetailRow(): array
    {
        return [
            'PublicId' => self::SAMPLE_PRODUCT_PUBLIC_ID,
            'ProductName' => 'Widget Pro',
            'ShortDescription' => 'Short',
            'FullDescription' => 'Full',
            'FamilyName' => 'Widgets',
            'CategoryName' => 'Industrial',
            'SeriesName' => 'Pro',
            'Slug' => 'widget-pro',
        ];
    }
}
