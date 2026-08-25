<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\DeleteStyleMediaAssetCommand;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Soft-deletes style media metadata. The physical blob is removed only when no
 * other active style or product record still references the same StoragePath.
 */
final class DeleteStyleMediaAssetService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductBlobStore $blobStore,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, DeleteStyleMediaAssetCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $style = $this->styles->findByPublicId($command->stylePublicId, false);
            if ($style === null) {
                throw ProductStyleNotFound::forPublicId($command->stylePublicId);
            }

            $record = $this->styles->findStyleAssetByPublicId($command->stylePublicId, $command->assetPublicId);
            if ($record === null) {
                throw ProductStyleNotFound::forPublicId($command->assetPublicId);
            }

            $path = is_string($record['storagePath'] ?? null) ? $record['storagePath'] : null;
            $container = is_string($record['storageContainer'] ?? null) ? $record['storageContainer'] : null;

            $this->styles->softDeleteStyleAsset(
                $command->stylePublicId,
                $command->assetPublicId,
                $this->audit->actorUserId($actor),
            );

            $remaining = 0;
            if ($path !== null && $path !== '') {
                $remaining = $this->styles->countActiveStoragePathReferences($path, $container);
                if ($remaining === 0 && $container !== null && $container !== '') {
                    try {
                        $this->blobStore->deleteObject($container, $path);
                    } catch (\Throwable) {
                        // best effort; metadata is already detached
                    }
                }
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style-media',
                entityId: null,
                actionCode: 'file-deleted',
                previousValues: [
                    'stylePublicId' => $command->stylePublicId,
                    'assetPublicId' => $command->assetPublicId,
                    'storagePath' => $path,
                    'blobDeleted' => $remaining === 0,
                    'remainingReferences' => $remaining,
                ],
            );
        });
    }
}
