<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ClassificationStatusCommand;
use TriCab\Core\Product\Application\Command\ReorderProductClassificationCommand;
use TriCab\Core\Product\Application\Command\UpsertProductCategoryCommand;
use TriCab\Core\Product\Application\Command\UpsertProductFamilyCommand;
use TriCab\Core\Product\Application\Command\UpsertProductSeriesCommand;
use TriCab\Core\Product\Application\DTO\ProductClassificationRecord;
use TriCab\Core\Product\Application\DTO\ProductClassificationUsage;
use TriCab\Core\Product\Application\Exception\DuplicateProductClassificationCode;
use TriCab\Core\Product\Application\Exception\ProductClassificationInUse;
use TriCab\Core\Product\Application\Exception\ProductClassificationNotFound;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Cohesive admin workflows for Family / Category / Series.
 */
final class ManageProductClassificationService
{
    public function __construct(
        private readonly ProductClassificationRepository $classifications,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
    ) {
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters */
    public function listFamilies(array $filters = []): array
    {
        return $this->classifications->listFamiliesAdmin($filters);
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, parentPublicId?: ?string} $filters */
    public function listCategories(array $filters = []): array
    {
        return $this->classifications->listCategoriesAdmin($filters);
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, parentPublicId?: ?string} $filters */
    public function listSeries(array $filters = []): array
    {
        return $this->classifications->listSeriesAdmin($filters);
    }

    public function getFamily(string $publicId): ProductClassificationRecord
    {
        return $this->requireFamily($publicId, true);
    }

    public function getCategory(string $publicId): ProductClassificationRecord
    {
        return $this->requireCategory($publicId, true);
    }

    public function getSeries(string $publicId): ProductClassificationRecord
    {
        return $this->requireSeries($publicId, true);
    }

    public function getFamilyUsage(string $publicId): ProductClassificationUsage
    {
        $this->requireFamily($publicId, true);

        return $this->classifications->getFamilyUsage($publicId);
    }

    public function getCategoryUsage(string $publicId): ProductClassificationUsage
    {
        $this->requireCategory($publicId, true);

        return $this->classifications->getCategoryUsage($publicId);
    }

    public function getSeriesUsage(string $publicId): ProductClassificationUsage
    {
        $this->requireSeries($publicId, true);

        return $this->classifications->getSeriesUsage($publicId);
    }

    public function createFamily(ActorContext $actor, UpsertProductFamilyCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->classifications->familyCodeExists($code)) {
                throw DuplicateProductClassificationCode::forCode($code);
            }

            $created = $this->classifications->createFamily(
                [
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                    'isActive' => $command->isActive,
                ],
                $this->audit->actorUserId($actor),
            );

            $record = $this->requireFamily($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-family',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $record->publicId, 'code' => $record->code],
            );

            return $record;
        });
    }

    public function updateFamily(ActorContext $actor, UpsertProductFamilyCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $publicId = $command->familyPublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $this->requireFamily($publicId);
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->classifications->familyCodeExists($code, $publicId)) {
                throw DuplicateProductClassificationCode::forCode($code);
            }

            $updated = $this->classifications->updateFamily(
                $publicId,
                [
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-family',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'code' => $updated->code],
            );

            return $updated;
        });
    }

    public function activateFamily(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->setFamilyActive($actor, $command, true);
    }

    public function deactivateFamily(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        $usage = $this->classifications->getFamilyUsage($command->publicId);
        if ($usage->hasBlockingChildrenForDeactivate()) {
            throw ProductClassificationInUse::cannotDeactivate('Family', $usage);
        }

        return $this->setFamilyActive($actor, $command, false);
    }

    public function archiveFamily(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireFamily($command->publicId);
            $usage = $this->classifications->getFamilyUsage($command->publicId);
            if ($usage->hasBlockingReferencesForArchive()) {
                throw ProductClassificationInUse::cannotArchive('Family', $usage);
            }
            $archived = $this->classifications->softDeleteFamily(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-family',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $archived->publicId],
            );

            return $archived;
        });
    }

    public function restoreFamily(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireFamily($command->publicId, true);
            $restored = $this->classifications->restoreFamily(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-family',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $restored->publicId],
            );

            return $restored;
        });
    }

    public function reorderFamilies(ActorContext $actor, ReorderProductClassificationCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->classifications->reorderFamilies($command->orderedPublicIds, $this->audit->actorUserId($actor));
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-family',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    public function createCategory(ActorContext $actor, UpsertProductCategoryCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireFamily($command->familyPublicId);
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->classifications->categoryCodeExists($code)) {
                throw DuplicateProductClassificationCode::forCode($code);
            }

            $created = $this->classifications->createCategory(
                [
                    'code' => $code,
                    'name' => $name,
                    'familyPublicId' => $command->familyPublicId,
                    'description' => $this->normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                    'isActive' => $command->isActive,
                ],
                $this->audit->actorUserId($actor),
            );
            $record = $this->requireCategory($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-category',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $record->publicId, 'code' => $record->code],
            );

            return $record;
        });
    }

    public function updateCategory(ActorContext $actor, UpsertProductCategoryCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $publicId = $command->categoryPublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $this->requireCategory($publicId);
            $this->requireFamily($command->familyPublicId);
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->classifications->categoryCodeExists($code, $publicId)) {
                throw DuplicateProductClassificationCode::forCode($code);
            }

            $updated = $this->classifications->updateCategory(
                $publicId,
                [
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                    'familyPublicId' => $command->familyPublicId,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-category',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'familyPublicId' => $command->familyPublicId],
            );

            return $updated;
        });
    }

    public function activateCategory(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->setCategoryActive($actor, $command, true);
    }

    public function deactivateCategory(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        $usage = $this->classifications->getCategoryUsage($command->publicId);
        if ($usage->hasBlockingChildrenForDeactivate()) {
            throw ProductClassificationInUse::cannotDeactivate('Category', $usage);
        }

        return $this->setCategoryActive($actor, $command, false);
    }

    public function archiveCategory(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireCategory($command->publicId);
            $usage = $this->classifications->getCategoryUsage($command->publicId);
            if ($usage->hasBlockingReferencesForArchive()) {
                throw ProductClassificationInUse::cannotArchive('Category', $usage);
            }
            $archived = $this->classifications->softDeleteCategory(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-category',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $archived->publicId],
            );

            return $archived;
        });
    }

    public function restoreCategory(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireCategory($command->publicId, true);
            $restored = $this->classifications->restoreCategory(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-category',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $restored->publicId],
            );

            return $restored;
        });
    }

    public function reorderCategories(ActorContext $actor, ReorderProductClassificationCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $familyPublicId = $command->parentPublicId ?? '';
            if ($familyPublicId === '') {
                throw InvalidProductValue::for('familyPublicId', '');
            }
            $this->requireFamily($familyPublicId);
            $this->classifications->reorderCategories(
                $familyPublicId,
                $command->orderedPublicIds,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-category',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['familyPublicId' => $familyPublicId, 'orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    public function createSeries(ActorContext $actor, UpsertProductSeriesCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireCategory($command->categoryPublicId);
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->classifications->seriesCodeExists($code)) {
                throw DuplicateProductClassificationCode::forCode($code);
            }

            $created = $this->classifications->createSeries(
                [
                    'code' => $code,
                    'name' => $name,
                    'categoryPublicId' => $command->categoryPublicId,
                    'description' => $this->normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                    'isActive' => $command->isActive,
                ],
                $this->audit->actorUserId($actor),
            );
            $record = $this->requireSeries($created->publicId);
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-series',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $record->publicId, 'code' => $record->code],
            );

            return $record;
        });
    }

    public function updateSeries(ActorContext $actor, UpsertProductSeriesCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $publicId = $command->seriesPublicId ?? '';
            if ($publicId === '' || $command->expectedRowVersion === null || $command->expectedRowVersion === '') {
                throw InvalidProductValue::for('expectedRowVersion', (string) $command->expectedRowVersion);
            }
            $this->requireSeries($publicId);
            $this->requireCategory($command->categoryPublicId);
            $code = $this->normalizeCode($command->code);
            $name = $this->requireName($command->name);
            DisplayOrder::fromInt($command->displayOrder);
            if ($this->classifications->seriesCodeExists($code, $publicId)) {
                throw DuplicateProductClassificationCode::forCode($code);
            }

            $updated = $this->classifications->updateSeries(
                $publicId,
                [
                    'code' => $code,
                    'name' => $name,
                    'description' => $this->normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                    'categoryPublicId' => $command->categoryPublicId,
                ],
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-series',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId, 'categoryPublicId' => $command->categoryPublicId],
            );

            return $updated;
        });
    }

    public function activateSeries(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->setSeriesActive($actor, $command, true);
    }

    public function deactivateSeries(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->setSeriesActive($actor, $command, false);
    }

    public function archiveSeries(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireSeries($command->publicId);
            $usage = $this->classifications->getSeriesUsage($command->publicId);
            if ($usage->hasBlockingReferencesForArchive()) {
                throw ProductClassificationInUse::cannotArchive('Series', $usage);
            }
            $archived = $this->classifications->softDeleteSeries(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-series',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $archived->publicId],
            );

            return $archived;
        });
    }

    public function restoreSeries(ActorContext $actor, ClassificationStatusCommand $command): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command): ProductClassificationRecord {
            $this->requireSeries($command->publicId, true);
            $restored = $this->classifications->restoreSeries(
                $command->publicId,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-series',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $restored->publicId],
            );

            return $restored;
        });
    }

    public function reorderSeries(ActorContext $actor, ReorderProductClassificationCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $categoryPublicId = $command->parentPublicId ?? '';
            if ($categoryPublicId === '') {
                throw InvalidProductValue::for('categoryPublicId', '');
            }
            $this->requireCategory($categoryPublicId);
            $this->classifications->reorderSeries(
                $categoryPublicId,
                $command->orderedPublicIds,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-series',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['categoryPublicId' => $categoryPublicId, 'orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    private function setFamilyActive(ActorContext $actor, ClassificationStatusCommand $command, bool $active): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $active): ProductClassificationRecord {
            $this->requireFamily($command->publicId);
            $updated = $this->classifications->setFamilyActive(
                $command->publicId,
                $active,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-family',
                entityId: null,
                actionCode: $active ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function setCategoryActive(ActorContext $actor, ClassificationStatusCommand $command, bool $active): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $active): ProductClassificationRecord {
            $this->requireCategory($command->publicId);
            $updated = $this->classifications->setCategoryActive(
                $command->publicId,
                $active,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-category',
                entityId: null,
                actionCode: $active ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function setSeriesActive(ActorContext $actor, ClassificationStatusCommand $command, bool $active): ProductClassificationRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $active): ProductClassificationRecord {
            $this->requireSeries($command->publicId);
            $updated = $this->classifications->setSeriesActive(
                $command->publicId,
                $active,
                $command->expectedRowVersion,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'product-series',
                entityId: null,
                actionCode: $active ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function requireFamily(string $publicId, bool $includeDeleted = false): ProductClassificationRecord
    {
        $record = $this->classifications->findFamilyByPublicId($publicId, $includeDeleted);
        if ($record === null || (!$includeDeleted && $record->isDeleted)) {
            throw ProductClassificationNotFound::forPublicId($publicId);
        }

        return $record;
    }

    private function requireCategory(string $publicId, bool $includeDeleted = false): ProductClassificationRecord
    {
        $record = $this->classifications->findCategoryByPublicId($publicId, $includeDeleted);
        if ($record === null || (!$includeDeleted && $record->isDeleted)) {
            throw ProductClassificationNotFound::forPublicId($publicId);
        }

        return $record;
    }

    private function requireSeries(string $publicId, bool $includeDeleted = false): ProductClassificationRecord
    {
        $record = $this->classifications->findSeriesByPublicId($publicId, $includeDeleted);
        if ($record === null || (!$includeDeleted && $record->isDeleted)) {
            throw ProductClassificationNotFound::forPublicId($publicId);
        }

        return $record;
    }

    private function normalizeCode(string $code): string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '' || strlen($normalized) > 50) {
            throw InvalidProductValue::for('code', $code);
        }

        return $normalized;
    }

    private function requireName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '' || strlen($normalized) > 200) {
            throw InvalidProductValue::for('name', $name);
        }

        return $normalized;
    }

    private function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $normalized = trim($description);
        if ($normalized === '') {
            return null;
        }
        if (strlen($normalized) > 2000) {
            throw InvalidProductValue::for('description', $description);
        }

        return $normalized;
    }
}
