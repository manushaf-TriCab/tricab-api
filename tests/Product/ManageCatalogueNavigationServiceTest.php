<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryCatalogueNavigationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAuditRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\MoveCatalogueNodeCommand;
use TriCab\Core\Product\Application\Command\UpsertCatalogueNodeCommand;
use TriCab\Core\Product\Application\Command\UpsertCatalogueTabCommand;
use TriCab\Core\Product\Application\Exception\InvalidCatalogueHierarchy;
use TriCab\Core\Product\Application\Service\ManageCatalogueNavigationService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Api\Shared\Persistence\NoOpApplicationTransaction;

final class ManageCatalogueNavigationServiceTest extends TestCase
{
    private InMemoryProductStore $store;
    private ManageCatalogueNavigationService $service;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $this->service = new ManageCatalogueNavigationService(
            new InMemoryCatalogueNavigationRepository($this->store),
            new NoOpApplicationTransaction(),
            new ProductAuditRecorder(new InMemoryProductAuditRepository($this->store)),
            maxDepth: 3,
        );
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
    }

    public function test_move_node_under_descendant_is_rejected(): void
    {
        $tab = $this->service->createTab($this->actor, new UpsertCatalogueTabCommand('Products', 'PROD', 'products'));
        $root = $this->service->createNode($this->actor, new UpsertCatalogueNodeCommand(
            tabPublicId: $tab->publicId,
            nodeName: 'Root',
            nodeCode: 'ROOT',
            slug: 'root',
        ));
        $child = $this->service->createNode($this->actor, new UpsertCatalogueNodeCommand(
            tabPublicId: $tab->publicId,
            nodeName: 'Child',
            nodeCode: 'CHILD',
            slug: 'child',
            parentPublicId: $root->publicId,
        ));

        $this->expectException(InvalidCatalogueHierarchy::class);
        $this->service->moveNode($this->actor, new MoveCatalogueNodeCommand(
            nodePublicId: $root->publicId,
            newParentPublicId: $child->publicId,
            expectedRowVersion: $root->rowVersion,
        ));
    }

    public function test_create_node_beyond_max_depth_is_rejected(): void
    {
        $tab = $this->service->createTab($this->actor, new UpsertCatalogueTabCommand('Depth', 'DEPTH', 'depth'));
        $level0 = $this->service->createNode($this->actor, new UpsertCatalogueNodeCommand(
            tabPublicId: $tab->publicId,
            nodeName: 'L0',
            nodeCode: 'L0',
            slug: 'l0',
        ));
        $level1 = $this->service->createNode($this->actor, new UpsertCatalogueNodeCommand(
            tabPublicId: $tab->publicId,
            nodeName: 'L1',
            nodeCode: 'L1',
            slug: 'l1',
            parentPublicId: $level0->publicId,
        ));
        $level2 = $this->service->createNode($this->actor, new UpsertCatalogueNodeCommand(
            tabPublicId: $tab->publicId,
            nodeName: 'L2',
            nodeCode: 'L2',
            slug: 'l2',
            parentPublicId: $level1->publicId,
        ));

        $this->expectException(InvalidCatalogueHierarchy::class);
        $this->service->createNode($this->actor, new UpsertCatalogueNodeCommand(
            tabPublicId: $tab->publicId,
            nodeName: 'Too deep',
            nodeCode: 'L3',
            slug: 'l3',
            parentPublicId: $level2->publicId,
        ));
    }
}
