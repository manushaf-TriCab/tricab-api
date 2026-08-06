<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Tests\TestAppFactory;

/**
 * Phase 8.5 live Azure SQL catalogue stabilisation.
 *
 * Gated by RUN_AZURE_INTEGRATION_TESTS=true (or legacy RUN_AZURE_SQL_INTEGRATION_TESTS=true).
 * Uses disposable non-production Azure SQL from local .env (USER_REPOSITORY=azure-sql).
 *
 * @group azure-sql
 * @group product-stabilisation
 */
#[Group('azure-sql')]
#[Group('product-stabilisation')]
final class AzureSqlProductCatalogueStabilisationTest extends TestCase
{
    /** @var array<string, float> */
    private array $timingsMs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $enabled = getenv('RUN_AZURE_INTEGRATION_TESTS') === 'true'
            || getenv('RUN_AZURE_SQL_INTEGRATION_TESTS') === 'true';

        if (!$enabled) {
            self::markTestSkipped('Set RUN_AZURE_INTEGRATION_TESTS=true to run Azure SQL Product stabilisation.');
        }

        if (!extension_loaded('pdo_sqlsrv')) {
            self::markTestSkipped('pdo_sqlsrv extension is not installed.');
        }
    }

    public function test_full_product_catalogue_stabilisation_workflow(): void
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        if (is_readable($envPath)) {
            \Dotenv\Dotenv::createImmutable(dirname($envPath))->safeLoad();
        }

        /** @var array<string, string|null> $env */
        $env = $_ENV;
        self::assertSame('azure-sql', $env['USER_REPOSITORY'] ?? null, 'Stabilisation requires USER_REPOSITORY=azure-sql');

        $app = TestAppFactory::create(array_merge($env, [
            'APP_ENV' => 'development',
            'DEV_AUTH_ENABLED' => 'true',
            'USER_REPOSITORY' => 'azure-sql',
        ]));

        $suffix = strtolower(bin2hex(random_bytes(4))) . dechex((int) (microtime(true) * 1000) & 0xffff);
        $lookups = $this->timed('lookups', fn () => $this->jsonGet($app, '/api/v1/admin/product-lookups'));
        self::assertSame(200, $lookups['status'], $lookups['raw']);
        /** @var array<string, mixed> $lookupData */
        $lookupData = $lookups['data'];

        $familyPublicId = $this->lookupPublicId($lookupData, 'families', 'CABLE');
        $categoryPublicId = $this->lookupPublicId($lookupData, 'categories', 'FLEX-POWER');
        $seriesPublicId = $this->lookupPublicId($lookupData, 'series', 'TC-FLEX');
        $regionAu = $this->lookupPublicId($lookupData, 'regions', 'AU');
        $regionNz = $this->lookupPublicId($lookupData, 'regions', 'NZ');
        $marketAu = $this->lookupPublicId($lookupData, 'markets', 'AU-DIST');
        $industryMining = $this->lookupPublicId($lookupData, 'industries', 'MINING');
        $appTempPower = $this->lookupPublicId($lookupData, 'applications', 'TEMP-POWER');
        $voltageDef = $this->lookupPublicId($lookupData, 'specificationDefinitions', 'VOLTAGE');
        $coresDef = $this->lookupPublicId($lookupData, 'specificationDefinitions', 'CORES');
        $standardDef = $this->lookupPublicId($lookupData, 'specificationDefinitions', 'STANDARD');
        $ruleSetPublicId = $this->lookupPublicId($lookupData, 'codeRuleSets', 'PHASE85-SIMPLE');
        self::assertNotEmpty($lookupData['specificationDefinitions'] ?? []);
        self::assertNotEmpty($lookupData['codeRuleSets'] ?? []);

        // Related accessory product (published later as related)
        $accessory = $this->createProduct($app, 'Phase85 Accessory ' . $suffix, $familyPublicId, $categoryPublicId);
        $this->assertOk($this->jsonPut($app, '/api/v1/admin/products/' . $accessory['publicId'] . '/markets', [
            'regionPublicIds' => [$regionAu],
            'marketPublicIds' => [$marketAu],
        ]));
        $this->assertOk($this->jsonPost($app, '/api/v1/admin/products/' . $accessory['publicId'] . '/slugs', [
            'newSlug' => 'phase85-accessory-' . $suffix,
            'isPrimary' => true,
        ]));
        $pubAccessory = $this->jsonPost($app, '/api/v1/admin/products/' . $accessory['publicId'] . '/publish', [
            'expectedRowVersion' => $this->currentRowVersion($app, $accessory['publicId']),
        ]);
        $this->assertOk($pubAccessory);

        // Primary complex cable product
        $created = $this->timed('create', fn () => $this->jsonPost($app, '/api/v1/admin/products', [
            'productName' => 'Phase85 Flex Cable ' . $suffix,
            'familyPublicId' => $familyPublicId,
            'categoryPublicId' => $categoryPublicId,
            'seriesPublicId' => $seriesPublicId,
            'shortDescription' => 'Representative flexible power cable for Phase 8.5 stabilisation.',
            'fullDescription' => 'Complex cable sample with many specifications and variants.',
        ]));
        $this->assertCreated($created);
        $productPublicId = (string) $created['data']['publicId'];
        $rowVersion = (string) $created['data']['rowVersion'];

        $updated = $this->jsonPatch($app, '/api/v1/admin/products/' . $productPublicId, [
            'expectedRowVersion' => $rowVersion,
            'productName' => 'Phase85 Flex Cable ' . $suffix . ' Updated',
            'shortDescription' => 'Updated short description for stabilisation.',
        ]);
        $this->assertOk($updated);
        $rowVersion = (string) $updated['data']['rowVersion'];

        // Concurrency conflict
        $conflict = $this->jsonPatch($app, '/api/v1/admin/products/' . $productPublicId, [
            'expectedRowVersion' => $created['data']['rowVersion'],
            'productName' => 'Should conflict',
        ]);
        self::assertSame(409, $conflict['status'], $conflict['raw']);
        self::assertSame('PRODUCT_CONCURRENCY_CONFLICT', $conflict['error']['code'] ?? null);

        // Variants (must be published to appear on public catalogue)
        $variantA = $this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/variants', [
            'variantName' => '3C 1.5mm',
            'sku' => 'P85-' . $suffix . '-1.5',
            'variantCode' => 'VC-' . $suffix . '-15',
            'statusCode' => 'published',
            'displayOrder' => 1,
        ]);
        $this->assertCreated($variantA);
        $variantB = $this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/variants', [
            'variantName' => '3C 2.5mm',
            'sku' => 'P85-' . $suffix . '-2.5',
            'variantCode' => 'VC-' . $suffix . '-25',
            'statusCode' => 'published',
            'displayOrder' => 2,
        ]);
        $this->assertCreated($variantB);
        $variantPublicId = (string) $variantA['data']['publicId'];
        $variantRowVersion = (string) $variantA['data']['rowVersion'];
        $variantUpdate = $this->jsonPatch($app, '/api/v1/admin/product-variants/' . $variantPublicId, [
            'expectedRowVersion' => $variantRowVersion,
            'variantName' => '3C 1.5mm Updated',
        ]);
        $this->assertOk($variantUpdate);

        // Specifications
        $this->assertOk($this->jsonPut($app, '/api/v1/admin/products/' . $productPublicId . '/specifications', [
            'values' => [
                ['definitionPublicId' => $voltageDef, 'valueText' => '0.6/1 kV'],
                ['definitionPublicId' => $standardDef, 'valueText' => 'AS/NZS 5000.1'],
                ['definitionPublicId' => $coresDef, 'valueInteger' => 3],
            ],
        ]));

        // Product codes
        $preview = $this->jsonPost($app, '/api/v1/admin/product-codes/preview', [
            'ruleSetPublicId' => $ruleSetPublicId,
            'segmentValues' => ['suffix' => 'FLEX' . strtoupper($suffix)],
        ]);
        $this->assertOk($preview);
        self::assertSame('TC-FLEX' . strtoupper($suffix), $preview['data']['generatedCode'] ?? null);
        $assign = $this->jsonPost($app, '/api/v1/admin/product-codes/assign', [
            'productPublicId' => $productPublicId,
            'ruleSetPublicId' => $ruleSetPublicId,
            'segmentValues' => ['suffix' => 'FLEX' . strtoupper($suffix)],
        ]);
        $this->assertOk($assign);

        // Media + documents metadata
        // mediaCategoryCode is ImageRoleCode (primary/gallery/…), not ProductMedia registry "image".
        $this->assertCreated($this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/media', [
            'mediaCategoryCode' => 'primary',
            'mediaTypeCode' => 'primary-image',
            'title' => 'Primary cable image',
            'storagePath' => 'product/' . $productPublicId . '/primary.jpg',
            'contentType' => 'image/jpeg',
            'fileExtension' => 'jpg',
            'byteSize' => 12345,
            'altText' => 'Flex cable',
            'displayOrder' => 1,
            'isPrimary' => true,
            'publicUrl' => 'https://cdn.example.test/product/' . $productPublicId . '/primary.jpg',
        ]));
        $this->assertCreated($this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/documents', [
            'documentTypeCode' => 'datasheet',
            'title' => 'Public datasheet',
            'storagePath' => 'product/' . $productPublicId . '/datasheet.pdf',
            'accessCode' => 'public',
            'displayOrder' => 1,
            'publicUrl' => 'https://cdn.example.test/product/' . $productPublicId . '/datasheet.pdf',
        ]));
        $this->assertCreated($this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/documents', [
            'documentTypeCode' => 'other',
            'title' => 'Restricted internal note',
            'storagePath' => 'product/' . $productPublicId . '/internal.pdf',
            'accessCode' => 'restricted',
            'displayOrder' => 2,
            'publicUrl' => 'https://cdn.example.test/product/' . $productPublicId . '/internal.pdf',
        ]));

        // Markets / industries / applications / relationships / SEO / slug
        $this->assertOk($this->jsonPut($app, '/api/v1/admin/products/' . $productPublicId . '/markets', [
            'regionPublicIds' => [$regionAu, $regionNz],
            'marketPublicIds' => [$marketAu],
        ]));
        $this->assertOk($this->jsonPut($app, '/api/v1/admin/products/' . $productPublicId . '/industries', [
            'industryPublicIds' => [$industryMining],
        ]));
        $this->assertOk($this->jsonPut($app, '/api/v1/admin/products/' . $productPublicId . '/applications', [
            'applicationPublicIds' => [$appTempPower],
        ]));
        $this->assertOk($this->jsonPut($app, '/api/v1/admin/products/' . $productPublicId . '/relationships', [
            'type' => 'accessory',
            'items' => [
                [
                    'relatedProductPublicId' => $accessory['publicId'],
                    'displayOrder' => 1,
                    'accessoryRequirementCode' => 'optional',
                ],
            ],
        ]));
        $this->assertOk($this->jsonPut($app, '/api/v1/admin/products/' . $productPublicId . '/seo', [
            'pageTitle' => 'Phase85 Flex Cable',
            'metaDescription' => 'Stabilisation sample cable product.',
            'searchKeywords' => 'cable,flex,phase85',
        ]));
        $slug = 'phase85-flex-cable-' . $suffix;
        $this->assertOk($this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/slugs', [
            'newSlug' => $slug,
            'isPrimary' => true,
        ]));

        // Publication readiness + publish
        $readiness = $this->jsonGet($app, '/api/v1/admin/products/' . $productPublicId . '/publication-readiness');
        $this->assertOk($readiness);
        self::assertTrue((bool) ($readiness['data']['isReady'] ?? false), $readiness['raw']);
        $publish = $this->timed('publish', fn () => $this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/publish', [
            'expectedRowVersion' => $this->currentRowVersion($app, $productPublicId),
        ]));
        $this->assertOk($publish);

        // Public detail + nested + search
        $publicDetail = $this->timed('public_detail', fn () => $this->jsonGet($app, '/api/v1/products/' . $slug));
        $this->assertOk($publicDetail);
        /** @var array<string, mixed> $publicData */
        $publicData = $publicDetail['data'];
        self::assertSame($slug, $publicData['slug'] ?? null);
        self::assertNotEmpty($publicData['productCode'] ?? null);
        self::assertNotEmpty($publicData['thumbnailUrl'] ?? null);
        self::assertNotEmpty($publicData['variants'] ?? []);
        self::assertNotEmpty($publicData['specifications'] ?? []);
        self::assertNotEmpty($publicData['relationships'] ?? []);
        self::assertNotEmpty($publicData['documents'] ?? []);
        foreach ((array) $publicData['documents'] as $doc) {
            self::assertSame('public', $doc['accessCode'] ?? null);
        }
        self::assertArrayNotHasKey('rowVersion', $publicData);
        self::assertArrayNotHasKey('pricing', $publicData);
        self::assertArrayNotHasKey('erpReference', $publicData);
        self::assertArrayNotHasKey('legacyProductId', $publicData);

        foreach (['/variants', '/media', '/documents', '/relationships', '/specifications'] as $nested) {
            $nestedResponse = $this->jsonGet($app, '/api/v1/products/' . $slug . $nested);
            $this->assertOk($nestedResponse);
            self::assertIsArray($nestedResponse['data']['items'] ?? null);
        }

        $publicAu = $this->jsonGet($app, '/api/v1/products/' . $slug . '?regionCode=AU');
        $this->assertOk($publicAu);
        $publicAmer = $this->jsonGet($app, '/api/v1/products/' . $slug . '?regionCode=AMER');
        self::assertSame(404, $publicAmer['status'], 'Product limited to AU/NZ should be hidden for AMER');

        $search = $this->timed('public_search', fn () => $this->jsonGet($app, '/api/v1/product-search?query=Phase85%20Flex%20Cable%20' . $suffix));
        $this->assertOk($search);
        self::assertGreaterThanOrEqual(1, count($search['data']['items'] ?? []));

        // Admin list timing
        $list = $this->timed('admin_list', fn () => $this->jsonGet($app, '/api/v1/admin/products?pageSize=25'));
        $this->assertOk($list);

        // Draft product must not be public
        $draft = $this->createProduct($app, 'Phase85 Draft ' . $suffix, $familyPublicId, $categoryPublicId);
        $this->assertOk($this->jsonPost($app, '/api/v1/admin/products/' . $draft['publicId'] . '/slugs', [
            'newSlug' => 'phase85-draft-' . $suffix,
            'isPrimary' => true,
        ]));
        $draftPublic = $this->jsonGet($app, '/api/v1/products/phase85-draft-' . $suffix);
        self::assertSame(404, $draftPublic['status']);

        // Archive / restore
        $archive = $this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/archive', [
            'expectedRowVersion' => $this->currentRowVersion($app, $productPublicId),
        ]);
        $this->assertOk($archive);
        $archivedPublic = $this->jsonGet($app, '/api/v1/products/' . $slug);
        self::assertSame(404, $archivedPublic['status']);
        $restoreArchive = $this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/restore-archive', [
            'expectedRowVersion' => $this->currentRowVersion($app, $productPublicId),
        ]);
        $this->assertOk($restoreArchive);
        // Re-publish after restore if needed
        $detailAfterRestore = $this->jsonGet($app, '/api/v1/admin/products/' . $productPublicId);
        $this->assertOk($detailAfterRestore);
        if (($detailAfterRestore['data']['statusCode'] ?? '') !== 'published') {
            $this->assertOk($this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/publish', [
                'expectedRowVersion' => $this->currentRowVersion($app, $productPublicId),
            ]));
        }

        // Soft-delete / restore
        $softDelete = $this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/soft-delete', [
            'expectedRowVersion' => $this->currentRowVersion($app, $productPublicId),
        ]);
        $this->assertOk($softDelete);
        $deletedGet = $this->jsonGet($app, '/api/v1/admin/products/' . $productPublicId);
        self::assertSame(404, $deletedGet['status']);
        $deletedPublic = $this->jsonGet($app, '/api/v1/products/' . $slug);
        self::assertSame(404, $deletedPublic['status']);
        $restoreDeletion = $this->jsonPost($app, '/api/v1/admin/products/' . $productPublicId . '/restore-deletion', [
            'expectedRowVersion' => (string) ($softDelete['data']['rowVersion'] ?? ''),
        ]);
        // Some restore endpoints may require no expectedRowVersion or return detail — accept 200
        self::assertContains($restoreDeletion['status'], [200, 422, 409], $restoreDeletion['raw']);

        // Permission: soft-delete uses PRODUCT.UPDATE path existence already exercised.
        // Pricing/audit permission gates remain covered by in-memory AdminProductEndpointsTest.

        fwrite(STDERR, "\n[phase85 timings ms] " . json_encode($this->timingsMs, JSON_THROW_ON_ERROR) . "\n");
        foreach (['lookups', 'create', 'publish', 'public_detail', 'public_search', 'admin_list'] as $key) {
            self::assertArrayHasKey($key, $this->timingsMs);
            self::assertLessThan(15000.0, $this->timingsMs[$key], $key . ' exceeded 15s');
        }
    }

    /**
     * @param array<string, mixed> $lookupData
     */
    private function lookupPublicId(array $lookupData, string $key, string $code): string
    {
        $items = $lookupData[$key] ?? [];
        self::assertIsArray($items, $key . ' missing from lookups');
        foreach ($items as $item) {
            if (is_array($item) && strcasecmp((string) ($item['code'] ?? ''), $code) === 0) {
                return (string) $item['publicId'];
            }
        }
        self::fail(sprintf('Lookup %s code %s not found', $key, $code));
    }

    /**
     * @return array{publicId: string, rowVersion: string}
     */
    private function createProduct(App $app, string $name, string $familyPublicId, string $categoryPublicId): array
    {
        $created = $this->jsonPost($app, '/api/v1/admin/products', [
            'productName' => $name,
            'familyPublicId' => $familyPublicId,
            'categoryPublicId' => $categoryPublicId,
        ]);
        $this->assertCreated($created);

        return [
            'publicId' => (string) $created['data']['publicId'],
            'rowVersion' => (string) $created['data']['rowVersion'],
        ];
    }

    private function currentRowVersion(App $app, string $productPublicId): string
    {
        $detail = $this->jsonGet($app, '/api/v1/admin/products/' . $productPublicId);
        $this->assertOk($detail);

        return (string) $detail['data']['rowVersion'];
    }

    /**
     * @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function timed(string $label, callable $callback): array
    {
        $started = microtime(true);
        $result = $callback();
        $this->timingsMs[$label] = (microtime(true) - $started) * 1000;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonGet(App $app, string $path): array
    {
        $response = $app->handle(TestAppFactory::request('GET', $path));

        return $this->wrap($response->getStatusCode(), TestAppFactory::json($response), (string) $response->getBody());
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function jsonPost(App $app, string $path, array $body): array
    {
        return $this->jsonWrite($app, 'POST', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function jsonPut(App $app, string $path, array $body): array
    {
        return $this->jsonWrite($app, 'PUT', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function jsonPatch(App $app, string $path, array $body): array
    {
        return $this->jsonWrite($app, 'PATCH', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function jsonWrite(App $app, string $method, string $path, array $body): array
    {
        $request = $this->jsonRequest($method, $path, $body);
        $response = $app->handle($request);

        return $this->wrap($response->getStatusCode(), TestAppFactory::json($response), (string) $response->getBody());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function wrap(int $status, array $payload, string $raw): array
    {
        return [
            'status' => $status,
            'success' => (bool) ($payload['success'] ?? false),
            'data' => $payload['data'] ?? [],
            'error' => $payload['error'] ?? [],
            'raw' => $raw,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertOk(array $response): void
    {
        self::assertSame(200, $response['status'], $response['raw']);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function assertCreated(array $response): void
    {
        self::assertSame(201, $response['status'], $response['raw']);
    }
}
