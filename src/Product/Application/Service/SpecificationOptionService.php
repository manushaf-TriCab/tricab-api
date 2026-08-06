<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ReorderSpecificationCommand;
use TriCab\Core\Product\Application\Command\SpecificationStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationOptionCommand;
use TriCab\Core\Product\Application\DTO\SpecificationOptionRecord;
use TriCab\Core\Product\Application\DTO\SpecificationUsage;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Exception\SpecificationMasterInUse;
use TriCab\Core\Product\Application\Exception\SpecificationMasterNotFound;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\SpecificationDataType;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class SpecificationOptionService
{
    public function __construct(
        private readonly ProductSpecificationRepository $specifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, definitionPublicId?: ?string} $filters
     * @return list<\TriCab\Core\Product\Application\DTO\SpecificationOptionRecord>
     */
    public function list(array $filters = []): array
    {
        return $this->specifications->listOptionsAdmin($filters);
    }

    public function get(string $publicId): SpecificationOptionRecord
    {
        return $this->require($publicId, true);
    }

    public function usage(string $publicId): SpecificationUsage
    {
        $this->require($publicId, true);

        return $this->specifications->getOptionUsage($publicId);
    }

    public function create(ActorContext $actor, UpsertSpecificationOptionCommand $command): SpecificationOptionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationOptionRecord {
            $definition = $this->specifications->findDefinitionByPublicId($command->definitionPublicId);
            if ($definition === null) {
                throw SpecificationMasterNotFound::for('definition', $command->definitionPublicId);
            }
            $dataType = SpecificationDataType::fromString($definition->dataTypeCode);
            if (!$dataType->isSelect()) {
                throw InvalidProductValue::for('DataTypeCode', $definition->dataTypeCode);
            }
            $code = SpecificationMasterSupport::normalizeCode($command->code);
            $name = SpecificationMasterSupport::requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->specifications->optionCodeExists($command->definitionPublicId, $code)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $created = $this->specifications->createOption([
                'definitionPublicId' => $command->definitionPublicId,
                'code' => $code,
                'name' => $name,
                'value' => SpecificationMasterSupport::normalizeDescription($command->value),
                'displayOrder' => $command->displayOrder,
                'isActive' => $command->isActive,
            ], $this->audit->actorUserId($actor));
            $record = $this->require($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-option',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $record->publicId, 'code' => $record->code],
            );

            return $record;
        });
    }

    public function update(ActorContext $actor, UpsertSpecificationOptionCommand $command): SpecificationOptionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationOptionRecord {
            $publicId = $command->optionPublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $existing = $this->require($publicId);
            $code = SpecificationMasterSupport::normalizeCode($command->code);
            $name = SpecificationMasterSupport::requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->specifications->optionCodeExists($existing->definitionPublicId, $code, $publicId)) {
                throw DuplicateSpecificationCode::forCode($code);
            }
            $updated = $this->specifications->updateOption(
                $publicId,
                [
                    'code' => $code,
                    'name' => $name,
                    'value' => SpecificationMasterSupport::normalizeDescription($command->value),
                    'displayOrder' => $command->displayOrder,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-option',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'code' => $updated->code],
            );

            return $updated;
        });
    }

    public function activate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationOptionRecord
    {
        return $this->setActive($actor, $command, true);
    }

    public function deactivate(ActorContext $actor, SpecificationStatusCommand $command): SpecificationOptionRecord
    {
        return $this->setActive($actor, $command, false);
    }

    public function archive(ActorContext $actor, SpecificationStatusCommand $command): SpecificationOptionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationOptionRecord {
            $this->require($command->publicId);
            $usage = $this->specifications->getOptionUsage($command->publicId);
            if ($usage->hasBlockingReferencesForArchive()) {
                throw SpecificationMasterInUse::cannotArchive('Specification option', $usage);
            }
            $archived = $this->specifications->softDeleteOption(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-option',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $archived->publicId],
            );

            return $archived;
        });
    }

    public function restore(ActorContext $actor, SpecificationStatusCommand $command): SpecificationOptionRecord
    {
        return $this->transaction->run(function () use ($actor, $command): SpecificationOptionRecord {
            $this->require($command->publicId, true);
            $restored = $this->specifications->restoreOption(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-option',
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
            $parent = $command->parentPublicId ?? '';
            if ($parent === '') {
                throw InvalidProductValue::for('definitionPublicId', '');
            }
            $this->specifications->reorderOptions($parent, $command->orderedPublicIds, $this->audit->actorUserId($actor));
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-option',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['definitionPublicId' => $parent, 'orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    private function setActive(ActorContext $actor, SpecificationStatusCommand $command, bool $isActive): SpecificationOptionRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $isActive): SpecificationOptionRecord {
            $this->require($command->publicId);
            $updated = $this->specifications->setOptionActive(
                $command->publicId,
                $isActive,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'specification-option',
                entityId: null,
                actionCode: $isActive ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function require(string $publicId, bool $includeDeleted = false): SpecificationOptionRecord
    {
        $record = $this->specifications->findOptionByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw SpecificationMasterNotFound::for('option', $publicId);
        }

        return $record;
    }
}
