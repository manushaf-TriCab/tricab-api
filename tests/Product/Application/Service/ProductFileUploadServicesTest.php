<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CompleteProductMediaUploadCommand;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\Command\CreateProduct360SetCommand;
use TriCab\Core\Product\Application\Command\DeleteProductMediaFileCommand;
use TriCab\Core\Product\Application\Command\Initiate360FrameUploadCommand;
use TriCab\Core\Product\Application\Command\InitiateProductMediaUploadCommand;
use TriCab\Core\Product\Application\Command\ReplaceProductMediaUploadCommand;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Application\Service\CompleteProductMediaUploadService;
use TriCab\Core\Product\Application\Service\ConfigurableProductFileScanPolicy;
use TriCab\Core\Product\Application\Service\CreateProduct360SetService;
use TriCab\Core\Product\Application\Service\CreateProductService;
use TriCab\Core\Product\Application\Service\DeleteProductMediaFileService;
use TriCab\Core\Product\Application\Service\GdProductImageMetadataExtractor;
use TriCab\Core\Product\Application\Service\Initiate360FrameUploadService;
use TriCab\Core\Product\Application\Service\InitiateProductMediaUploadService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\ReplaceProductMediaUploadService;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Domain\ValueObject\ProductFileKind;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductBlobStore;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ProductFileUploadServicesTest extends TestCase
{
    private InMemoryProductStore $store;
    private InMemoryProductBlobStore $blobs;
    private PassthroughApplicationTransaction $transaction;
    private ProductAuditRecorder $audit;
    private ActorContext $actor;
    private ProductFilePathBuilder $paths;
    private ProductFileValidationPolicy $policy;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $this->blobs = new InMemoryProductBlobStore();
        $this->transaction = new PassthroughApplicationTransaction();
        $this->audit = new ProductAuditRecorder($this->store->audit());
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-public-id', emailAddress: 'admin@tricab.com');
        $this->paths = new ProductFilePathBuilder();
        $this->policy = new ProductFileValidationPolicy();
    }

    private function createProduct(): string
    {
        $service = new CreateProductService(
            $this->store->products(),
            $this->store->classification(),
            $this->transaction,
            $this->audit,
        );

        return $service->execute($this->actor, new CreateProductCommand(productName: 'Widget'))->publicId;
    }

    public function test_initiate_and_complete_media_upload(): void
    {
        $productPublicId = $this->createProduct();

        $initiate = new InitiateProductMediaUploadService(
            $this->store->media(),
            $this->blobs,
            $this->paths,
            $this->policy,
            $this->transaction,
            $this->audit,
        );

        $session = $initiate->execute($this->actor, new InitiateProductMediaUploadCommand(
            productPublicId: $productPublicId,
            mediaCategoryCode: 'gallery',
            mediaTypeCode: 'image',
            title: 'Hero',
            originalFileName: 'hero.png',
            contentType: 'image/png',
            byteSize: 12,
        ));

        self::assertSame(ProductFileKind::IMAGE, $session->fileKind);
        self::assertSame('PUT', $session->httpMethod);
        self::assertStringContainsString($session->entityPublicId, $session->storagePath);

        $binary = str_repeat('x', 12);
        $this->blobs->putObject($session->storageContainer, $session->storagePath, 'image/png', $binary);

        $complete = new CompleteProductMediaUploadService(
            $this->store->media(),
            $this->blobs,
            new ConfigurableProductFileScanPolicy(true),
            new GdProductImageMetadataExtractor(),
            $this->transaction,
            $this->audit,
        );

        $record = $complete->execute($this->actor, new CompleteProductMediaUploadCommand(
            mediaPublicId: $session->entityPublicId,
            binaryContent: $binary,
        ));

        self::assertSame(UploadStatusCode::ACTIVE, $record->uploadStatusCode);
        self::assertSame('clean', $record->scanStatusCode);
        self::assertNotNull($record->publicUrl);
        self::assertSame(12, $record->byteSize);

        $actions = array_map(static fn ($e) => $e->actionCode, $this->store->auditEntries());
        self::assertContains('upload-session-created', $actions);
        self::assertContains('upload-completed', $actions);
        foreach ($this->store->auditEntries() as $entry) {
            $encoded = json_encode($entry->newValues ?? []);
            self::assertIsString($encoded);
            self::assertStringNotContainsString('sig=write', $encoded);
        }
    }

    public function test_complete_rejects_missing_blob(): void
    {
        $productPublicId = $this->createProduct();
        $initiate = new InitiateProductMediaUploadService(
            $this->store->media(),
            $this->blobs,
            $this->paths,
            $this->policy,
            $this->transaction,
            $this->audit,
        );
        $session = $initiate->execute($this->actor, new InitiateProductMediaUploadCommand(
            productPublicId: $productPublicId,
            mediaCategoryCode: 'gallery',
            mediaTypeCode: 'image',
            title: 'Hero',
            originalFileName: 'hero.png',
            contentType: 'image/png',
            byteSize: 12,
        ));

        $complete = new CompleteProductMediaUploadService(
            $this->store->media(),
            $this->blobs,
            new ConfigurableProductFileScanPolicy(true),
            new GdProductImageMetadataExtractor(),
            $this->transaction,
            $this->audit,
        );

        $this->expectException(ProductFileUploadException::class);
        $complete->execute($this->actor, new CompleteProductMediaUploadCommand($session->entityPublicId));
    }

    public function test_replace_and_delete_media_file(): void
    {
        $productPublicId = $this->createProduct();
        $initiate = new InitiateProductMediaUploadService(
            $this->store->media(),
            $this->blobs,
            $this->paths,
            $this->policy,
            $this->transaction,
            $this->audit,
        );
        $session = $initiate->execute($this->actor, new InitiateProductMediaUploadCommand(
            productPublicId: $productPublicId,
            mediaCategoryCode: 'primary',
            mediaTypeCode: 'image',
            title: 'Hero',
            originalFileName: 'hero.png',
            contentType: 'image/png',
            byteSize: 8,
        ));
        $this->blobs->putObject($session->storageContainer, $session->storagePath, 'image/png', str_repeat('a', 8));
        $complete = new CompleteProductMediaUploadService(
            $this->store->media(),
            $this->blobs,
            new ConfigurableProductFileScanPolicy(true),
            new GdProductImageMetadataExtractor(),
            $this->transaction,
            $this->audit,
        );
        $complete->execute($this->actor, new CompleteProductMediaUploadCommand($session->entityPublicId));

        $replace = new ReplaceProductMediaUploadService(
            $this->store->media(),
            $this->blobs,
            $this->paths,
            $this->policy,
            $this->transaction,
            $this->audit,
        );
        $replaceSession = $replace->execute($this->actor, new ReplaceProductMediaUploadCommand(
            mediaPublicId: $session->entityPublicId,
            originalFileName: 'hero2.jpg',
            contentType: 'image/jpeg',
            byteSize: 10,
        ));
        $this->blobs->putObject($replaceSession->storageContainer, $replaceSession->storagePath, 'image/jpeg', str_repeat('b', 10));
        $replaced = $complete->execute($this->actor, new CompleteProductMediaUploadCommand($session->entityPublicId));
        self::assertSame(UploadStatusCode::ACTIVE, $replaced->uploadStatusCode);
        self::assertSame('image/jpeg', $replaced->contentType);

        $delete = new DeleteProductMediaFileService(
            $this->store->media(),
            $this->blobs,
            $this->transaction,
            $this->audit,
        );
        $delete->execute($this->actor, new DeleteProductMediaFileCommand($session->entityPublicId));
        $after = $this->store->media()->findByPublicId($session->entityPublicId);
        self::assertNotNull($after);
        self::assertSame(UploadStatusCode::DELETED, $after->uploadStatusCode);
        self::assertNull($after->publicUrl);
    }

    public function test_create_360_set_and_initiate_frame(): void
    {
        $productPublicId = $this->createProduct();
        $createSet = new CreateProduct360SetService($this->store->sets360(), $this->transaction, $this->audit);
        $set = $createSet->execute($this->actor, new CreateProduct360SetCommand(
            productPublicId: $productPublicId,
            title: 'Spin',
            expectedFrameCount: 2,
        ));
        self::assertSame(2, $set->expectedFrameCount);

        $initiateFrame = new Initiate360FrameUploadService(
            $this->store->sets360(),
            $this->blobs,
            $this->paths,
            $this->policy,
            $this->transaction,
            $this->audit,
        );
        $frameSession = $initiateFrame->execute($this->actor, new Initiate360FrameUploadCommand(
            setPublicId: $set->publicId,
            frameNumber: 1,
            originalFileName: 'f1.jpg',
            contentType: 'image/jpeg',
            byteSize: 4,
        ));
        self::assertSame(ProductFileKind::FRAME_360, $frameSession->fileKind);
        self::assertStringContainsString('/360/', $frameSession->storagePath);
    }
}
