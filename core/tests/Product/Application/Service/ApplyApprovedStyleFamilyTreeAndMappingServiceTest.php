<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\Service\ApplyApprovedStyleFamilyTreeAndMappingService;
use TriCab\Core\Product\Application\Service\AssignProductStyleService;
use TriCab\Core\Product\Application\Service\CreateProductService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\StyleInheritanceMode;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ApplyApprovedStyleFamilyTreeAndMappingServiceTest extends TestCase
{
    private InMemoryProductStyleRepository $styles;
    private InMemoryProductStore $store;
    private ManageProductStyleService $manage;
    private AssignProductStyleService $assign;
    private ApplyApprovedStyleFamilyTreeAndMappingService $apply;
    private CreateProductService $products;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->store = new InMemoryProductStore();
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $audit = new ProductAuditRecorder($this->store->audit());
        $tx = new PassthroughApplicationTransaction();
        $this->manage = new ManageProductStyleService($this->styles, $this->store->specifications(), $tx, $audit);
        $this->assign = new AssignProductStyleService($this->styles, $this->store->adminQuery(), $tx, $audit);
        $this->apply = new ApplyApprovedStyleFamilyTreeAndMappingService(
            $this->manage,
            $this->assign,
            $this->styles,
            $audit,
        );
        $this->products = new CreateProductService(
            $this->store->products(),
            $this->store->classification(),
            $tx,
            $audit,
        );

        foreach (['BV', 'BVA', 'BVC', 'BVM', 'BVP', 'BVA-X', 'RC', 'BH', 'PJ', 'UP', 'UPA'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
        }
        foreach (['BVA', 'BVC', 'BVM', 'BVP'] as $child) {
            $this->setParent($child, 'BV');
        }
        $this->setParent('BVA-X', 'BVA');
    }

    public function test_requires_confirmation_and_development(): void
    {
        try {
            $this->apply->apply($this->actor, false, true, ['RCA'], [], [], []);
            self::fail('Expected confirmation exception');
        } catch (InvalidProductValue) {
            self::assertNull($this->styles->findByStyleCode('RCA'));
        }

        $this->expectException(\RuntimeException::class);
        $this->apply->apply($this->actor, true, false, ['RCA'], [], [], []);
    }

    public function test_creates_local_styles_links_parents_assigns_and_replaces_rca(): void
    {
        $rca = $this->products->execute($this->actor, new CreateProductCommand(productName: 'RCA product'));
        $bha = $this->products->execute($this->actor, new CreateProductCommand(productName: 'BHA product'));
        $pjt = $this->products->execute($this->actor, new CreateProductCommand(productName: 'PJT product'));
        $up = $this->products->execute($this->actor, new CreateProductCommand(productName: 'UP product'));
        $upa = $this->products->execute($this->actor, new CreateProductCommand(productName: 'UPA product'));
        $bva = $this->styles->findByStyleCode('BVA');
        self::assertNotNull($bva);
        $this->styles->assignProduct($rca->publicId, $bva->publicId, UserId::fromInt(1));

        $result = $this->apply->apply(
            $this->actor,
            true,
            true,
            ['RCA', 'RCC', 'RCM', 'RCP', 'BHA', 'PJT', 'UPC', 'UPM'],
            [
                ['parentStyleCode' => 'RC', 'childStyleCode' => 'RCA'],
                ['parentStyleCode' => 'RC', 'childStyleCode' => 'RCC'],
                ['parentStyleCode' => 'RC', 'childStyleCode' => 'RCM'],
                ['parentStyleCode' => 'RC', 'childStyleCode' => 'RCP'],
                ['parentStyleCode' => 'BH', 'childStyleCode' => 'BHA'],
                ['parentStyleCode' => 'PJ', 'childStyleCode' => 'PJT'],
                ['parentStyleCode' => 'UP', 'childStyleCode' => 'UPA'],
                ['parentStyleCode' => 'UP', 'childStyleCode' => 'UPC'],
                ['parentStyleCode' => 'UP', 'childStyleCode' => 'UPM'],
                ['parentStyleCode' => 'BVA', 'childStyleCode' => 'BVA-X'],
            ],
            [
                ['productPublicId' => $bha->publicId, 'styleCode' => 'BHA'],
                ['productPublicId' => $pjt->publicId, 'styleCode' => 'PJT'],
                ['productPublicId' => $up->publicId, 'styleCode' => 'UP'],
                ['productPublicId' => $upa->publicId, 'styleCode' => 'UPA'],
            ],
            [
                ['productPublicId' => $rca->publicId, 'fromStyleCode' => 'BVA', 'toStyleCode' => 'RCA'],
            ],
            [
                ['parentStyleCode' => 'PJ', 'childStyleCode' => 'PJT'],
                ['parentStyleCode' => 'BVA', 'childStyleCode' => 'BVA-X'],
            ],
        );

        self::assertSame(8, $result['counters']['stylesCreated']);
        self::assertSame(0, $result['counters']['stylesAlreadyExisted']);
        // Excluded PJ→PJT and BVA→BVA-X; remaining = 8 links.
        self::assertSame(8, $result['counters']['parentLinksCreated']);
        self::assertSame(0, $result['counters']['parentConflicts']);
        self::assertSame(2, $result['counters']['parentExcluded']);
        self::assertSame(0, $result['counters']['selfCycles'] + $result['counters']['directCycles'] + $result['counters']['deepCycles']);
        self::assertTrue($result['inheritanceUnchanged']['ok']);

        $rcaStyle = $this->styles->findByStyleCode('RCA');
        self::assertNotNull($rcaStyle);
        self::assertSame('RC', $rcaStyle->parentStyleCode);
        self::assertSame(StyleInheritanceMode::LOCAL, $rcaStyle->inheritance->specifications);
        self::assertSame('RCA', $this->styles->findAssignmentByProductPublicId($rca->publicId)?->styleCode);
        self::assertNotNull($this->styles->findByStyleCode('BVA'));

        $pjtStyle = $this->styles->findByStyleCode('PJT');
        self::assertNotNull($pjtStyle);
        self::assertNull($pjtStyle->parentStyleCode);
        self::assertSame('PJT', $this->styles->findAssignmentByProductPublicId($pjt->publicId)?->styleCode);

        self::assertSame('BVA', $this->styles->findByStyleCode('BVA-X')?->parentStyleCode);
        self::assertSame('UP', $this->styles->findAssignmentByProductPublicId($up->publicId)?->styleCode);
        self::assertSame('UP', $this->styles->findByStyleCode('UPA')?->parentStyleCode);
        self::assertSame('UP', $this->styles->findByStyleCode('UPC')?->parentStyleCode);

        foreach (['BVA', 'BVC', 'BVM', 'BVP'] as $child) {
            self::assertSame('BV', $this->styles->findByStyleCode($child)?->parentStyleCode);
        }

        // Idempotent second pass
        $again = $this->apply->apply(
            $this->actor,
            true,
            true,
            ['RCA', 'RCC', 'RCM', 'RCP', 'BHA', 'PJT', 'UPC', 'UPM'],
            [
                ['parentStyleCode' => 'RC', 'childStyleCode' => 'RCA'],
                ['parentStyleCode' => 'BH', 'childStyleCode' => 'BHA'],
                ['parentStyleCode' => 'UP', 'childStyleCode' => 'UPA'],
            ],
            [
                ['productPublicId' => $bha->publicId, 'styleCode' => 'BHA'],
                ['productPublicId' => $up->publicId, 'styleCode' => 'UP'],
            ],
            [
                ['productPublicId' => $rca->publicId, 'fromStyleCode' => 'BVA', 'toStyleCode' => 'RCA'],
            ],
            [
                ['parentStyleCode' => 'PJ', 'childStyleCode' => 'PJT'],
            ],
        );
        self::assertSame(0, $again['counters']['stylesCreated']);
        self::assertSame(0, $again['counters']['parentLinksCreated']);
        self::assertGreaterThan(0, $again['counters']['parentLinksAlreadyCorrect']);
        self::assertSame(0, $again['counters']['assignmentsCreated']);
        self::assertFalse($again['replacedAssignments'][0]['applied']);
    }

    public function test_conflicting_parent_is_not_overwritten(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('RCA', 'RCA'));
        $this->setParent('RCA', 'BV');
        $result = $this->apply->apply(
            $this->actor,
            true,
            true,
            [],
            [['parentStyleCode' => 'RC', 'childStyleCode' => 'RCA']],
            [],
            [],
        );
        self::assertSame('CONFLICT', $result['parentLinks'][0]['result']);
        self::assertSame('BV', $this->styles->findByStyleCode('RCA')?->parentStyleCode);
    }

    public function test_does_not_suffix_duplicate_style_codes(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BHA', 'BHA'));
        $result = $this->apply->apply($this->actor, true, true, ['BHA'], [], [], []);
        self::assertSame('ALREADY_EXISTS', $result['createdStyles'][0]['result']);
        self::assertNull($this->styles->findByStyleCode('BHA-2'));
        self::assertNull($this->styles->findByStyleCode('BHA-NEW'));
        self::assertCount(1, array_filter($this->styles->listAll(false), static fn ($s) => $s->styleCode === 'BHA'));
    }

    private function setParent(string $childCode, string $parentCode): void
    {
        $child = $this->styles->findByStyleCode($childCode, false);
        $parent = $this->styles->findByStyleCode($parentCode, false);
        self::assertNotNull($child);
        self::assertNotNull($parent);
        $this->manage->changeParent($this->actor, new ChangeProductStyleParentCommand(
            stylePublicId: $child->publicId,
            parentPublicId: $parent->publicId,
            expectedRowVersion: $child->rowVersion,
        ));
    }
}
