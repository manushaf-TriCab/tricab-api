<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\MakeLocalCopyStyleResourceCommand;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Snapshots currently effective inherited records onto the child style and
 * switches that resource type to LOCAL. Does not duplicate Azure blobs.
 */
final class MakeLocalCopyStyleResourceService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ResolveEffectiveStyleResourcesService $resolver,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    public function execute(ActorContext $actor, MakeLocalCopyStyleResourceCommand $command): ProductStyleRecord
    {
        if (!$command->confirmed) {
            throw InvalidProductValue::for('confirmed', 'false');
        }

        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $style = $this->styles->findByPublicId($command->stylePublicId, false);
            if ($style === null) {
                throw ProductStyleNotFound::forPublicId($command->stylePublicId);
            }
            $type = StyleResourceType::fromString($command->resourceType);

            if ($type->isSpecifications()) {
                $effective = $this->resolver->specificationsForStyle($style->publicId);
                $values = [];
                foreach ($effective as $item) {
                    $values[] = $item->value;
                }
                $this->styles->replaceStyleSpecificationValues(
                    $style->publicId,
                    $values,
                    $this->audit->actorUserId($actor),
                );
            } else {
                $effective = $this->resolver->assetsForStyle($style->publicId, $type);
                $assets = [];
                foreach ($effective as $item) {
                    $assets[] = $item->asset;
                }
                $this->styles->replaceStyleAssets(
                    $style->publicId,
                    $type,
                    $assets,
                    $this->audit->actorUserId($actor),
                );
            }

            $modes = $style->inheritance->toMap();
            $modes[$type->toString()] = StyleInheritanceMode::LOCAL;
            $updated = $this->styles->updateInheritance(
                $style->publicId,
                $modes,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'make-local-copy',
                previousValues: ['resourceType' => $type->toString(), 'mode' => $style->inheritance->modeFor($type)],
                newValues: ['resourceType' => $type->toString(), 'mode' => StyleInheritanceMode::LOCAL],
            );

            return $this->styles->findByPublicId($updated->publicId, false)
                ?? $updated;
        });
    }
}
