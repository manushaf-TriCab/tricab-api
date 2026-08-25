<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignCatalogueNodeProductStyleCommand;
use TriCab\Core\Product\Application\Command\AssignCatalogueNodeProductsCommand;
use TriCab\Core\Product\Application\Command\RemoveCatalogueNodeProductStyleCommand;
use TriCab\Core\Product\Application\Command\CatalogueStatusCommand;
use TriCab\Core\Product\Application\Command\MoveCatalogueNodeCommand;
use TriCab\Core\Product\Application\Command\ReorderCatalogueCommand;
use TriCab\Core\Product\Application\Command\ReplaceProductCataloguePlacementsCommand;
use TriCab\Core\Product\Application\Command\UpsertCatalogueNodeCommand;
use TriCab\Core\Product\Application\Command\UpsertCatalogueTabCommand;
use TriCab\Core\Product\Application\DTO\CatalogueNodeProductRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeProductStyleRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeResolvedProductRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeTreeNode;
use TriCab\Core\Product\Application\DTO\CatalogueNodeUsage;
use TriCab\Core\Product\Application\DTO\CatalogueProductPlacementRecord;
use TriCab\Core\Product\Application\DTO\CatalogueTabRecord;
use TriCab\Core\Product\Application\Exception\CatalogueConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\CatalogueInUse;
use TriCab\Core\Product\Application\Exception\CatalogueNotFound;
use TriCab\Core\Product\Application\Exception\DuplicateCatalogueCode;
use TriCab\Core\Product\Application\Exception\DuplicateCatalogueSlug;
use TriCab\Core\Product\Application\Exception\InvalidCatalogueHierarchy;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Admin workflows for customer catalogue tabs, nodes, and product placements.
 */
final class ManageCatalogueNavigationService
{
    public function __construct(
        private readonly CatalogueNavigationRepository $catalogue,
        private readonly ApplicationTransaction $transaction,
        private readonly ProductAuditRecorder $audit,
        private readonly CatalogueNodeEffectiveProductsService $effectiveProducts,
        private readonly int $maxDepth = CatalogueNavigationRepository::MAX_DEPTH,
    ) {
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters
     * @return list<CatalogueTabRecord>
     */
    public function listTabs(array $filters = []): array
    {
        return $this->catalogue->listTabsAdmin($filters);
    }

    public function getTab(string $publicId): CatalogueTabRecord
    {
        return $this->requireTab($publicId, true);
    }

    public function createTab(ActorContext $actor, UpsertCatalogueTabCommand $command): CatalogueTabRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueTabRecord {
            $code = $this->normalizeCode($command->tabCode, 50);
            $name = $this->requireName($command->tabName);
            $slug = $this->normalizeSlug($command->slug);
            DisplayOrder::fromInt($command->displayOrder);

            if ($this->catalogue->tabCodeExists($code)) {
                throw DuplicateCatalogueCode::forTab($code);
            }
            if ($this->catalogue->tabSlugExists($slug)) {
                throw DuplicateCatalogueSlug::forTab($slug);
            }

            $created = $this->catalogue->createTab(
                [
                    'tabCode' => $code,
                    'tabName' => $name,
                    'slug' => $slug,
                    'description' => $this->normalizeDescription($command->description),
                    'displayOrder' => $command->displayOrder,
                    'isActive' => $command->isActive,
                    'isPublic' => $command->isPublic,
                ],
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-tab',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $created->publicId, 'tabCode' => $created->tabCode],
            );

            return $created;
        });
    }

    public function updateTab(ActorContext $actor, UpsertCatalogueTabCommand $command): CatalogueTabRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueTabRecord {
            $publicId = $command->tabPublicId ?? '';
            if ($publicId === '') {
                throw InvalidProductValue::for('tabPublicId', $publicId);
            }
            $this->requireTab($publicId);
            $expected = $this->requireRowVersion($command->expectedRowVersion);

            $data = [];
            if ($command->tabCode !== '') {
                $code = $this->normalizeCode($command->tabCode, 50);
                if ($this->catalogue->tabCodeExists($code, $publicId)) {
                    throw DuplicateCatalogueCode::forTab($code);
                }
                $data['tabCode'] = $code;
            }
            $data['tabName'] = $this->requireName($command->tabName);
            $slug = $this->normalizeSlug($command->slug);
            if ($this->catalogue->tabSlugExists($slug, $publicId)) {
                throw DuplicateCatalogueSlug::forTab($slug);
            }
            $data['slug'] = $slug;
            $data['description'] = $this->normalizeDescription($command->description);
            DisplayOrder::fromInt($command->displayOrder);
            $data['displayOrder'] = $command->displayOrder;
            $data['isActive'] = $command->isActive;
            $data['isPublic'] = $command->isPublic;

            try {
                $updated = $this->catalogue->updateTab($publicId, $data, $expected, $this->audit->actorUserId($actor));
            } catch (CatalogueConcurrencyConflict $e) {
                throw $e;
            }

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-tab',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function activateTab(ActorContext $actor, CatalogueStatusCommand $command): CatalogueTabRecord
    {
        return $this->setTabActive($actor, $command, true);
    }

    public function deactivateTab(ActorContext $actor, CatalogueStatusCommand $command): CatalogueTabRecord
    {
        return $this->setTabActive($actor, $command, false);
    }

    public function archiveTab(ActorContext $actor, CatalogueStatusCommand $command): CatalogueTabRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueTabRecord {
            $this->requireTab($command->publicId);
            $updated = $this->catalogue->softDeleteTab(
                $command->publicId,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-tab',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function restoreTab(ActorContext $actor, CatalogueStatusCommand $command): CatalogueTabRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueTabRecord {
            $this->requireTab($command->publicId, true);
            $updated = $this->catalogue->restoreTab(
                $command->publicId,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-tab',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function reorderTabs(ActorContext $actor, ReorderCatalogueCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->catalogue->reorderTabs($command->orderedPublicIds, $this->audit->actorUserId($actor));
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-tab',
                entityId: null,
                actionCode: 'reordered',
                newValues: ['orderedPublicIds' => $command->orderedPublicIds],
            );
        });
    }

    /**
     * @return list<CatalogueNodeTreeNode>
     */
    public function getTabTree(string $tabPublicId, bool $includeDeleted = false): array
    {
        $this->requireTab($tabPublicId, $includeDeleted);

        return $this->catalogue->buildAdminForest($tabPublicId, $includeDeleted);
    }

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, tabPublicId?: ?string, parentPublicId?: ?string} $filters
     * @return list<CatalogueNodeRecord>
     */
    public function listNodes(array $filters = []): array
    {
        return $this->catalogue->listNodesAdmin($filters);
    }

    public function getNode(string $publicId): CatalogueNodeRecord
    {
        return $this->requireNode($publicId, true);
    }

    public function getNodeUsage(string $publicId): CatalogueNodeUsage
    {
        $this->requireNode($publicId, true);

        return $this->catalogue->getNodeUsage($publicId);
    }

    public function createNode(ActorContext $actor, UpsertCatalogueNodeCommand $command): CatalogueNodeRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueNodeRecord {
            $this->requireTab($command->tabPublicId);
            $code = $this->normalizeCode($command->nodeCode, 80);
            $name = $this->requireName($command->nodeName);
            $slug = $this->normalizeSlug($command->slug);
            DisplayOrder::fromInt($command->displayOrder);
            $parentPublicId = $command->parentPublicId;

            if ($parentPublicId !== null) {
                $parent = $this->requireNode($parentPublicId);
                if ($parent->tabPublicId !== $command->tabPublicId) {
                    throw InvalidCatalogueHierarchy::differentTab();
                }
                $parentDepth = $this->catalogue->nodeDepth($parentPublicId);
                if ($parentDepth + 1 >= $this->maxDepth) {
                    throw InvalidCatalogueHierarchy::maxDepth($this->maxDepth);
                }
            }

            if ($this->catalogue->nodeCodeExists($command->tabPublicId, $code)) {
                throw DuplicateCatalogueCode::forNode($code);
            }
            if ($this->catalogue->siblingSlugExists($command->tabPublicId, $parentPublicId, $slug)) {
                throw DuplicateCatalogueSlug::forSibling($slug);
            }

            $created = $this->catalogue->createNode(
                [
                    'tabPublicId' => $command->tabPublicId,
                    'parentPublicId' => $parentPublicId,
                    'nodeCode' => $code,
                    'nodeName' => $name,
                    'slug' => $slug,
                    'description' => $this->normalizeDescription($command->description),
                    'nodeTypeCode' => $this->normalizeNodeType($command->nodeTypeCode),
                    'imageUrl' => $this->normalizeOptionalString($command->imageUrl, 1000),
                    'imageAltText' => $this->normalizeOptionalString($command->imageAltText, 300),
                    'imageMediaPublicId' => $command->imageMediaPublicId,
                    'displayOrder' => $command->displayOrder,
                    'isActive' => $command->isActive,
                    'isPublic' => $command->isPublic,
                    'seoTitle' => $this->normalizeOptionalString($command->seoTitle, 300),
                    'seoDescription' => $this->normalizeOptionalString($command->seoDescription, 1000),
                    'regionCodes' => $command->regionCodes ?? [],
                ],
                $this->audit->actorUserId($actor),
            );

            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: 'created',
                newValues: ['publicId' => $created->publicId, 'nodeCode' => $created->nodeCode],
            );

            return $created;
        });
    }

    public function updateNode(ActorContext $actor, UpsertCatalogueNodeCommand $command): CatalogueNodeRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueNodeRecord {
            $publicId = $command->nodePublicId ?? '';
            if ($publicId === '') {
                throw InvalidProductValue::for('nodePublicId', $publicId);
            }
            $existing = $this->requireNode($publicId);
            $expected = $this->requireRowVersion($command->expectedRowVersion);

            $code = $this->normalizeCode($command->nodeCode, 80);
            $name = $this->requireName($command->nodeName);
            $slug = $this->normalizeSlug($command->slug);
            DisplayOrder::fromInt($command->displayOrder);

            $parentPublicId = $command->parentPublicId;
            if ($parentPublicId === $publicId) {
                throw InvalidCatalogueHierarchy::selfParent();
            }
            if ($parentPublicId !== null) {
                $parent = $this->requireNode($parentPublicId);
                if ($parent->tabPublicId !== $existing->tabPublicId) {
                    throw InvalidCatalogueHierarchy::differentTab();
                }
                if ($this->catalogue->wouldCreateCycle($publicId, $parentPublicId)) {
                    throw InvalidCatalogueHierarchy::circular();
                }
                $parentDepth = $this->catalogue->nodeDepth($parentPublicId);
                $subtreeExtra = $this->subtreeHeight($publicId);
                if ($parentDepth + 1 + $subtreeExtra >= $this->maxDepth) {
                    throw InvalidCatalogueHierarchy::maxDepth($this->maxDepth);
                }
            }

            if ($this->catalogue->nodeCodeExists($existing->tabPublicId, $code, $publicId)) {
                throw DuplicateCatalogueCode::forNode($code);
            }
            if ($this->catalogue->siblingSlugExists($existing->tabPublicId, $parentPublicId, $slug, $publicId)) {
                throw DuplicateCatalogueSlug::forSibling($slug);
            }

            $data = [
                'parentPublicId' => $parentPublicId,
                'nodeCode' => $code,
                'nodeName' => $name,
                'slug' => $slug,
                'description' => $this->normalizeDescription($command->description),
                'nodeTypeCode' => $this->normalizeNodeType($command->nodeTypeCode),
                'imageUrl' => $this->normalizeOptionalString($command->imageUrl, 1000),
                'imageAltText' => $this->normalizeOptionalString($command->imageAltText, 300),
                'imageMediaPublicId' => $command->imageMediaPublicId,
                'displayOrder' => $command->displayOrder,
                'isActive' => $command->isActive,
                'isPublic' => $command->isPublic,
                'seoTitle' => $this->normalizeOptionalString($command->seoTitle, 300),
                'seoDescription' => $this->normalizeOptionalString($command->seoDescription, 1000),
            ];
            if ($command->regionCodes !== null) {
                $data['regionCodes'] = $command->regionCodes;
            }

            $updated = $this->catalogue->updateNode($publicId, $data, $expected, $this->audit->actorUserId($actor));
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: 'updated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function moveNode(ActorContext $actor, MoveCatalogueNodeCommand $command): CatalogueNodeRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueNodeRecord {
            $existing = $this->requireNode($command->nodePublicId);
            if ($command->newParentPublicId === $command->nodePublicId) {
                throw InvalidCatalogueHierarchy::selfParent();
            }
            if ($command->newParentPublicId !== null) {
                $parent = $this->requireNode($command->newParentPublicId);
                if ($parent->tabPublicId !== $existing->tabPublicId) {
                    throw InvalidCatalogueHierarchy::differentTab();
                }
                if ($this->catalogue->wouldCreateCycle($command->nodePublicId, $command->newParentPublicId)) {
                    throw InvalidCatalogueHierarchy::circular();
                }
                $parentDepth = $this->catalogue->nodeDepth($command->newParentPublicId);
                if ($parentDepth + 1 + $this->subtreeHeight($command->nodePublicId) >= $this->maxDepth) {
                    throw InvalidCatalogueHierarchy::maxDepth($this->maxDepth);
                }
            }

            $updated = $this->catalogue->moveNode(
                $command->nodePublicId,
                $command->newParentPublicId,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
                $command->displayOrder,
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: 'moved',
                newValues: [
                    'publicId' => $updated->publicId,
                    'parentPublicId' => $updated->parentPublicId,
                ],
            );

            return $updated;
        });
    }

    public function activateNode(ActorContext $actor, CatalogueStatusCommand $command): CatalogueNodeRecord
    {
        return $this->setNodeActive($actor, $command, true);
    }

    public function deactivateNode(ActorContext $actor, CatalogueStatusCommand $command): CatalogueNodeRecord
    {
        return $this->setNodeActive($actor, $command, false);
    }

    public function archiveNode(ActorContext $actor, CatalogueStatusCommand $command): CatalogueNodeRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueNodeRecord {
            $this->requireNode($command->publicId);
            $usage = $this->catalogue->getNodeUsage($command->publicId);
            if ($usage->childCount > 0) {
                throw CatalogueInUse::cannotArchive($usage->summary);
            }

            $updated = $this->catalogue->softDeleteNode(
                $command->publicId,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: 'archived',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function restoreNode(ActorContext $actor, CatalogueStatusCommand $command): CatalogueNodeRecord
    {
        return $this->transaction->run(function () use ($actor, $command): CatalogueNodeRecord {
            $this->requireNode($command->publicId, true);
            $updated = $this->catalogue->restoreNode(
                $command->publicId,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: 'restored',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    public function reorderNodes(ActorContext $actor, ReorderCatalogueCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $tabPublicId = $command->tabPublicId ?? '';
            if ($tabPublicId === '') {
                throw InvalidProductValue::for('tabPublicId', $tabPublicId);
            }
            $this->requireTab($tabPublicId);
            $this->catalogue->reorderNodes(
                $tabPublicId,
                $command->parentPublicId,
                $command->orderedPublicIds,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: 'reordered',
                newValues: [
                    'tabPublicId' => $tabPublicId,
                    'parentPublicId' => $command->parentPublicId,
                    'orderedPublicIds' => $command->orderedPublicIds,
                ],
            );
        });
    }

    /** @return list<CatalogueNodeProductRecord> */
    public function listNodeProducts(string $nodePublicId): array
    {
        $this->requireNode($nodePublicId, true);

        return $this->catalogue->listNodeProducts($nodePublicId, false);
    }

    public function assignNodeProducts(ActorContext $actor, AssignCatalogueNodeProductsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->requireNode($command->nodePublicId);
            $this->catalogue->replaceNodeProducts(
                $command->nodePublicId,
                $command->placements,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: 'products-assigned',
                newValues: [
                    'nodePublicId' => $command->nodePublicId,
                    'count' => count($command->placements),
                ],
            );
        });
    }

    /** @return list<CatalogueNodeProductStyleRecord> */
    public function listNodeProductStyles(string $nodePublicId): array
    {
        $this->requireNode($nodePublicId, true);

        return $this->catalogue->listNodeProductStyles($nodePublicId, false);
    }

    /** @return list<CatalogueNodeResolvedProductRecord> */
    public function listResolvedNodeProducts(string $nodePublicId, ?string $regionCode = null): array
    {
        $this->requireNode($nodePublicId, true);

        return $this->effectiveProducts->listResolvedAdmin($nodePublicId, $regionCode);
    }

    public function assignNodeProductStyle(ActorContext $actor, AssignCatalogueNodeProductStyleCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->requireNode($command->nodePublicId);
            $this->catalogue->addNodeProductStyle(
                $command->nodePublicId,
                $command->productStylePublicId,
                $command->displayOrder,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node-product-style',
                entityId: null,
                actionCode: 'assigned',
                newValues: [
                    'nodePublicId' => $command->nodePublicId,
                    'productStylePublicId' => $command->productStylePublicId,
                ],
            );
        });
    }

    public function removeNodeProductStyle(ActorContext $actor, RemoveCatalogueNodeProductStyleCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            $this->requireNode($command->nodePublicId);
            $this->catalogue->deactivateNodeProductStyle(
                $command->nodePublicId,
                $command->productStylePublicId,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node-product-style',
                entityId: null,
                actionCode: 'removed',
                newValues: [
                    'nodePublicId' => $command->nodePublicId,
                    'productStylePublicId' => $command->productStylePublicId,
                ],
            );
        });
    }

    /** @return list<CatalogueProductPlacementRecord> */
    public function listProductPlacements(string $productPublicId): array
    {
        return $this->catalogue->listProductPlacements($productPublicId);
    }

    public function replaceProductPlacements(ActorContext $actor, ReplaceProductCataloguePlacementsCommand $command): void
    {
        $this->transaction->run(function () use ($actor, $command): void {
            foreach ($command->placements as $placement) {
                $nodePublicId = $placement['nodePublicId'] ?? '';
                if (!is_string($nodePublicId) || $nodePublicId === '') {
                    throw InvalidCatalogueHierarchy::invalid('Each placement requires nodePublicId.');
                }
                $this->requireNode($nodePublicId);
            }
            $this->catalogue->replaceProductPlacements(
                $command->productPublicId,
                $command->placements,
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-placement',
                entityId: null,
                actionCode: 'replaced',
                newValues: [
                    'productPublicId' => $command->productPublicId,
                    'count' => count($command->placements),
                ],
            );
        });
    }

    /** @return array{tabs: list<CatalogueTabRecord>, nodes: list<CatalogueNodeRecord>} */
    public function search(string $query): array
    {
        $normalized = trim($query);
        if ($normalized === '') {
            return ['tabs' => [], 'nodes' => []];
        }

        return $this->catalogue->searchAdmin($normalized);
    }

    private function setTabActive(ActorContext $actor, CatalogueStatusCommand $command, bool $active): CatalogueTabRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $active): CatalogueTabRecord {
            $this->requireTab($command->publicId);
            $updated = $this->catalogue->setTabActive(
                $command->publicId,
                $active,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-tab',
                entityId: null,
                actionCode: $active ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function setNodeActive(ActorContext $actor, CatalogueStatusCommand $command, bool $active): CatalogueNodeRecord
    {
        return $this->transaction->run(function () use ($actor, $command, $active): CatalogueNodeRecord {
            $this->requireNode($command->publicId);
            $updated = $this->catalogue->setNodeActive(
                $command->publicId,
                $active,
                $this->requireRowVersion($command->expectedRowVersion),
                $this->audit->actorUserId($actor),
            );
            $this->audit->record(
                actor: $actor,
                productPublicId: null,
                entityTypeCode: 'catalogue-node',
                entityId: null,
                actionCode: $active ? 'activated' : 'deactivated',
                newValues: ['publicId' => $updated->publicId],
            );

            return $updated;
        });
    }

    private function requireTab(string $publicId, bool $includeDeleted = false): CatalogueTabRecord
    {
        $record = $this->catalogue->findTabByPublicId($publicId, $includeDeleted);
        if ($record === null || (!$includeDeleted && $record->isDeleted)) {
            throw CatalogueNotFound::forTab($publicId);
        }

        return $record;
    }

    private function requireNode(string $publicId, bool $includeDeleted = false): CatalogueNodeRecord
    {
        $record = $this->catalogue->findNodeByPublicId($publicId, $includeDeleted);
        if ($record === null || (!$includeDeleted && $record->isDeleted)) {
            throw CatalogueNotFound::forNode($publicId);
        }

        return $record;
    }

    private function requireRowVersion(?string $expectedRowVersion): string
    {
        if ($expectedRowVersion === null || trim($expectedRowVersion) === '') {
            throw InvalidProductValue::for('expectedRowVersion', (string) $expectedRowVersion);
        }

        return trim($expectedRowVersion);
    }

    private function subtreeHeight(string $nodePublicId): int
    {
        $node = $this->requireNode($nodePublicId, true);
        $nodes = $this->catalogue->listNodesForTab($node->tabPublicId, true);
        $byParent = [];
        foreach ($nodes as $n) {
            $key = $n->parentPublicId ?? '';
            $byParent[$key][] = $n;
        }

        $walk = function (string $id, int $depth) use (&$walk, $byParent): int {
            $max = $depth;
            foreach ($byParent[$id] ?? [] as $child) {
                $max = max($max, $walk($child->publicId, $depth + 1));
            }

            return $max;
        };

        return $walk($nodePublicId, 0);
    }

    private function normalizeCode(string $code, int $maxLen): string
    {
        $normalized = strtoupper(trim($code));
        if ($normalized === '' || strlen($normalized) > $maxLen) {
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

    private function normalizeSlug(string $slug): string
    {
        $normalized = strtolower(trim($slug));
        $normalized = preg_replace('/[^a-z0-9\-]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');
        if ($normalized === '' || strlen($normalized) > 160) {
            throw InvalidProductValue::for('slug', $slug);
        }

        return $normalized;
    }

    private function normalizeDescription(?string $description): ?string
    {
        return $this->normalizeOptionalString($description, 2000);
    }

    private function normalizeOptionalString(?string $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }
        if (strlen($normalized) > $maxLen) {
            throw InvalidProductValue::for('string', $value);
        }

        return $normalized;
    }

    private function normalizeNodeType(string $nodeTypeCode): string
    {
        $normalized = strtolower(trim($nodeTypeCode));
        if ($normalized === '') {
            return 'category';
        }
        $allowed = ['category', 'product-group', 'link', 'collection'];
        if (!in_array($normalized, $allowed, true)) {
            throw InvalidProductValue::for('nodeTypeCode', $nodeTypeCode);
        }

        return $normalized;
    }
}
