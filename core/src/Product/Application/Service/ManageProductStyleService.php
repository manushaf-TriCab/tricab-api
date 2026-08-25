<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\Command\ProductStyleStatusCommand;
use TriCab\Core\Product\Application\Command\ReorderProductStylesCommand;
use TriCab\Core\Product\Application\Command\ReplaceStyleSpecificationValuesCommand;
use TriCab\Core\Product\Application\Command\UpdateStyleInheritanceCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\ProductStyleInheritanceConfig;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Application\DTO\ProductStyleTreeNode;
use TriCab\Core\Product\Application\DTO\ProductStyleUsage;
use TriCab\Core\Product\Application\Exception\DuplicateProductStyleCode;
use TriCab\Core\Product\Application\Exception\InvalidProductStyleHierarchy;
use TriCab\Core\Product\Application\Exception\ProductStyleInUse;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Service\StyleHierarchyCycleGuard;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductStyleCode;
use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class ManageProductStyleService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ProductSpecificationRepository $specifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly StyleHierarchyCycleGuard $cycleGuard = new StyleHierarchyCycleGuard(),
    ) {
    }

    /**
     * @return list<ProductStyleRecord>
     */
    public function list(bool $includeDeleted = false): array
    {
        return $this->styles->listAll($includeDeleted);
    }

    /**
     * @return list<ProductStyleTreeNode>
     */
    public function tree(bool $includeDeleted = false): array
    {
        $records = $this->styles->listAll($includeDeleted);
        $byParent = [];
        foreach ($records as $record) {
            $key = $record->parentPublicId ?? '';
            $byParent[$key][] = $record;
        }

        $build = function (?string $parentPublicId) use (&$build, $byParent): array {
            $key = $parentPublicId ?? '';
            $nodes = [];
            foreach ($byParent[$key] ?? [] as $record) {
                $kids = $build($record->publicId);
                $nodes[] = new ProductStyleTreeNode(
                    publicId: $record->publicId,
                    styleCode: $record->styleCode,
                    displayName: $record->displayName,
                    parentPublicId: $record->parentPublicId,
                    sortOrder: $record->sortOrder,
                    isActive: $record->isActive,
                    childCount: count($kids),
                    productCount: $record->productCount,
                    children: $kids,
                );
            }

            return $nodes;
        };

        return $build(null);
    }

    public function get(string $publicId): ProductStyleRecord
    {
        return $this->requireStyle($publicId, true);
    }

    public function getUsage(string $publicId): ProductStyleUsage
    {
        $this->requireStyle($publicId, true);

        return $this->styles->getUsage($publicId);
    }

    public function create(ActorContext $actor, UpsertProductStyleCommand $command): ProductStyleRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $code = ProductStyleCode::fromString($command->styleCode)->toString();
            $name = $this->requireName($command->displayName);
            DisplayOrder::fromInt($command->sortOrder);
            if ($this->styles->styleCodeExists($code)) {
                throw DuplicateProductStyleCode::forCode($code);
            }
            $parentPublicId = $this->normalizeParent($command->parentPublicId);
            if ($parentPublicId !== null) {
                $this->requireStyle($parentPublicId, false);
            }
            $inheritance = $parentPublicId === null
                ? ProductStyleInheritanceConfig::localAll()
                : ProductStyleInheritanceConfig::inheritAll();

            $created = $this->styles->create(
                [
                    'styleCode' => $code,
                    'displayName' => $name,
                    'parentPublicId' => $parentPublicId,
                    'sortOrder' => $command->sortOrder,
                    'isActive' => $command->isActive,
                    'inheritance' => $inheritance->toMap(),
                ],
                $this->audit->actorUserId($actor),
            );
            $record = $this->requireStyle($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'created',
                newValues: [
                    'publicId' => $record->publicId,
                    'styleCode' => $record->styleCode,
                    'parentPublicId' => $record->parentPublicId,
                ],
            );

            return $record;
        });
    }

    public function update(ActorContext $actor, UpsertProductStyleCommand $command): ProductStyleRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $publicId = $command->stylePublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $existing = $this->requireStyle($publicId);
            $code = ProductStyleCode::fromString($command->styleCode)->toString();
            $name = $this->requireName($command->displayName);
            DisplayOrder::fromInt($command->sortOrder);
            if ($this->styles->styleCodeExists($code, $publicId)) {
                throw DuplicateProductStyleCode::forCode($code);
            }

            $updated = $this->styles->update(
                $publicId,
                [
                    'styleCode' => $code,
                    'displayName' => $name,
                    'sortOrder' => $command->sortOrder,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'updated',
                previousValues: ['styleCode' => $existing->styleCode, 'displayName' => $existing->displayName],
                newValues: ['styleCode' => $updated->styleCode, 'displayName' => $updated->displayName],
            );

            return $this->requireStyle($updated->publicId);
        });
    }

    public function changeParent(ActorContext $actor, ChangeProductStyleParentCommand $command): ProductStyleRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $existing = $this->requireStyle($command->stylePublicId);
            $parentPublicId = $this->normalizeParent($command->parentPublicId);
            if ($parentPublicId !== null) {
                $parent = $this->requireStyle($parentPublicId);
                $ancestorIds = array_map(
                    static fn (array $a): string => $a['publicId'],
                    $parent->ancestors,
                );
                $ancestorIds = array_merge([$parent->publicId], $ancestorIds);
                $this->cycleGuard->assertCanSetParent($existing->publicId, $parentPublicId, $ancestorIds);
            }

            $updated = $this->styles->setParent(
                $existing->publicId,
                $parentPublicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'parent-changed',
                previousValues: ['parentPublicId' => $existing->parentPublicId],
                newValues: ['parentPublicId' => $updated->parentPublicId],
            );

            return $this->requireStyle($updated->publicId);
        });
    }

    public function reorder(ActorContext $actor, ReorderProductStylesCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->styles->reorder(
                $this->normalizeParent($command->parentPublicId),
                $command->orderedPublicIds,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'reordered',
                newValues: [
                    'parentPublicId' => $command->parentPublicId,
                    'orderedPublicIds' => $command->orderedPublicIds,
                ],
            );
        });
    }

    public function updateInheritance(ActorContext $actor, UpdateStyleInheritanceCommand $command): ProductStyleRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $existing = $this->requireStyle($command->stylePublicId);
            $config = ProductStyleInheritanceConfig::fromMap($command->modes, $existing->inheritance);
            if ($existing->parentPublicId === null) {
                foreach ($config->toMap() as $type => $mode) {
                    if ($mode !== StyleInheritanceMode::LOCAL) {
                        throw InvalidProductStyleHierarchy::inheritRequiresParent($type);
                    }
                }
            }
            foreach ($config->toMap() as $mode) {
                StyleInheritanceMode::fromString($mode);
            }

            $updated = $this->styles->updateInheritance(
                $existing->publicId,
                $config->toMap(),
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'inheritance-changed',
                previousValues: $existing->inheritance->toMap(),
                newValues: $updated->inheritance->toMap(),
            );

            return $this->requireStyle($updated->publicId);
        });
    }

    public function replaceSpecifications(
        ActorContext $actor,
        ReplaceStyleSpecificationValuesCommand $command,
    ): ProductStyleRecord {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $existing = $this->requireStyle($command->stylePublicId);
            if ($command->expectedRowVersion !== $existing->rowVersion) {
                throw \TriCab\Core\Product\Application\Exception\ProductStyleConcurrencyConflict::forPublicId($existing->publicId);
            }
            $values = SpecificationCommandAssembler::assemble(
                $this->specifications->listDefinitions(true),
                $command->values,
                'product',
            );
            $this->styles->replaceStyleSpecificationValues(
                $existing->publicId,
                $values,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style-specification',
                entityId: null,
                actionCode: 'replaced',
                newValues: ['stylePublicId' => $existing->publicId, 'count' => count($values)],
            );

            return $this->requireStyle($existing->publicId);
        });
    }

    public function activate(ActorContext $actor, ProductStyleStatusCommand $command): ProductStyleRecord
    {
        return $this->setActive($actor, $command, true, 'activated');
    }

    public function deactivate(ActorContext $actor, ProductStyleStatusCommand $command): ProductStyleRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $existing = $this->requireStyle($command->stylePublicId);
            $usage = $this->styles->getUsage($existing->publicId);
            if ($usage->hasActiveChildren()) {
                throw ProductStyleInUse::cannotDeactivate($usage);
            }

            $updated = $this->styles->setActive(
                $existing->publicId,
                false,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'deactivated',
                previousValues: ['isActive' => true],
                newValues: ['isActive' => false],
            );

            return $this->requireStyle($updated->publicId);
        });
    }

    public function archive(ActorContext $actor, ProductStyleStatusCommand $command): ProductStyleRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $existing = $this->requireStyle($command->stylePublicId);
            $usage = $this->styles->getUsage($existing->publicId);
            if ($usage->hasBlockingChildren() || $usage->hasProducts()) {
                throw ProductStyleInUse::cannotArchive($usage);
            }

            $updated = $this->styles->softDelete(
                $existing->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'archived',
                previousValues: ['isDeleted' => false],
                newValues: ['isDeleted' => true],
            );

            return $this->requireStyle($updated->publicId, true);
        });
    }

    public function restore(ActorContext $actor, ProductStyleStatusCommand $command): ProductStyleRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductStyleRecord {
            $existing = $this->requireStyle($command->stylePublicId, true);
            $updated = $this->styles->restore(
                $existing->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: 'restored',
                previousValues: ['isDeleted' => true],
                newValues: ['isDeleted' => false],
            );

            return $this->requireStyle($updated->publicId);
        });
    }

    private function setActive(
        ActorContext $actor,
        ProductStyleStatusCommand $command,
        bool $isActive,
        string $actionCode,
    ): ProductStyleRecord {
        return $this->transaction->run(function () use ($actor, $command, $isActive, $actionCode): ProductStyleRecord {
            $existing = $this->requireStyle($command->stylePublicId);
            $updated = $this->styles->setActive(
                $existing->publicId,
                $isActive,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-style',
                entityId: null,
                actionCode: $actionCode,
                previousValues: ['isActive' => $existing->isActive],
                newValues: ['isActive' => $isActive],
            );

            return $this->requireStyle($updated->publicId);
        });
    }

    private function requireStyle(string $publicId, bool $includeDeleted = false): ProductStyleRecord
    {
        $record = $this->styles->findByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw ProductStyleNotFound::forPublicId($publicId);
        }

        return $record;
    }

    private function requireName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '' || strlen($normalized) > 200) {
            throw InvalidProductValue::for('displayName', $name);
        }

        return $normalized;
    }

    private function normalizeParent(?string $parentPublicId): ?string
    {
        if ($parentPublicId === null) {
            return null;
        }
        $trimmed = trim($parentPublicId);

        return $trimmed === '' ? null : $trimmed;
    }
}
