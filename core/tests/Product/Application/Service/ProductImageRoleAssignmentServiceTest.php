<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Command\ClearProductPresentationMediaRoleCommand;
use TriCab\Core\Product\Application\Command\UpdateProductMediaRoleCommand;
use TriCab\Core\Product\Application\Service\ClearProductPresentationMediaRoleService;
use TriCab\Core\Product\Application\Service\PresentationMediaResolver;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\UpdateProductMediaRoleService;
use TriCab\Core\Product\Domain\ValueObject\PresentationMediaRole;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;
use TriCab\Core\Tests\Product\Doubles\InMemoryFakeProductMediaRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;

final class ProductImageRoleAssignmentServiceTest extends TestCase
{
    private InMemoryProductStore $store;
    private InMemoryFakeProductMediaRepository $media;
    private UpdateProductMediaRoleService $updateRole;
    private ClearProductPresentationMediaRoleService $clearRole;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $this->media = new InMemoryFakeProductMediaRepository($this->store);
        $tx = new class () implements ApplicationTransaction {
            public function run(callable $callback): mixed
            {
                return $callback();
            }
        };
        $audit = new ProductAuditRecorder($this->store->audit());
        $this->updateRole = new UpdateProductMediaRoleService($this->media, $tx, $audit);
        $this->clearRole = new ClearProductPresentationMediaRoleService($this->media, $tx, $audit);
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $this->seedImages('11111111-1111-4111-8111-111111111111', ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']);
    }

    public function testSameImageCanHoldAllThreeRoles(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'cross-section');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['product-thumbnail']);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['primary']);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['cross-section']);

        $roles = $this->media->listPresentationRolesByMedia(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        sort($roles['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
        self::assertSame(['cross-section', 'primary', 'product-thumbnail'], $roles['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
    }

    public function testDifferentImagesCanHoldThreeRoles(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cross-section');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['product-thumbnail']);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $assignments['primary']);
        self::assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $assignments['cross-section']);
    }

    public function testAssignThumbnailPreservesPrimaryAndCrossSection(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cross-section');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'product-thumbnail');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $assignments['product-thumbnail']);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $assignments['primary']);
        self::assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $assignments['cross-section']);
    }

    public function testAssignPrimaryPreservesThumbnailAndCrossSection(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cross-section');
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'primary');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['product-thumbnail']);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['primary']);
        self::assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $assignments['cross-section']);
    }

    public function testAssignCrossSectionPreservesThumbnailAndPrimary(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cross-section');
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'cross-section');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['product-thumbnail']);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $assignments['primary']);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['cross-section']);
    }

    public function testClearThumbnailPreservesOtherRoles(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cross-section');
        $this->clear('11111111-1111-4111-8111-111111111111', 'product-thumbnail');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertArrayNotHasKey('product-thumbnail', $assignments);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $assignments['primary']);
        self::assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $assignments['cross-section']);
    }

    public function testClearPrimaryPreservesOtherRoles(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cross-section');
        $this->clear('11111111-1111-4111-8111-111111111111', 'primary');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['product-thumbnail']);
        self::assertArrayNotHasKey('primary', $assignments);
        self::assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $assignments['cross-section']);
    }

    public function testClearCrossSectionPreservesOtherRoles(): void
    {
        $this->assign('11111111-1111-4111-8111-111111111111', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'product-thumbnail');
        $this->assign('11111111-1111-4111-8111-111111111111', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'primary');
        $this->assign('11111111-1111-4111-8111-111111111111', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'cross-section');
        $this->clear('11111111-1111-4111-8111-111111111111', 'cross-section');

        $assignments = $this->media->listPresentationRoleAssignments(ProductPublicId::fromString('11111111-1111-4111-8111-111111111111'));
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $assignments['product-thumbnail']);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $assignments['primary']);
        self::assertArrayNotHasKey('cross-section', $assignments);
    }

    public function testExplicitAssignmentsBeatLegacyImageRoleCode(): void
    {
        $this->seedImages('22222222-2222-4222-8222-222222222222', ['dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee']);
        $this->media->updateImageRole(
            ProductPublicId::fromString('22222222-2222-4222-8222-222222222222'),
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'primary',
            UserId::fromInt(1),
        );
        $this->assign('22222222-2222-4222-8222-222222222222', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'product-thumbnail');

        $media = $this->media->listByProduct(ProductPublicId::fromString('22222222-2222-4222-8222-222222222222'));
        $thumb = PresentationMediaResolver::select($media, PresentationMediaRole::PRODUCT_THUMBNAIL);
        self::assertNotNull($thumb);
        self::assertSame('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', $thumb->publicId);
    }

    public function testPrimaryAssignmentBeatsLegacyIsPrimaryFallback(): void
    {
        $this->seedImages('33333333-3333-4333-8333-333333333333', ['ffffffff-ffff-4fff-8fff-ffffffffffff', '12121212-1212-4121-8121-121212121212']);
        $this->media->setPrimary(
            ProductPublicId::fromString('33333333-3333-4333-8333-333333333333'),
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            UserId::fromInt(1),
        );
        $this->assign('33333333-3333-4333-8333-333333333333', '12121212-1212-4121-8121-121212121212', 'primary');

        $media = $this->media->listByProduct(ProductPublicId::fromString('33333333-3333-4333-8333-333333333333'));
        $primary = PresentationMediaResolver::select($media, PresentationMediaRole::PRIMARY);
        self::assertNotNull($primary);
        self::assertSame('12121212-1212-4121-8121-121212121212', $primary->publicId);
    }

    public function testPresentationAssignDoesNotMutateLegacyImageRoleCode(): void
    {
        $this->seedImages('44444444-4444-4444-8444-444444444444', ['13131313-1313-4131-8131-131313131313']);
        $before = $this->media->listByProduct(ProductPublicId::fromString('44444444-4444-4444-8444-444444444444'))[0]->mediaCategoryCode;
        $this->assign('44444444-4444-4444-8444-444444444444', '13131313-1313-4131-8131-131313131313', 'cross-section');
        $after = $this->media->listByProduct(ProductPublicId::fromString('44444444-4444-4444-8444-444444444444'))[0];
        self::assertSame($before, $after->mediaCategoryCode);
        self::assertContains('cross-section', $after->presentationRoles);
    }

    public function testRejectsPresentationRoleOnNonPublicEligibleMedia(): void
    {
        $productId = '55555555-5555-4555-8555-555555555555';
        $mediaId = '14141414-1414-4141-8141-141414141414';
        $this->seedImages($productId, [$mediaId]);
        $map = $this->store->mediaByPublicIdMap();
        $map[$mediaId]['uploadStatusCode'] = 'pending_upload';
        $map[$mediaId]['publicUrl'] = null;
        $this->store->setMediaByPublicIdMap($map);
        $rows = $this->store->mediaFor($productId);
        $rows[0]['uploadStatusCode'] = 'pending_upload';
        $rows[0]['publicUrl'] = null;
        $this->store->setMediaFor($productId, $rows);

        $this->expectException(\TriCab\Core\Product\Domain\Exception\InvalidProductFileException::class);
        $this->assign($productId, $mediaId, 'cross-section');
    }

    /**
     * @param list<string> $ids
     */
    private function seedImages(string $productPublicId, array $ids): void
    {
        $rows = [];
        foreach ($ids as $index => $id) {
            $rows[] = [
                'publicId' => $id,
                'productPublicId' => $productPublicId,
                'mediaCategoryCode' => 'gallery',
                'mediaTypeCode' => 'primary-image',
                'title' => $id,
                'publicUrl' => 'https://example.test/' . $id . '.png',
                'altText' => null,
                'displayOrder' => $index,
                'isPrimary' => false,
                'uploadStatusCode' => 'active',
                'scanStatusCode' => 'clean',
                'byteSize' => 10,
                'contentType' => 'image/png',
                'originalFileName' => $id . '.png',
                'fileExtension' => 'png',
                'widthPx' => 100,
                'heightPx' => 100,
                'thumbnailUrl' => null,
                'storagePath' => null,
                'storageContainer' => null,
                'archived' => false,
            ];
        }
        $this->store->setMediaFor($productPublicId, $rows);
        $map = $this->store->mediaByPublicIdMap();
        foreach ($rows as $row) {
            $map[$row['publicId']] = $row;
        }
        $this->store->setMediaByPublicIdMap($map);
    }

    private function assign(string $productPublicId, string $mediaPublicId, string $role): void
    {
        $this->updateRole->execute(
            $this->actor,
            new UpdateProductMediaRoleCommand($productPublicId, $mediaPublicId, $role),
        );
    }

    private function clear(string $productPublicId, string $role): void
    {
        $this->clearRole->execute(
            $this->actor,
            new ClearProductPresentationMediaRoleCommand($productPublicId, $role),
        );
    }
}
