<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteStyleMediaUploadCommand;
use TriCab\Core\Product\Application\Command\DeleteStyleMediaAssetCommand;
use TriCab\Core\Product\Application\Command\InitiateStyleMediaUploadCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\Service\CompleteStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\DeleteStyleMediaAssetService;
use TriCab\Core\Product\Application\Service\InitiateStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductBlobStore;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ProductStyleMediaUploadTest extends TestCase
{
    public function test_initiate_complete_and_reference_safe_delete(): void
    {
        $styles = new InMemoryProductStyleRepository();
        $products = new InMemoryProductStore();
        $blob = new InMemoryProductBlobStore();
        $actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $audit = new ProductAuditRecorder($products->audit());
        $tx = new PassthroughApplicationTransaction();
        $manage = new ManageProductStyleService($styles, $products->specifications(), $tx, $audit);
        $initiate = new InitiateStyleMediaUploadService(
            $styles,
            $blob,
            new ProductFilePathBuilder(),
            new ProductFileValidationPolicy(),
            $tx,
            $audit,
        );
        $complete = new CompleteStyleMediaUploadService($styles, $blob, $tx, $audit);
        $delete = new DeleteStyleMediaAssetService($styles, $blob, $tx, $audit);

        $bv = $manage->create($actor, new UpsertProductStyleCommand('BV', 'BV'));
        $bytes = str_repeat('J', 64);
        $session = $initiate->execute($actor, new InitiateStyleMediaUploadCommand(
            stylePublicId: $bv->publicId,
            resourceType: StyleResourceType::IMAGES,
            originalFileName: 'hero.jpg',
            contentType: 'image/jpeg',
            byteSize: strlen($bytes),
            title: 'hero-bv.jpg',
            roleCode: 'hero',
            isPrimary: true,
            storageContainer: 'products',
        ));
        self::assertStringContainsString('products/styles/' . $bv->publicId . '/images/', $session->storagePath);

        $completed = $complete->execute($actor, new CompleteStyleMediaUploadCommand(
            $bv->publicId,
            $session->entityPublicId,
            $bytes,
        ));
        self::assertSame('active', $completed['uploadStatusCode'] ?? null);
        self::assertTrue($blob->headObject('products', $session->storagePath)?->exists ?? false);

        $bva = $manage->create($actor, new UpsertProductStyleCommand('BVA', 'BVA', $bv->publicId));
        $styles->replaceStyleAssets($bva->publicId, StyleResourceType::images(), [
            [
                'title' => 'hero-copy.jpg',
                'roleCode' => 'hero',
                'storagePath' => $session->storagePath,
                'storageContainer' => 'products',
                'displayOrder' => 1,
                'isPrimary' => true,
            ],
        ], \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1));

        $delete->execute($actor, new DeleteStyleMediaAssetCommand($bv->publicId, $session->entityPublicId));
        self::assertTrue($blob->headObject('products', $session->storagePath)?->exists ?? false);
        self::assertSame(1, $styles->countActiveStoragePathReferences($session->storagePath, 'products'));
    }
}
