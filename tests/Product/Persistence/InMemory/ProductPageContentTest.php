<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence\InMemory;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;

final class ProductPageContentTest extends TestCase
{
    private const FAMILY_PUBLIC_ID = '11111111-1111-1111-1111-111111111111';

    private const REGION_PUBLIC_ID = '22222222-2222-2222-2222-222222222222';

    public function test_product_without_settings_uses_the_defaults(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Plain product');

        self::assertSame(
            ['showProductMarking' => false, 'coreColoursNote' => null, 'showCoreColoursNote' => true],
            $this->pageContent($this->view($app, $publicId)),
        );
    }

    public function test_save_reload_keeps_note_text_and_both_toggles(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'M3P-3C like');

        $update = $this->put($app, $publicId, [
            'showProductMarking' => false,
            'coreColoursNote' => "Other core colours available on request.",
            'showCoreColoursNote' => true,
        ]);
        self::assertSame(200, $update->getStatusCode(), (string) $update->getBody());
        self::assertSame(
            ['showProductMarking' => false, 'coreColoursNote' => 'Other core colours available on request.', 'showCoreColoursNote' => true],
            $this->pageContent($this->view($app, $publicId)),
        );

        $hide = $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => 'Other core colours available on request.', 'showCoreColoursNote' => false]);
        self::assertSame(200, $hide->getStatusCode(), (string) $hide->getBody());
        self::assertSame(
            ['showProductMarking' => true, 'coreColoursNote' => 'Other core colours available on request.', 'showCoreColoursNote' => false],
            $this->pageContent($this->view($app, $publicId)),
        );
    }

    public function test_each_product_keeps_its_own_independent_settings(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $a = $this->createProduct($app, 'Product A');
        $b = $this->createProduct($app, 'Product B');
        $c = $this->createProduct($app, 'Product C (never edited)');

        self::assertSame(200, $this->put($app, $a, ['showProductMarking' => true, 'coreColoursNote' => 'Note for A', 'showCoreColoursNote' => true])->getStatusCode());
        self::assertSame(200, $this->put($app, $b, ['showProductMarking' => false, 'coreColoursNote' => "Note for B\nsecond line", 'showCoreColoursNote' => false])->getStatusCode());

        self::assertSame('Note for A', $this->pageContent($this->view($app, $a))['coreColoursNote']);
        self::assertSame("Note for B\nsecond line", $this->pageContent($this->view($app, $b))['coreColoursNote']);
        self::assertFalse($this->pageContent($this->view($app, $b))['showProductMarking']);
        self::assertSame(['showProductMarking' => false, 'coreColoursNote' => null, 'showCoreColoursNote' => true], $this->pageContent($this->view($app, $c)));
    }

    public function test_existing_product_without_a_settings_row_keeps_marking_off(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Existing published-like product');

        $content = $this->pageContent($this->view($app, $publicId));
        self::assertFalse($content['showProductMarking'], 'Product Marking must be OFF without an explicit settings row');
        self::assertNull($this->version($app, $publicId));
        self::assertTrue(ProductPageContent::defaults()->showProductMarking() === false);
    }

    public function test_saving_only_a_note_never_turns_product_marking_on(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Note only');

        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => false, 'coreColoursNote' => 'N', 'showCoreColoursNote' => true])->getStatusCode());
        self::assertFalse($this->pageContent($this->view($app, $publicId))['showProductMarking']);
    }

    public function test_explicit_marking_true_and_false_are_both_honoured(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Explicit');

        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => null, 'showCoreColoursNote' => true])->getStatusCode());
        self::assertTrue($this->pageContent($this->view($app, $publicId))['showProductMarking']);
        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => false, 'coreColoursNote' => null, 'showCoreColoursNote' => true])->getStatusCode());
        self::assertFalse($this->pageContent($this->view($app, $publicId))['showProductMarking']);
    }

    public function test_version_token_changes_on_every_save_and_a_stale_save_is_a_409_that_changes_nothing(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Concurrent admins');

        $v0 = $this->version($app, $publicId);
        self::assertNull($v0);
        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => 'admin A', 'showCoreColoursNote' => true, 'expectedVersion' => $v0])->getStatusCode());
        $v1 = $this->version($app, $publicId);
        self::assertNotNull($v1);

        // administrator B still holds the old state (no row): must NOT silently overwrite A
        $stale = $this->put($app, $publicId, ['showProductMarking' => false, 'coreColoursNote' => 'admin B', 'showCoreColoursNote' => true, 'expectedVersion' => $v0]);
        self::assertSame(409, $stale->getStatusCode(), (string) $stale->getBody());
        self::assertSame('admin A', $this->pageContent($this->view($app, $publicId))['coreColoursNote']);
        self::assertTrue($this->pageContent($this->view($app, $publicId))['showProductMarking']);

        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => false, 'coreColoursNote' => 'admin B', 'showCoreColoursNote' => true, 'expectedVersion' => $v1])->getStatusCode());
        $v2 = $this->version($app, $publicId);
        self::assertNotNull($v2);
        self::assertNotSame($v1, $v2);
        self::assertSame(409, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => 'late', 'showCoreColoursNote' => true, 'expectedVersion' => $v1])->getStatusCode());
    }

    public function test_clearing_the_note_stores_null_and_crlf_is_normalised(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Clearable');

        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => "  line one\r\nline two  ", 'showCoreColoursNote' => true])->getStatusCode());
        self::assertSame("line one\nline two", $this->pageContent($this->view($app, $publicId))['coreColoursNote']);

        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => '   ', 'showCoreColoursNote' => true])->getStatusCode());
        self::assertNull($this->pageContent($this->view($app, $publicId))['coreColoursNote']);
    }

    public function test_rejects_invalid_input(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Validation');

        $tooLong = $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => str_repeat('x', ProductPageContent::NOTE_MAX_LENGTH + 1), 'showCoreColoursNote' => true]);
        self::assertSame(422, $tooLong->getStatusCode(), (string) $tooLong->getBody());
        $atLimit = $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => str_repeat('x', ProductPageContent::NOTE_MAX_LENGTH), 'showCoreColoursNote' => true]);
        self::assertSame(200, $atLimit->getStatusCode(), (string) $atLimit->getBody());

        self::assertSame(422, $this->put($app, $publicId, ['showProductMarking' => 'yes', 'coreColoursNote' => null, 'showCoreColoursNote' => true])->getStatusCode());
        self::assertSame(422, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => 12, 'showCoreColoursNote' => true])->getStatusCode());
        self::assertSame(422, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => null])->getStatusCode());
        self::assertSame(422, $this->putRaw($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => null, 'showCoreColoursNote' => true])->getStatusCode(), 'expectedVersion is required');
        self::assertSame(422, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => null, 'showCoreColoursNote' => true, 'expectedVersion' => ''])->getStatusCode());
        self::assertSame(422, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => null, 'showCoreColoursNote' => true, 'expectedVersion' => 5])->getStatusCode());
        self::assertSame(422, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => "a\0b", 'showCoreColoursNote' => true])->getStatusCode());
    }

    public function test_unknown_product_is_404(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $response = $this->putRaw($app, '99999999-9999-9999-9999-999999999999', ['showProductMarking' => false, 'coreColoursNote' => null, 'showCoreColoursNote' => true, 'expectedVersion' => null]);
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_published_page_and_preview_share_the_saved_settings(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $created = $this->createProductRecord($app, 'Published content');

        self::assertSame(200, $this->put($app, $created['publicId'], ['showProductMarking' => false, 'coreColoursNote' => 'Published note', 'showCoreColoursNote' => true])->getStatusCode());
        $markets = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $created['publicId'] . '/markets', ['regionPublicIds' => [self::REGION_PUBLIC_ID], 'marketPublicIds' => []]));
        self::assertSame(200, $markets->getStatusCode(), (string) $markets->getBody());
        $publish = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products/' . $created['publicId'] . '/publish', ['expectedRowVersion' => $created['rowVersion']]));
        self::assertSame(200, $publish->getStatusCode(), (string) $publish->getBody());

        $public = $app->handle(TestAppFactory::request('GET', '/api/v1/products/' . $created['publicId']));
        self::assertSame($this->pageContent($this->view($app, $created['publicId'])), $this->pageContent($public));
        self::assertSame('Published note', $this->pageContent($public)['coreColoursNote']);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function seedReferenceData(App $app): void
    {
        $store = $app->getContainer()->get(\TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore::class);
        self::assertInstanceOf(\TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore::class, $store);
        $store->addFamily([
            'publicId' => self::FAMILY_PUBLIC_ID,
            'code' => 'FAM',
            'name' => 'Test Family',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
        $store->addRegion([
            'publicId' => self::REGION_PUBLIC_ID,
            'code' => 'ANZ',
            'name' => 'Australia/NZ',
            'displayOrder' => 1,
            'isActive' => true,
        ]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function createProduct(App $app, string $name): string
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $name,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $data = TestAppFactory::json($create)['data'] ?? null;
        self::assertIsArray($data);
        $publicId = $data['publicId'] ?? null;
        self::assertIsString($publicId);

        return $publicId;
    }

    /**
     * @param App<ContainerInterface> $app
     * @return array{publicId: string, rowVersion: string}
     */
    private function createProductRecord(App $app, string $name): array
    {
        $create = $app->handle($this->jsonRequest('POST', '/api/v1/admin/products', [
            'productName' => $name,
            'familyPublicId' => self::FAMILY_PUBLIC_ID,
        ]));
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $data = TestAppFactory::json($create)['data'] ?? null;
        self::assertIsArray($data);
        $publicId = $data['publicId'] ?? null;
        $rowVersion = $data['rowVersion'] ?? null;
        self::assertIsString($publicId);
        self::assertIsString($rowVersion);

        return ['publicId' => $publicId, 'rowVersion' => $rowVersion];
    }

    /**
     * @return array{showProductMarking: bool, coreColoursNote: ?string, showCoreColoursNote: bool}
     */
    private function pageContent(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = TestAppFactory::json($response)['data'] ?? null;
        self::assertIsArray($data);
        $content = $data['pageContent'] ?? null;
        self::assertIsArray($content);
        self::assertIsBool($content['showProductMarking'] ?? null);
        self::assertIsBool($content['showCoreColoursNote'] ?? null);
        self::assertTrue(array_key_exists('coreColoursNote', $content));
        $note = $content['coreColoursNote'];
        self::assertTrue($note === null || is_string($note));

        return [
            'showProductMarking' => $content['showProductMarking'],
            'coreColoursNote' => $note,
            'showCoreColoursNote' => $content['showCoreColoursNote'],
        ];
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function view(App $app, string $publicId): \Psr\Http\Message\ResponseInterface
    {
        $view = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/products/' . $publicId . '/customer-view'));
        self::assertSame(200, $view->getStatusCode(), (string) $view->getBody());

        return $view;
    }

    public function test_page_content_write_requires_product_update_permission(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Guarded product');
        $version = $this->version($app, $publicId);

        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $users = $container->get(\TriCab\Core\Identity\Contract\UserRepository::class);
        self::assertInstanceOf(\TriCab\Api\Identity\Persistence\InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', 'PRODUCT.READ'], ['EMPLOYEE']);

        $response = $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => 'x', 'showCoreColoursNote' => true, 'expectedVersion' => $version]);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_page_content_update_is_audited_with_previous_and_new_values(): void
    {
        $app = TestAppFactory::create();
        $this->seedReferenceData($app);
        $publicId = $this->createProduct($app, 'Audited product');
        self::assertSame(200, $this->put($app, $publicId, ['showProductMarking' => true, 'coreColoursNote' => 'Audit me', 'showCoreColoursNote' => false])->getStatusCode());

        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $audit = $container->get(\TriCab\Core\Product\Contract\ProductAuditRepository::class);
        self::assertInstanceOf(\TriCab\Core\Product\Contract\ProductAuditRepository::class, $audit);
        $entries = array_values(array_filter(
            $audit->listByProduct(\TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($publicId)),
            static fn ($e): bool => $e->entityTypeCode === 'product-page-content',
        ));
        self::assertCount(1, $entries);
        self::assertSame('updated', $entries[0]->actionCode);
        self::assertStringContainsString('Audit me', (string) $entries[0]->newValuesJson);
        self::assertStringContainsString('"showProductMarking":false', (string) $entries[0]->previousValuesJson);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $body
     */
    private function put(App $app, string $publicId, array $body): \Psr\Http\Message\ResponseInterface
    {
        if (!array_key_exists('expectedVersion', $body)) {
            $body['expectedVersion'] = $this->version($app, $publicId);
        }

        return $this->putRaw($app, $publicId, $body);
    }

    /**
     * @param App<ContainerInterface> $app
     * @param array<string, mixed> $body
     */
    private function putRaw(App $app, string $publicId, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $app->handle($this->jsonRequest('PUT', '/api/v1/admin/products/' . $publicId . '/page-content', $body));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function version(App $app, string $publicId): ?string
    {
        $data = TestAppFactory::json($this->view($app, $publicId))['data'] ?? null;
        self::assertIsArray($data);
        $content = $data['pageContent'] ?? null;
        self::assertIsArray($content);
        $version = $content['version'] ?? null;
        self::assertTrue($version === null || is_string($version));

        return $version;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
    {
        $request = TestAppFactory::request($method, $path)
            ->withHeader('Content-Type', 'application/json');
        if ($body !== []) {
            $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        }

        return $request;
    }
}
