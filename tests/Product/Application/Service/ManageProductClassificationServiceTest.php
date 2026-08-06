<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Command\ClassificationStatusCommand;
use TriCab\Core\Product\Application\Command\ReorderProductClassificationCommand;
use TriCab\Core\Product\Application\Command\UpsertProductCategoryCommand;
use TriCab\Core\Product\Application\Command\UpsertProductFamilyCommand;
use TriCab\Core\Product\Application\Command\UpsertProductSeriesCommand;
use TriCab\Core\Product\Application\Exception\DuplicateProductClassificationCode;
use TriCab\Core\Product\Application\Exception\ProductClassificationConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductClassificationInUse;
use TriCab\Core\Product\Application\Service\ManageProductClassificationService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;

final class ManageProductClassificationServiceTest extends TestCase
{
    private InMemoryProductStore $store;
    private ManageProductClassificationService $service;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $this->service = new ManageProductClassificationService(
            $this->store->classification(),
            new PassthroughApplicationTransaction(),
            new ProductAuditRecorder($this->store->audit()),
        );
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
    }

    public function test_create_family_and_duplicate_code(): void
    {
        $created = $this->service->createFamily($this->actor, new UpsertProductFamilyCommand(
            name: 'Industrial',
            code: 'industrial',
            description: 'Industrial cables',
            displayOrder: 10,
        ));
        self::assertSame('INDUSTRIAL', $created->code);
        self::assertSame('Industrial', $created->name);

        $this->expectException(DuplicateProductClassificationCode::class);
        $this->service->createFamily($this->actor, new UpsertProductFamilyCommand(
            name: 'Other',
            code: 'INDUSTRIAL',
        ));
    }

    public function test_update_family_concurrency_conflict(): void
    {
        $created = $this->service->createFamily($this->actor, new UpsertProductFamilyCommand(
            name: 'Mining',
            code: 'MINING',
        ));

        $this->expectException(ProductClassificationConcurrencyConflict::class);
        $this->service->updateFamily($this->actor, new UpsertProductFamilyCommand(
            name: 'Mining Updated',
            code: 'MINING',
            expectedRowVersion: 'stale-version',
            familyPublicId: $created->publicId,
        ));
    }

    public function test_category_under_family_and_deactivate_blocked_by_active_series(): void
    {
        $family = $this->service->createFamily($this->actor, new UpsertProductFamilyCommand('Fam', 'FAM1'));
        $category = $this->service->createCategory($this->actor, new UpsertProductCategoryCommand(
            familyPublicId: $family->publicId,
            name: 'Flex',
            code: 'FLEX1',
        ));
        $this->service->createSeries($this->actor, new UpsertProductSeriesCommand(
            categoryPublicId: $category->publicId,
            name: 'Series A',
            code: 'SER1',
        ));

        $this->expectException(ProductClassificationInUse::class);
        $this->service->deactivateCategory($this->actor, new ClassificationStatusCommand(
            publicId: $category->publicId,
            expectedRowVersion: $category->rowVersion,
        ));
    }

    public function test_archive_series_without_products_and_reorder_families(): void
    {
        $a = $this->service->createFamily($this->actor, new UpsertProductFamilyCommand('A', 'A1', displayOrder: 10));
        $b = $this->service->createFamily($this->actor, new UpsertProductFamilyCommand('B', 'B1', displayOrder: 20));
        $this->service->reorderFamilies($this->actor, new ReorderProductClassificationCommand([
            $b->publicId,
            $a->publicId,
        ]));
        $listed = $this->service->listFamilies();
        self::assertSame($b->publicId, $listed[0]->publicId);
        self::assertSame(10, $listed[0]->displayOrder);

        $family = $this->service->createFamily($this->actor, new UpsertProductFamilyCommand('C', 'C1'));
        $category = $this->service->createCategory($this->actor, new UpsertProductCategoryCommand(
            familyPublicId: $family->publicId,
            name: 'Cat',
            code: 'CATX',
        ));
        $series = $this->service->createSeries($this->actor, new UpsertProductSeriesCommand(
            categoryPublicId: $category->publicId,
            name: 'Ser',
            code: 'SERX',
        ));
        $archived = $this->service->archiveSeries($this->actor, new ClassificationStatusCommand(
            publicId: $series->publicId,
            expectedRowVersion: $series->rowVersion,
        ));
        self::assertTrue($archived->isDeleted);
    }
}
