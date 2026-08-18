<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationGroupCommand;
use TriCab\Core\Product\Application\DTO\SpecificationGroupRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Exception\SpecificationMasterInUse;
use TriCab\Core\Product\Application\Exception\SpecificationMasterNotFound;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SpecificationGroupService
{
    public function __construct(
        private readonly ProductSpecificationRepository $specifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters
     * @return list<\TriCab\Core\Product\Application\DTO\SpecificationGroupRecord>
     */
    public function list(array $filters = []): array
    {
        return $this->specifications->listGroupsAdmin($filters);
    }

    public function get(string $publicId): SpecificationGroupRecord
    {
        return $this->require($publicId, true);
    }

    public function usage(string $publicId): SpecificationUsage
    {
        $this->require($publicId, true);

        return $this->specifications->getGroupUsage($publicId);
    }

    public function create(ActorContext $actor, UpsertSpecificationGroupCommand $command): SpecificationGroupRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationGroupRecord {
            $code = SpecificationMasterSupport::normalizeCode($command->code);
            $name = SpecificationMasterSupport::requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->specifications->groupCodeExists($code)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $created = $this->specifications->createGroup([
                'code' => $code,
                'name' => $name,
                'description' => SpecificationMasterSupport::normalizeDescription($command->description),
                'displayOrder' => $command->displayOrder,
                'isActive' => $command->isActive,
            ], $this->audit->actorUserId($actor));
            $record = $this->require($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-group',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $record->publicId, 'code' => $record->code],
            );

            return $record;
        });
    }

    public function update(ActorContext $actor, UpsertSpecificationGroupCommand $command): SpecificationGroupRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationGroupRecord {
            $publicId = $command->groupPublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $this->require($publicId);
            $code = SpecificationMasterSupport::normalizeCode($command->code);
            $name = SpecificationMasterSupport::requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->specifications->groupCodeExists($code, $publicId)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $updated = $this->specifications->updateGroup(
                $publicId,
                [
                    'code' => $code,
                    'name' => $name,
                    'description' => SpecificationMasterSupport::normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-group',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'code' => $updated->code],
            );

            return $updated;
        });
    }

    public function activate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationGroupRecord
    {
        return $this->setActive($actor, $command, true);
    }

    public function deactivate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationGroupRecord
    {
        return $this->setActive($actor, $command, false);
    }

    public function archive(ActorContext $actor, SpecificationStatusCommand $command): SpecificationGroupRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationGroupRecord {
            $this->require($command->publicId);
            $usage = $this->specifications->getGroupUsage($command->publicId);
            if ($usage->hasBlockingReferencesForArchive()) {
                throw SpecificationMasterInUse::cannotArchive('Specification group', $usage);
            }
            $archived = $this->specifications->softDeleteGroup(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-group',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $archived->publicId],
            );

            return $archived;
        });
    }

    public function restore(ActorContext $actor, SpecificationStatusCommand $command): SpecificationGroupRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationGroupRecord {
            $this->require($command->publicId, true);
            $restored = $this->specifications->restoreGroup(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-group',
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
            $this->specifications->reorderGroups($command->orderedPublicIds, $this->audit->actorUserId($actor));
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-group',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    private function setActive(ActorContext $actor, SpecificationStatusCommand $command, bool $isActive): SpecificationGroupRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $isActive): SpecificationGroupRecord {
            $this->require($command->publicId);
            $updated = $this->specifications->setGroupActive(
                $command->publicId,
                $isActive,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-group',
                entityId: null,
                actionCode: $isActive ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function require(string $publicId, bool $includeDeleted = false): SpecificationGroupRecord
    {
        $record = $this->specifications->findGroupByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw SpecificationMasterNotFound::for('group', $publicId);
        }

        return $record;
    }
}
