<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Payload;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryMigrationBatchRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Product\Migration\Application\Command\CreateMigrationBatchCommand;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationService;
use TriCab\Core\Product\Migration\Identity\EmptyMigrationLookupIndex;
use TriCab\Core\Product\Migration\Identity\ProductIdentityMatcher;
use TriCab\Core\Product\Migration\Identity\ProductVariantClassifier;
use TriCab\Core\Product\Migration\Mapping\OptionAliasResolver;
use TriCab\Core\Product\Migration\Source\FixtureLegacyProductSource;
use TriCab\Core\Product\Migration\Transform\SpecificationValueTransformer;
use TriCab\Api\Shared\Persistence\NoOpApplicationTransaction;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;

final class ProductMigrationEndpointsTest extends TestCase
{
    public function test_batch_create_extract_normalize_validate_dry_run(): void
    {
        $app = TestAppFactory::create([]);

        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-migration/batches', [
            'name' => 'Pilot FT',
            'sourceSystem' => 'combined',
            'dryRun' => true,
            'sourceFilter' => ['styleCode' => 'FT'],
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $batch = TestAppFactory::json($create)['data'];
        self::assertTrue($batch['dryRun']);
        self::assertSame('draft', $batch['statusCode']);
        $id = $batch['publicId'];

        foreach (['extract', 'normalize', 'validate'] as $action) {
            $response = $app->handle($this->jsonRequest('POST', "/api/v1/admin/product-migration/batches/{$id}/{$action}", []));
            self::assertSame(200, $response->getStatusCode(), $action . ' ' . (string) $response->getBody());
        }

        $dry = $app->handle($this->jsonRequest('POST', "/api/v1/admin/product-migration/batches/{$id}/dry-run", []));
        self::assertSame(200, $dry->getStatusCode(), (string) $dry->getBody());
        $report = TestAppFactory::json($dry)['data'];
        self::assertTrue($report['dryRunNoWriteGuarantee']);
        self::assertFalse($report['wouldWriteProducts']);
        self::assertFalse($report['wouldWriteBlobs']);
        self::assertArrayNotHasKey('migrationBatchId', $report);

        $issues = $app->handle(TestAppFactory::request('GET', "/api/v1/admin/product-migration/batches/{$id}/issues"));
        self::assertSame(200, $issues->getStatusCode());

        $recon = $app->handle(TestAppFactory::request('GET', "/api/v1/admin/product-migration/batches/{$id}/reconciliation?refresh=1"));
        self::assertSame(200, $recon->getStatusCode(), (string) $recon->getBody());
        $reconData = TestAppFactory::json($recon)['data'];
        self::assertSame('draft', $reconData['publicationDefault']);
    }


    public function test_readiness_endpoint(): void
    {
        $app = TestAppFactory::create([]);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-migration/readiness'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = TestAppFactory::json($response)['data'];
        self::assertArrayHasKey('overallStatus', $data);
        self::assertArrayHasKey('gates', $data);
        self::assertFalse($data['allowsFullCatalogueMigration']);
        self::assertArrayNotHasKey('wordpressPassword', $data['sourceConfig'] ?? []);
        self::assertArrayNotHasKey('tcPassword', $data['sourceConfig'] ?? []);
    }

    public function test_catalogue_navigation_dry_run_is_read_only(): void
    {
        $app = TestAppFactory::create([]);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-migration/catalogue-navigation-dry-run'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = MigrationPayload::assoc(TestAppFactory::json($response)['data'] ?? []);
        $payload = MigrationPayload::assoc($data['payload'] ?? []);
        $safety = MigrationPayload::assoc($payload['safety'] ?? []);
        self::assertSame(0, MigrationPayload::int($data['wordpressWrites'] ?? $safety['wordpressWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['tcWrites'] ?? $safety['tcWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['catalogueTabWrites'] ?? $safety['catalogueTabWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['catalogueNodeWrites'] ?? $safety['catalogueNodeWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['productNodeWrites'] ?? $safety['productNodeWrites'] ?? 1));
        self::assertFalse(MigrationPayload::bool($data['applied'] ?? true, true));
        self::assertStringContainsString(
            'ADDITIVE ONLY',
            MigrationPayload::string($data['banner'] ?? $payload['banner'] ?? ''),
        );
    }


    public function test_f12_live_sku_pilot_endpoint_is_read_only(): void
    {
        $app = TestAppFactory::create([]);
        $response = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-migration/pilot-f12-live-sku'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = MigrationPayload::assoc(TestAppFactory::json($response)['data'] ?? []);
        $payload = MigrationPayload::assoc($data['payload'] ?? []);
        self::assertFalse(MigrationPayload::bool($data['applied'] ?? $payload['applied'] ?? true, true));
        self::assertSame(0, MigrationPayload::int($data['wordpressWrites'] ?? $payload['safety']['wordpressWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['tcWrites'] ?? $payload['safety']['tcWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['targetWrites'] ?? $payload['safety']['targetWrites'] ?? $payload['mutationPlan']['actualTargetWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['blobWrites'] ?? $payload['safety']['blobWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['pricingWrites'] ?? $payload['safety']['pricingWrites'] ?? 1));
        $banner = MigrationPayload::string($data['banner'] ?? $payload['banner'] ?? '');
        self::assertTrue(
            str_contains($banner, 'F12') || str_contains(MigrationPayload::string($data['phase'] ?? $payload['phase'] ?? ''), 'live-sku'),
            $banner,
        );
    }

    public function test_manage_requires_migration_permission(): void
    {
        $app = TestAppFactory::create([]);
        $this->replacePermissions($app, ['account.login', 'PRODUCT.READ']);
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-migration/batches', [
            'name' => 'Denied',
            'sourceSystem' => 'combined',
        ]));
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_service_idempotency_and_draft_and_rollback_protection(): void
    {
        $batches = new InMemoryMigrationBatchRepository();
        $legacy = TestAppFactory::create([])->getContainer()->get(LegacyProductMappingRepository::class);
        $service = new ProductMigrationService(
            batches: $batches,
            identityMatcher: new ProductIdentityMatcher($legacy, new EmptyMigrationLookupIndex()),
            variantClassifier: new ProductVariantClassifier(),
            transformer: new SpecificationValueTransformer(),
            optionAliases: new OptionAliasResolver(),
            transaction: new NoOpApplicationTransaction(),
            source: FixtureLegacyProductSource::pilotSample(),
        );
        $actor = new ActorContext(userId: 1, publicId: '00000000-0000-0000-0000-000000000001', emailAddress: 'test@tricab.com', correlationId: 'mig-test');
        $batch = $service->createBatch($actor, new CreateMigrationBatchCommand('Exec', 'combined', dryRun: false, sourceFilter: ['styleCode' => 'FT']));
        $service->extract($actor, $batch->publicId);
        $service->normalize($actor, $batch->publicId);
        $service->validate($actor, $batch->publicId);
        $first = $service->execute($actor, $batch->publicId);
        self::assertContains($first->statusCode, ['completed', 'completed-with-warnings']);
        self::assertGreaterThan(0, $first->importedRecords);
        foreach ($batches->importedProductsFor($batch->publicId) as $row) {
            self::assertSame('draft', $row['status']);
        }
        $again = $batches->executeImport($batch->publicId, false);
        self::assertGreaterThan(0, $again['skipped']);
        self::assertSame(0, $again['imported']);

        $imported = $batches->importedProductsFor($batch->publicId);
        $batches->markManuallyEdited((string) $imported[0]['targetPublicId']);
        $rollback = $service->rollback($actor, $batch->publicId, false);
        self::assertGreaterThan(0, $rollback['protected']);
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param App<ContainerInterface> $app
     * @param list<string> $permissionCodes
     */
    private function replacePermissions(App $app, array $permissionCodes): void
    {
        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $repository = $container->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $repository);
        $repository->replacePermissions(1, $permissionCodes, ['EMPLOYEE']);
    }
}
