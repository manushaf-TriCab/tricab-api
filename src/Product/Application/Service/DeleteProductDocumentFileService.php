<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\DeleteProductDocumentFileCommand;
use TriCab\Core\Product\Application\Exception\ProductDocumentNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class DeleteProductDocumentFileService
{
    public function __construct(
        private readonly ProductDocumentRepository $documents,
        private readonly ProductBlobStore $blobStore,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, DeleteProductDocumentFileCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $record = $this->documents->findByPublicId($command->documentPublicId);
            if ($record === null) {
                throw ProductDocumentNotFound::forPublicId($command->documentPublicId);
            }

            if ($record->storageContainer !== null && $record->storagePath !== null) {
                try {
                    $this->blobStore->deleteObject($record->storageContainer, $record->storagePath);
                } catch (\Throwable) {
                    // best effort
                }
            }

            if ($record->pendingStorageContainer !== null && $record->pendingStoragePath !== null) {
                try {
                    $this->blobStore->deleteObject($record->pendingStorageContainer, $record->pendingStoragePath);
                } catch (\Throwable) {
                    // best effort
                }
            }

            $this->documents->clearFile($record->publicId, $this->audit->actorUserId($actor));

            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($record->productPublicId),
                entityTypeCode: 'product-document',
                entityId: null,
                actionCode: 'file-deleted',
                previousValues: [
                    'documentPublicId' => $record->publicId,
                    'storagePath' => $record->storagePath,
                    'publicUrl' => $record->publicUrl,
                ],
            );
        });
    }
}
