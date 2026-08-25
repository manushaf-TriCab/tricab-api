<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Identity\Contract\UserRepository;

final class ProductStyleEndpointsTest extends TestCase
{
    public function test_create_tree_cycle_and_effective_resources(): void
    {
        $app = $this->createApp();

        $bv = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles', [
            'styleCode' => 'BV',
            'displayName' => 'BV Base',
        ]));
        self::assertSame(201, $bv->getStatusCode(), (string) $bv->getBody());
        $bvData = TestAppFactory::json($bv)['data'];
        self::assertSame('BV', $bvData['styleCode']);
        self::assertSame('LOCAL', $bvData['inheritance']['SPECIFICATIONS']);

        $children = [];
        foreach (['BVA', 'BVC', 'BVM', 'BVP'] as $code) {
            $created = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles', [
                'styleCode' => $code,
                'displayName' => $code,
                'parentPublicId' => $bvData['publicId'],
            ]));
            self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
            $children[$code] = TestAppFactory::json($created)['data'];
            self::assertSame('INHERIT', $children[$code]['inheritance']['SPECIFICATIONS']);
        }

        $dup = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles', [
            'styleCode' => 'BV',
            'displayName' => 'Duplicate',
        ]));
        self::assertSame(409, $dup->getStatusCode());

        $selfParent = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/' . $bvData['publicId'] . '/parent', [
            'parentPublicId' => $bvData['publicId'],
            'expectedRowVersion' => $bvData['rowVersion'],
        ]));
        self::assertSame(422, $selfParent->getStatusCode());
        self::assertSame('INVALID_PRODUCT_STYLE_HIERARCHY', TestAppFactory::json($selfParent)['error']['code']);

        $cycle = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/' . $bvData['publicId'] . '/parent', [
            'parentPublicId' => $children['BVA']['publicId'],
            'expectedRowVersion' => TestAppFactory::json($app->handle(
                TestAppFactory::request('GET', '/api/v1/admin/product-styles/' . $bvData['publicId']),
            ))['data']['rowVersion'],
        ]));
        self::assertSame(422, $cycle->getStatusCode());

        $tree = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-styles/tree'));
        self::assertSame(200, $tree->getStatusCode());
        $roots = TestAppFactory::json($tree)['data']['items'];
        self::assertSame('BV', $roots[0]['styleCode']);
        self::assertCount(4, $roots[0]['children']);

        $stale = $app->handle($this->jsonRequest('PUT', '/api/v1/admin/product-styles/' . $bvData['publicId'], [
            'styleCode' => 'BV',
            'displayName' => 'Stale',
            'expectedRowVersion' => 'AAAAAAAAAAA=',
        ]));
        self::assertSame(409, $stale->getStatusCode());

        $unconfirmed = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-styles/' . $children['BVA']['publicId'] . '/make-local-copy',
            [
                'resourceType' => 'SPECIFICATIONS',
                'expectedRowVersion' => $children['BVA']['rowVersion'],
                'confirmed' => false,
            ],
        ));
        self::assertSame(422, $unconfirmed->getStatusCode());

        $preview = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/preview', [
            'styleCodes' => ['BV', 'BVA', 'BVC', 'BVM', 'BVP'],
        ]));
        self::assertSame(200, $preview->getStatusCode());
        $previewData = TestAppFactory::json($preview)['data'];
        self::assertSame(0, $previewData['writeGuard']['wordpressWrites']);
        self::assertSame(0, $previewData['writeGuard']['tcWrites']);
        self::assertFalse($previewData['writeGuard']['persisted']);
        self::assertSame(0, $previewData['writeGuard']['relationshipsApplied']);
        self::assertSame(0, $previewData['counters']['relationshipsApplied']);
        $proposal = null;
        foreach ($previewData['proposals'] as $row) {
            if (($row['childStyleCode'] ?? '') === 'BVA') {
                $proposal = $row;
                break;
            }
        }
        self::assertIsArray($proposal);
        self::assertSame('BV', $proposal['parentStyleCode']);
        self::assertSame('ALREADY_PERSISTED', $proposal['applicationState']);
        self::assertNotSame('APPLIED', $proposal['applicationState']);
        self::assertSame('BV', $this->getStyleCodeParent($app, $children['BVA']['publicId']));
    }

    public function test_style_media_upload_session_complete_and_delete(): void
    {
        $app = $this->createApp();
        $bv = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles', [
            'styleCode' => 'BV',
            'displayName' => 'BV Base',
        ])))['data'];
        $bytes = str_repeat('A', 128);
        $sessionResponse = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-styles/' . $bv['publicId'] . '/media/upload-session',
            [
                'resourceType' => 'IMAGES',
                'originalFileName' => 'hero.jpg',
                'contentType' => 'image/jpeg',
                'byteSize' => strlen($bytes),
                'title' => 'hero-bv.jpg',
                'roleCode' => 'hero',
                'isPrimary' => true,
            ],
        ));
        self::assertSame(201, $sessionResponse->getStatusCode(), (string) $sessionResponse->getBody());
        $session = TestAppFactory::json($sessionResponse)['data'];
        self::assertSame('PUT', $session['httpMethod']);
        self::assertStringContainsString('products/styles/' . $bv['publicId'] . '/images/', $session['storagePath']);
        self::assertArrayNotHasKey('accountKey', $session);
        self::assertStringStartsWith('products/styles/', $session['storagePath']);

        $complete = $app->handle($this->jsonRequest(
            'POST',
            '/api/v1/admin/product-styles/' . $bv['publicId'] . '/media/' . $session['entityPublicId'] . '/complete-upload',
            ['binaryBase64' => base64_encode($bytes)],
        ));
        self::assertSame(200, $complete->getStatusCode(), (string) $complete->getBody());

        $effective = TestAppFactory::json($app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/product-styles/' . $bv['publicId'] . '/effective-resources'),
        ))['data'];
        self::assertCount(1, $effective['images']);
        self::assertSame('hero', $effective['images'][0]['roleCode']);
        self::assertFalse($effective['images'][0]['inherited']);

        $delete = $app->handle(TestAppFactory::request(
            'DELETE',
            '/api/v1/admin/product-styles/' . $bv['publicId'] . '/media/' . $session['entityPublicId'],
        ));
        self::assertSame(200, $delete->getStatusCode(), (string) $delete->getBody());
    }

    public function test_permission_checks(): void
    {
        $app = $this->createApp();
        $this->replacePermissions($app, ['account.login', 'PRODUCT.READ']);

        $denied = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles', [
            'styleCode' => 'BV',
            'displayName' => 'BV',
        ]));
        self::assertSame(403, $denied->getStatusCode());

        $this->replacePermissions($app, ['account.login', 'PRODUCT.STYLES.READ']);
        $list = $app->handle(TestAppFactory::request('GET', '/api/v1/admin/product-styles'));
        self::assertSame(200, $list->getStatusCode());

        $this->replacePermissions($app, ['account.login', 'PRODUCT.READ']);
        $deniedPreview = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/preview', []));
        self::assertSame(403, $deniedPreview->getStatusCode());
    }

    public function test_evidence_review_is_read_only_and_apply_requires_confirmed_list(): void
    {
        $app = $this->createApp();
        $f1 = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles', [
            'styleCode' => 'F1',
            'displayName' => 'F1',
        ])))['data'];
        $f12 = TestAppFactory::json($app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles', [
            'styleCode' => 'F12',
            'displayName' => 'F12',
        ])))['data'];

        $review = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/evidence-review', []));
        self::assertSame(200, $review->getStatusCode(), (string) $review->getBody());
        $reviewData = TestAppFactory::json($review)['data'];
        self::assertSame(0, $reviewData['writeGuard']['wordpressWrites']);
        self::assertSame(0, $reviewData['writeGuard']['tcWrites']);
        self::assertSame(0, $reviewData['writeGuard']['parentStyleIdWrites']);
        self::assertSame(0, $reviewData['relationshipsApplied']);
        self::assertFalse($reviewData['writeGuard']['persisted']);
        self::assertNull($this->getStyleCodeParent($app, $f12['publicId']));

        $unconfirmed = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/apply-hierarchy', [
            'confirmed' => false,
            'relationships' => [
                ['parentStyleCode' => 'F1', 'childStyleCode' => 'F12'],
            ],
        ]));
        self::assertSame(422, $unconfirmed->getStatusCode());
        self::assertNull($this->getStyleCodeParent($app, $f12['publicId']));

        $empty = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/apply-hierarchy', [
            'confirmed' => true,
            'relationships' => [],
        ]));
        self::assertSame(200, $empty->getStatusCode(), (string) $empty->getBody());
        self::assertSame([], TestAppFactory::json($empty)['data']['items']);
        self::assertNull($this->getStyleCodeParent($app, $f12['publicId']));

        $applied = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/apply-hierarchy', [
            'confirmed' => true,
            'relationships' => [
                [
                    'parentStyleCode' => 'F1',
                    'childStyleCode' => 'F12',
                    'parentStylePublicId' => $f1['publicId'],
                    'childStylePublicId' => $f12['publicId'],
                ],
            ],
        ]));
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getBody());
        self::assertTrue(TestAppFactory::json($applied)['data']['items'][0]['applied']);
        self::assertSame('F1', $this->getStyleCodeParent($app, $f12['publicId']));
    }

    public function test_product_style_mapping_dry_run_and_unconfirmed_apply(): void
    {
        $app = $this->createApp();
        $mapping = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/product-mapping', []));
        self::assertSame(200, $mapping->getStatusCode(), (string) $mapping->getBody());
        $data = TestAppFactory::json($mapping)['data'];
        self::assertSame(0, $data['writeGuard']['productAssignmentWrites']);
        self::assertSame(0, $data['writeGuard']['parentStyleIdWrites']);
        self::assertFalse($data['writeGuard']['persisted']);
        self::assertSame(0, $data['assignmentsApplied']);
        self::assertSame([], $data['hierarchy']['selectedRelationships']);
        self::assertSame(0, $data['counters']['safeAssignmentCandidates']);

        $unconfirmed = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/apply-assignments', [
            'confirmed' => false,
            'assignments' => [['productPublicId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'productStylePublicId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']],
        ]));
        self::assertSame(422, $unconfirmed->getStatusCode());

        $empty = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/apply-assignments', [
            'confirmed' => true,
            'assignments' => [],
        ]));
        self::assertSame(200, $empty->getStatusCode(), (string) $empty->getBody());
        self::assertSame([], TestAppFactory::json($empty)['data']['items']);
    }

    public function test_product_code_style_mapping_dry_run_writes_nothing(): void
    {
        $app = $this->createApp();
        $mapping = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/product-code-mapping', []));
        self::assertSame(200, $mapping->getStatusCode(), (string) $mapping->getBody());
        $data = TestAppFactory::json($mapping)['data'];
        self::assertSame(0, $data['writeGuard']['productStyleWrites']);
        self::assertSame(0, $data['writeGuard']['productAssignmentWrites']);
        self::assertSame(0, $data['writeGuard']['parentStyleIdWrites']);
        self::assertSame(0, $data['writeGuard']['productWrites']);
        self::assertFalse($data['writeGuard']['persisted']);
        self::assertSame(0, $data['assignmentsApplied']);
        self::assertSame(0, $data['stylesCreated']);
        self::assertTrue($data['businessRule']['productCodeIsStyleIdentityForStyleShellProducts']);

        $unconfirmed = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/apply-product-code-mapping', [
            'confirmed' => false,
            'createStyles' => [['styleCode' => 'BHA']],
            'assignments' => [],
            'replaceAssignments' => [],
        ]));
        self::assertSame(422, $unconfirmed->getStatusCode());

        $empty = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/apply-product-code-mapping', [
            'confirmed' => true,
            'createStyles' => [],
            'assignments' => [],
            'replaceAssignments' => [],
        ]));
        self::assertSame(200, $empty->getStatusCode(), (string) $empty->getBody());
        $applied = TestAppFactory::json($empty)['data'];
        self::assertSame([], $applied['createdStyles']);
        self::assertSame([], $applied['assignments']);
        self::assertSame([], $applied['replacedAssignments']);
    }

    public function test_style_family_tree_dry_run_writes_nothing(): void
    {
        $app = $this->createApp();
        $response = $app->handle($this->jsonRequest('POST', '/api/v1/admin/product-styles/discovery/family-tree', []));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = TestAppFactory::json($response)['data'];
        self::assertSame(0, $data['writeGuard']['productStyleWrites']);
        self::assertSame(0, $data['writeGuard']['parentStyleIdWrites']);
        self::assertSame(0, $data['writeGuard']['productAssignmentWrites']);
        self::assertSame(0, $data['writeGuard']['specificationWrites']);
        self::assertSame(0, $data['writeGuard']['mediaWrites']);
        self::assertFalse($data['writeGuard']['persisted']);
        self::assertSame(0, $data['relationshipsApplied']);
        self::assertTrue($data['businessRule']['productMapsToExactChildStyle']);
    }

    /**
     * @return App<ContainerInterface>
     */
    private function createApp(): App
    {
        return TestAppFactory::create();
    }

    /**
     * @param array<string, mixed> $body
     */
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

    /**
     * @param App<ContainerInterface> $app
     */
    private function getStyleCodeParent(App $app, string $stylePublicId): ?string
    {
        $data = TestAppFactory::json($app->handle(
            TestAppFactory::request('GET', '/api/v1/admin/product-styles/' . $stylePublicId),
        ))['data'];

        return isset($data['parentStyleCode']) && is_string($data['parentStyleCode']) ? $data['parentStyleCode'] : null;
    }
}
