<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\Product3DModelRowVersionCommand;
use TriCab\Core\Product\Application\Command\UpdateProduct3DModelCommand;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\Exception\Product3DModelNotFound;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\Service\Product3DViewerConfigurationValidator;
use TriCab\Core\Product\Domain\ValueObject\Product3DModelStatus;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class ManageProduct3DModelService
{
    public function __construct(
        private readonly Product3DModelRepository $models,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /** @return list<Product3DModelSummary> */
    public function list(string $productPublicId): array
    {
        return $this->models->listByProduct(ProductPublicId::fromString($productPublicId));
    }

    public function get(string $productPublicId, string $modelPublicId): Product3DModelSummary
    {
        $model = $this->models->findByPublicId($modelPublicId);
        if ($model === null || $model->productPublicId !== $productPublicId) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }

        return $model;
    }

    public function update(ActorContext $actor, UpdateProduct3DModelCommand $command): Product3DModelSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product3DModelSummary {
            $existing = $this->get($command->productPublicId, $command->modelPublicId);
            $data = [];
            if ($command->name !== null) {
                $data['name'] = trim($command->name);
            }
            if ($command->viewerConfiguration !== null) {
                $validated = (new Product3DViewerConfigurationValidator())->validate($command->viewerConfiguration);
                $data['viewerConfigurationJson'] = $validated['json'];
            }
            $updated = $this->models->update(
                $existing->publicId,
                $data,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($updated->productPublicId),
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'updated',
                newValues: ['modelPublicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function publish(ActorContext $actor, Product3DModelRowVersionCommand $command): Product3DModelSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product3DModelSummary {
            $existing = $this->get($command->productPublicId, $command->modelPublicId);
            $status = Product3DModelStatus::fromString($existing->statusCode);
            if (!$status->canPublish() || $existing->uploadStatusCode !== 'active') {
                throw ProductFileUploadException::invalidState('Only READY uploaded models can be published.');
            }
            $scan = ScanStatusCode::fromString($existing->scanStatusCode);
            if (!$scan->allowsPublicExposure()) {
                throw ProductFileUploadException::invalidState(
                    'Model cannot be published until the file scan status is clean (or not_required/skipped).',
                );
            }
            $updated = $this->models->publish(
                $existing->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($updated->productPublicId),
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'published',
                newValues: ['modelPublicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function unpublish(ActorContext $actor, Product3DModelRowVersionCommand $command): Product3DModelSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product3DModelSummary {
            $existing = $this->get($command->productPublicId, $command->modelPublicId);
            $updated = $this->models->unpublish(
                $existing->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($updated->productPublicId),
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'unpublished',
                newValues: ['modelPublicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function setPrimary(ActorContext $actor, Product3DModelRowVersionCommand $command): Product3DModelSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product3DModelSummary {
            $existing = $this->get($command->productPublicId, $command->modelPublicId);
            $updated = $this->models->setPrimary(
                $existing->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($updated->productPublicId),
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'set-primary',
                newValues: ['modelPublicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function softDelete(ActorContext $actor, Product3DModelRowVersionCommand $command): Product3DModelSummary
    {
        return $this->transaction->run(function () use ($actor, $command): Product3DModelSummary {
            $existing = $this->get($command->productPublicId, $command->modelPublicId);
            $updated = $this->models->softDelete(
                $existing->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: ProductPublicId::fromString($updated->productPublicId),
                entityTypeCode: 'product-3d-model',
                entityId: null,
                actionCode: 'soft-deleted',
                newValues: ['modelPublicId' => $updated->publicId],
            );

            return $updated;
        });
    }
}
