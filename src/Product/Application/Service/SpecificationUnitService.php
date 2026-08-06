<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationUnitCommand;
use TriCab\Core\Product\Application\DTO\SpecificationUnitRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Exception\SpecificationMasterInUse;
use TriCab\Core\Product\Application\Exception\SpecificationMasterNotFound;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SpecificationUnitService
{
    public function __construct(
        private readonly ProductSpecificationRepository $specifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters
     * @return list<\TriCab\Core\Product\Application\DTO\SpecificationUnitRecord>
     */
    public function list(array $filters = []): array
    {
        return $this->specifications->listUnitsAdmin($filters);
    }

    public function get(string $publicId): SpecificationUnitRecord
    {
        return $this->require($publicId, true);
    }

    public function usage(string $publicId): SpecificationUsage
    {
        $this->require($publicId, true);

        return $this->specifications->getUnitUsage($publicId);
    }

    public function create(ActorContext $actor, UpsertSpecificationUnitCommand $command): SpecificationUnitRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationUnitRecord {
            $code = SpecificationMasterSupport::normalizeCode($command->code);
            $name = SpecificationMasterSupport::requireName($command->name);
            $symbol = trim($command->symbol);
            if ($symbol === '') {
                throw InvalidProductValue::for('Symbol', $command->symbol);
            }
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->specifications->unitCodeExists($code)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $created = $this->specifications->createUnit([
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'description' => SpecificationMasterSupport::normalizeDescription($command->description),
                'displayOrder' => $command->displayOrder,
                'isActive' => $command->isActive,
            ], $this->audit->actorUserId($actor));
            $record = $this->require($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-unit',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $record->publicId, 'code' => $record->code],
            );

            return $record;
        });
    }

    public function update(ActorContext $actor, UpsertSpecificationUnitCommand $command): SpecificationUnitRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationUnitRecord {
            $publicId = $command->unitPublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $this->require($publicId);
            $code = SpecificationMasterSupport::normalizeCode($command->code);
            $name = SpecificationMasterSupport::requireName($command->name);
            $symbol = trim($command->symbol);
            if ($symbol === '') {
                throw InvalidProductValue::for('Symbol', $command->symbol);
            }
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->specifications->unitCodeExists($code, $publicId)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $updated = $this->specifications->updateUnit(
                $publicId,
                [
                    'code' => $code,
                    'name' => $name,
                    'symbol' => $symbol,
                    'description' => SpecificationMasterSupport::normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-unit',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'code' => $updated->code],
            );

            return $updated;
        });
    }

    public function activate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationUnitRecord
    {
        return $this->setActive($actor, $command, true);
    }

    public function deactivate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationUnitRecord
    {
        return $this->setActive($actor, $command, false);
    }

    public function archive(ActorContext $actor, SpecificationStatusCommand $command): SpecificationUnitRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationUnitRecord {
            $this->require($command->publicId);
            $usage = $this->specifications->getUnitUsage($command->publicId);
            if ($usage->hasBlockingReferencesForArchive()) {
                throw SpecificationMasterInUse::cannotArchive('Specification unit', $usage);
            }
            $archived = $this->specifications->softDeleteUnit(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-unit',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $archived->publicId],
            );

            return $archived;
        });
    }

    public function restore(ActorContext $actor, SpecificationStatusCommand $command): SpecificationUnitRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationUnitRecord {
            $this->require($command->publicId, true);
            $restored = $this->specifications->restoreUnit(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-unit',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $restored->publicId],
            );

            return $restored;
        });
    }

    public function reorder(ActorContext $actor, ReorderSpecificationCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->specifications->reorderUnits($command->orderedPublicIds, $this->audit->actorUserId($actor));
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-unit',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    private function setActive(ActorContext $actor, SpecificationStatusCommand $command, bool $isActive): SpecificationUnitRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $isActive): SpecificationUnitRecord {
            $this->require($command->publicId);
            $updated = $this->specifications->setUnitActive(
                $command->publicId,
                $isActive,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-unit',
                entityId: null,
                actionCode: $isActive ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function require(string $publicId, bool $includeDeleted = false): SpecificationUnitRecord
    {
        $record = $this->specifications->findUnitByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw SpecificationMasterNotFound::for('unit', $publicId);
        }

        return $record;
    }
}
