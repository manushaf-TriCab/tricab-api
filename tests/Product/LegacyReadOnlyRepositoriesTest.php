<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Migration\MysqlLegacyWordPressRepository;
use TriCab\Api\Product\Migration\RecordingLegacyReadOnlyConnection;
use TriCab\Api\Product\Persistence\InMemory\InMemoryMigrationBatchRepository;
use TriCab\Api\Shared\Persistence\NoOpApplicationTransaction;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;
use TriCab\Core\Product\Migration\Application\Command\CreateMigrationBatchCommand;
use TriCab\Core\Product\Migration\Application\Exception\InvalidMigrationBatchState;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationService;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Identity\EmptyMigrationLookupIndex;
use TriCab\Core\Product\Migration\Identity\ProductIdentityMatcher;
use TriCab\Core\Product\Migration\Identity\ProductVariantClassifier;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Mapping\OptionAliasResolver;
use TriCab\Core\Product\Migration\Source\LiveCombinedLegacyProductSource;
use TriCab\Core\Product\Migration\Transform\SpecificationValueTransformer;

final class LegacyReadOnlyRepositoriesTest extends TestCase
{
    public function test_wordpress_repository_only_issues_select_show_describe(): void
    {
        $guard = new LegacyReadOnlySqlGuard();
        $logger = new InMemoryLegacySqlStatementLogger();
        $conn = new RecordingLegacyReadOnlyConnection($guard, $logger, [
            'SELECT' => [
                [
                    'ID' => 3812,
                    'post_title' => 'FLEXI-TUFF',
                    'post_name' => 'flexi-tuff',
                    'post_status' => 'publish',
                    'post_type' => 'cable',
                    'post_content' => 'desc',
                    'style_code' => 'FT',
                    'small_desc' => 'short',
                ],
            ],
            'SHOW TABLES' => [['Tables_in_db' => 'wp_posts'], ['Tables_in_db' => 'wp_postmeta']],
            'DESCRIBE' => [['Field' => 'ID'], ['Field' => 'post_title']],
            'COUNT' => [['cnt' => 1]],
        ]);
        $repo = new MysqlLegacyWordPressRepository($conn, 'wp_');
        $products = $repo->findProducts(['styleCode' => 'FT', 'maxRecords' => 10]);
        self::assertCount(1, $products);
        self::assertSame('FT', $products[0]['style_code']);
        $schema = $repo->inspectSchema();
        self::assertContains('wp_posts', $schema['tables']);
        foreach ($logger->entries() as $entry) {
            self::assertTrue($guard->isAllowed($entry->sql), $entry->sql);
            self::assertSame('MysqlLegacyWordPressRepository', $entry->repository);
            self::assertGreaterThanOrEqual(0, $entry->rowsReturned);
        }
    }

    public function test_guard_blocks_write_through_recording_connection(): void
    {
        $guard = new LegacyReadOnlySqlGuard();
        $logger = new InMemoryLegacySqlStatementLogger();
        $conn = new RecordingLegacyReadOnlyConnection($guard, $logger);
        $this->expectException(LegacyDatabaseWriteAttemptException::class);
        $conn->query('UPDATE wp_posts SET post_title = \'x\'', [], 'test', 'bad');
    }

    public function test_live_source_dry_run_populates_staging_without_product_writes(): void
    {
        $wp = new class implements LegacyWordPressRepository {
            public function findProducts(array $filter = []): array
            {
                return [[
                    'ID' => 3812,
                    'post_title' => 'FLEXI-TUFF',
                    'post_name' => 'flexi-tuff',
                    'post_status' => 'publish',
                    'post_type' => 'cable',
                    'post_content' => 'Full',
                    'style_code' => 'FT',
                    'small_desc' => 'short',
                ]];
            }

            public function findAttachments(array $postIds): array
            {
                return [];
            }

            public function findCategories(array $postIds): array
            {
                return [];
            }

            public function findSpecifications(array $postIds): array
            {
                return [];
            }

            public function findRelationships(array $postIds): array
            {
                return [];
            }

            public function inspectSchema(): array
            {
                return ['tables' => ['wp_posts'], 'columns' => [], 'productCounts' => ['cable' => 1]];
            }
        };
        $tc = new class implements LegacyTcRepository {
            public function findVariants(array $filter = []): array
            {
                return [[
                    'ida' => 'FT-2X1.5BK',
                    'style' => 'FT',
                    'style_code' => 'FT',
                    'od' => '25.4',
                ]];
            }

            public function findSpecifications(array $filter = []): array
            {
                return [];
            }

            public function findCodes(array $filter = []): array
            {
                return [];
            }

            public function findAccessories(array $filter = []): array
            {
                return [];
            }

            public function findDescriptions(array $filter = []): array
            {
                return [];
            }

            public function inspectSchema(): array
            {
                return ['tables' => ['tpro'], 'columns' => [], 'rowCounts' => ['tpro' => 1]];
            }
        };

        $source = new LiveCombinedLegacyProductSource($wp, $tc);
        $batches = new InMemoryMigrationBatchRepository();
        $legacy = TestAppFactory::create([])->getContainer()->get(LegacyProductMappingRepository::class);
        $service = new ProductMigrationService(
            batches: $batches,
            identityMatcher: new ProductIdentityMatcher($legacy, new EmptyMigrationLookupIndex()),
            variantClassifier: new ProductVariantClassifier(),
            transformer: new SpecificationValueTransformer(),
            optionAliases: new OptionAliasResolver(),
            transaction: new NoOpApplicationTransaction(),
            source: $source,
        );
        $actor = new ActorContext(1, '00000000-0000-0000-0000-000000000001', 'test@tricab.com', 'live-ro');
        $batch = $service->createBatch($actor, new CreateMigrationBatchCommand(
            name: 'Live RO FT',
            sourceSystem: 'combined',
            dryRun: false,
            sourceFilter: ['styleCode' => 'FT', 'maxRecords' => 20],
        ));
        self::assertTrue($batch->dryRun, 'live source must force dryRun=true');
        $service->extract($actor, $batch->publicId);
        $service->normalize($actor, $batch->publicId);
        $service->validate($actor, $batch->publicId);
        $report = $service->dryRun($actor, $batch->publicId);
        self::assertFalse($report->wouldWriteProducts);
        self::assertFalse($report->wouldWriteBlobs);
        self::assertGreaterThan(0, $report->counts['productsDiscovered'] ?? 0);
        $recon = $service->reconcile($actor, $batch->publicId);
        self::assertSame('draft', $recon['publicationDefault']);
        $this->expectException(InvalidMigrationBatchState::class);
        $service->execute($actor, $batch->publicId);
    }
}
