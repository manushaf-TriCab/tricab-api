<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\InMemory\InMemoryCatalogueNavigationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class InMemoryCatalogueNavigationRepositoryTest extends TestCase
{
    public function test_tab_node_tree_and_public_reads(): void
    {
        $store = new InMemoryProductStore();
        $repo = new InMemoryCatalogueNavigationRepository($store);
        $actor = UserId::fromInt(1);

        $tab = $repo->createTab([
            'tabCode' => 'SHOP',
            'tabName' => 'Shop',
            'slug' => 'shop',
            'displayOrder' => 10,
        ], $actor);

        $root = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'nodeCode' => 'ROOT',
            'nodeName' => 'Root',
            'slug' => 'root',
        ], $actor);
        $child = $repo->createNode([
            'tabPublicId' => $tab->publicId,
            'parentPublicId' => $root->publicId,
            'nodeCode' => 'CHILD',
            'nodeName' => 'Child',
            'slug' => 'child',
        ], $actor);

        self::assertSame(1, $repo->nodeDepth($child->publicId));
        self::assertTrue($repo->wouldCreateCycle($root->publicId, $child->publicId));
        self::assertFalse($repo->wouldCreateCycle($child->publicId, $root->publicId));

        $forest = $repo->buildAdminForest($tab->publicId);
        self::assertCount(1, $forest);
        self::assertSame('root', $forest[0]->slug);
        self::assertCount(1, $forest[0]->children);

        $publicTabs = $repo->listPublicTabs();
        self::assertNotEmpty($publicTabs);
        $tree = $repo->getPublicTabTree('shop', null, false);
        self::assertCount(1, $tree);
        self::assertSame('root', $tree[0]->slug);
    }
}
