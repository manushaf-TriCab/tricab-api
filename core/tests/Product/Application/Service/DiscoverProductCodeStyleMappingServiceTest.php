<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\ProductCodeStyleMappingRow;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\Service\ApplyProductCodeStyleMappingService;
use TriCab\Core\Product\Application\Service\AssignProductStyleService;
use TriCab\Core\Product\Application\Service\CreateProductService;
use TriCab\Core\Product\Application\Service\DiscoverProductCodeStyleMappingService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStyleMappingIdentitySource;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class DiscoverProductCodeStyleMappingServiceTest extends TestCase
{
    private const P_C1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0101';
    private const P_F12 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0102';
    private const P_BHA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0103';
    private const P_RCA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0104';
    private const P_BVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0105';
    private const P_DBG = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0106';
    private const P_UNK = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0107';
    private const P_CASE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0108';
    private const P_NAME = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0109';
    private const P_SHARE_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0110';
    private const P_SHARE_B = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0111';
    private const P_NATIVE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0112';

    private InMemoryProductStyleRepository $styles;
    private InMemoryProductStyleMappingIdentitySource $identity;
    private DiscoverProductCodeStyleMappingService $discover;
    private ManageProductStyleService $manage;
    private AssignProductStyleService $assign;
    private ApplyProductCodeStyleMappingService $apply;
    private InMemoryProductStore $store;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->identity = new InMemoryProductStyleMappingIdentitySource(azureReadCount: 1);
        $this->store = new InMemoryProductStore();
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $audit = new ProductAuditRecorder($this->store->audit());
        $tx = new PassthroughApplicationTransaction();
        $this->manage = new ManageProductStyleService($this->styles, $this->store->specifications(), $tx, $audit);
        $this->assign = new AssignProductStyleService($this->styles, $this->store->adminQuery(), $tx, $audit);
        $this->apply = new ApplyProductCodeStyleMappingService($this->manage, $this->assign, $this->styles, $tx);
        $this->discover = new DiscoverProductCodeStyleMappingService($this->styles, $this->identity);
        foreach (['C1', 'F12', 'F1', 'RCA', 'BVA', 'BVC', 'BVM', 'BVP', 'BVA-X', 'UPA'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
        }
    }

    public function test_product_code_exact_style_match(): void
    {
        $this->identity->seed($this->snap(self::P_C1, 'Flexible Control', 'C1'));
        $row = $this->row($this->discover->dryRun(), self::P_C1);
        self::assertSame('EXACT_STYLE_MATCH', $row->decision);
        self::assertSame('C1', $row->expectedStyleCode);
        self::assertTrue($row->styleExists);
        self::assertSame('PROPOSE_ASSIGN', $row->action);
        self::assertSame('NOT_APPLIED', $row->applicationState);
        self::assertCount(1, $this->discover->dryRun()['existingStyleAssignmentCandidates']);
    }

    public function test_case_and_trim_canonicalisation(): void
    {
        $this->identity->seed($this->snap(self::P_CASE, 'Trimmed', ' rca '));
        $row = $this->row($this->discover->dryRun(), self::P_CASE);
        self::assertSame(' rca ', $row->rawProductCode);
        self::assertSame('RCA', $row->canonicalProductCode);
        self::assertSame('EXACT_STYLE_MATCH', $row->decision);
    }

    public function test_missing_product_style_is_root_local(): void
    {
        $this->identity->seed($this->snap(self::P_BHA, 'Shipboard Fire Resistant', 'BHA'));
        $row = $this->row($this->discover->dryRun(), self::P_BHA);
        self::assertSame('MISSING_PRODUCT_STYLE', $row->decision);
        self::assertSame('BHA', $row->expectedStyleCode);
        self::assertFalse($row->styleExists);
        self::assertSame('BHA', $row->proposedStyle['styleCode'] ?? null);
        self::assertSame('BHA', $row->proposedStyle['displayName'] ?? null);
        self::assertArrayHasKey('parentStyleId', $row->proposedStyle ?? []);
        self::assertNull($row->proposedStyle['parentStyleId']);
        self::assertSame('LOCAL', $row->proposedStyle['inheritance']['SPECIFICATIONS'] ?? null);
        self::assertSame('LOCAL', $row->proposedStyle['inheritance']['IMAGES'] ?? null);
        self::assertSame('LOCAL', $row->proposedStyle['inheritance']['AR_ASSETS'] ?? null);
        self::assertSame('BHA', $row->proposedAssignment['productStyleCode'] ?? null);
        self::assertNull($this->styles->findByStyleCode('BHA'));
    }

    public function test_exact_child_code_never_falls_back_to_parent_or_prefix(): void
    {
        $this->identity->seed($this->snap(self::P_F12, 'Inspection Tee', 'F12'));
        $row = $this->row($this->discover->dryRun(), self::P_F12);
        self::assertSame('EXACT_STYLE_MATCH', $row->decision);
        self::assertSame('F12', $row->expectedStyleCode);
        self::assertNotSame('F1', $row->expectedStyleCode);
    }

    public function test_missing_f12_does_not_propose_parent_f1(): void
    {
        $f12 = $this->styles->findByStyleCode('F12');
        self::assertNotNull($f12);
        $this->styles->softDelete($f12->publicId, $f12->rowVersion, UserId::fromInt(1));
        $this->identity->seed($this->snap(self::P_F12, 'Inspection Tee', 'F12'));
        $row = $this->row($this->discover->dryRun(), self::P_F12);
        self::assertSame('MISSING_PRODUCT_STYLE', $row->decision);
        self::assertSame('F12', $row->expectedStyleCode);
        self::assertSame('F12', $row->proposedStyle['styleCode'] ?? null);
        self::assertArrayHasKey('parentStyleId', $row->proposedStyle ?? []);
        self::assertNull($row->proposedStyle['parentStyleId']);
        self::assertNotSame('F1', $row->expectedStyleCode);
    }

    public function test_no_product_name_fallback(): void
    {
        $this->identity->seed($this->snap(self::P_NAME, 'Flexible Switchboard Cable UPA', 'F12'));
        $row = $this->row($this->discover->dryRun(), self::P_NAME);
        self::assertSame('F12', $row->expectedStyleCode);
        self::assertNotSame('UPA', $row->expectedStyleCode);
    }

    public function test_rca_assigned_to_bva_is_conflict(): void
    {
        $bva = $this->styles->findByStyleCode('BVA');
        self::assertNotNull($bva);
        $this->identity->seed($this->snap(self::P_RCA, 'Flexible Instrumentation Coll. Scr', 'RCA', assigned: 'BVA', assignedId: $bva->publicId));
        $row = $this->row($this->discover->dryRun(), self::P_RCA);
        self::assertSame('ASSIGNMENT_CONFLICT', $row->decision);
        self::assertSame('RCA', $row->expectedStyleCode);
        self::assertSame('BVA', $row->currentAssignedStyleCode);
        self::assertTrue($row->expectedStyleExists);
        self::assertFalse($row->applyEligible);
        self::assertSame([], $this->discover->dryRun()['existingStyleAssignmentCandidates']);
        self::assertCount(1, $this->discover->dryRun()['assignmentConflicts']);
        self::assertSame([], $this->discover->dryRun()['assignmentAfterCreateCandidates']);
    }

    public function test_rca_conflict_with_missing_style_is_create_then_replace_not_new_assign(): void
    {
        $rca = $this->styles->findByStyleCode('RCA');
        self::assertNotNull($rca);
        $this->styles->softDelete($rca->publicId, $rca->rowVersion, UserId::fromInt(1));
        $bva = $this->styles->findByStyleCode('BVA');
        self::assertNotNull($bva);
        $this->identity->seed($this->snap(self::P_RCA, 'Flexible Instrumentation Coll. Scr', 'RCA', assigned: 'BVA', assignedId: $bva->publicId));
        $report = $this->discover->dryRun();
        $row = $this->row($report, self::P_RCA);
        self::assertSame('ASSIGNMENT_CONFLICT', $row->decision);
        self::assertFalse($row->expectedStyleExists);
        self::assertSame('RCA', $row->proposedStyle['styleCode'] ?? null);
        self::assertCount(1, $report['missingStyleCreationCandidates']);
        self::assertSame('RCA', $report['missingStyleCreationCandidates'][0]->canonicalProductCode);
        self::assertSame([], $report['assignmentAfterCreateCandidates']);
        self::assertCount(1, $report['assignmentConflicts']);
        self::assertSame([], $report['existingStyleAssignmentCandidates']);
    }

    public function test_already_assigned_same(): void
    {
        $c1 = $this->styles->findByStyleCode('C1');
        self::assertNotNull($c1);
        $this->identity->seed($this->snap(self::P_C1, 'Flexible Control', 'C1', assigned: 'C1', assignedId: $c1->publicId));
        $row = $this->row($this->discover->dryRun(), self::P_C1);
        self::assertSame('ALREADY_ASSIGNED_SAME', $row->decision);
    }

    public function test_bva_product_code_is_independent_of_smoke_hierarchy(): void
    {
        $this->identity->seed($this->snap(self::P_BVA, 'Flexible Control LSZH', 'BVA'));
        $row = $this->row($this->discover->dryRun(), self::P_BVA);
        self::assertSame('EXACT_STYLE_MATCH', $row->decision);
        self::assertSame('BVA', $row->expectedStyleCode);
        self::assertNull($this->styles->findByStyleCode('BVA')?->parentPublicId);
    }

    public function test_debug_and_native_are_not_applicable(): void
    {
        $this->identity->seed($this->snap(self::P_DBG, 'Dbg Markets', null));
        $this->identity->seed($this->snap(self::P_NATIVE, 'Phase85 Flex Cable', 'TC-FLEX3512F7F5FA5B'));
        $report = $this->discover->dryRun();
        self::assertSame('NOT_APPLICABLE', $this->row($report, self::P_DBG)->decision);
        self::assertSame('DEBUG_TEST_PRODUCT', $this->row($report, self::P_DBG)->population);
        self::assertSame('NOT_APPLICABLE', $this->row($report, self::P_NATIVE)->decision);
        self::assertSame('TARGET_NATIVE_PRODUCT', $this->row($report, self::P_NATIVE)->population);
    }

    public function test_unknown_goes_to_review(): void
    {
        $this->identity->seed($this->snap(self::P_UNK, 'Mystery Product', null));
        $row = $this->row($this->discover->dryRun(), self::P_UNK);
        self::assertSame('UNKNOWN', $row->population);
        self::assertSame('REVIEW', $row->decision);
    }

    public function test_multiple_products_may_share_one_existing_style(): void
    {
        $this->identity->seed($this->snap(self::P_SHARE_A, 'One', 'C1'));
        $this->identity->seed($this->snap(self::P_SHARE_B, 'Two', 'C1'));
        $report = $this->discover->dryRun();
        self::assertSame(2, $report['counters']['exactStyleMatch']);
        self::assertCount(1, array_unique(array_map(
            static fn (ProductCodeStyleMappingRow $r): string => (string) $r->expectedStyleCode,
            $report['existingStyleAssignmentCandidates'],
        )));
    }

    public function test_missing_style_is_not_created_as_suffix_variant(): void
    {
        $this->identity->seed($this->snap(self::P_BHA, 'Shipboard', 'BHA'));
        $row = $this->row($this->discover->dryRun(), self::P_BHA);
        self::assertSame('BHA', $row->proposedStyle['styleCode'] ?? null);
        self::assertNotSame('BHA-2', $row->proposedStyle['styleCode'] ?? null);
        self::assertNull($this->styles->findByStyleCode('BHA-NEW'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->identity->seed($this->snap(self::P_C1, 'Flexible Control', 'C1'));
        $this->identity->seed($this->snap(self::P_BHA, 'Shipboard', 'BHA'));
        $before = count($this->styles->listAll(false));
        $report = $this->discover->dryRun();
        self::assertSame(0, $report['writeGuard']['productStyleWrites']);
        self::assertSame(0, $report['writeGuard']['productAssignmentWrites']);
        self::assertSame(0, $report['writeGuard']['parentStyleIdWrites']);
        self::assertSame(0, $report['writeGuard']['productWrites']);
        self::assertSame($before, count($this->styles->listAll(false)));
        self::assertNull($this->styles->findByStyleCode('BHA'));
        self::assertNull($this->styles->findAssignmentByProductPublicId(self::P_C1));
    }

    public function test_apply_requires_confirmed_and_explicit_lists(): void
    {
        try {
            $this->apply->apply($this->actor, false, [['styleCode' => 'BHA']], [], []);
            self::fail('Expected confirmation exception');
        } catch (InvalidProductValue) {
            self::assertNull($this->styles->findByStyleCode('BHA'));
        }
        $empty = $this->apply->apply($this->actor, true, [], [], []);
        self::assertSame([], $empty['createdStyles']);
        self::assertNull($this->styles->findByStyleCode('BHA'));
    }

    public function test_in_memory_apply_creates_root_local_and_is_idempotent_on_duplicate_style(): void
    {
        $created = $this->apply->apply($this->actor, true, [['styleCode' => 'BHA']], [], []);
        self::assertTrue($created['createdStyles'][0]['applied']);
        $style = $this->styles->findByStyleCode('BHA');
        self::assertNotNull($style);
        self::assertNull($style->parentPublicId);
        self::assertSame('LOCAL', $style->inheritance->specifications);
        $again = $this->apply->apply($this->actor, true, [['styleCode' => 'BHA']], [], []);
        self::assertFalse($again['createdStyles'][0]['applied']);
        self::assertCount(1, array_filter($this->styles->listAll(false), static fn ($s) => $s->styleCode === 'BHA'));
    }

    public function test_replace_requires_from_style_match(): void
    {
        $create = new CreateProductService($this->store->products(), $this->store->classification(), new PassthroughApplicationTransaction(), new ProductAuditRecorder($this->store->audit()));
        $product = $create->execute($this->actor, new CreateProductCommand(productName: 'RCA product'));
        $bva = $this->styles->findByStyleCode('BVA');
        $rca = $this->styles->findByStyleCode('RCA');
        self::assertNotNull($bva);
        self::assertNotNull($rca);
        $this->styles->assignProduct($product->publicId, $bva->publicId, UserId::fromInt(1));
        $wrong = $this->assign->replaceSelected($this->actor, [[
            'productPublicId' => $product->publicId,
            'fromStyleCode' => 'C1',
            'toStyleCode' => 'RCA',
        ]], true);
        self::assertFalse($wrong[0]['applied']);
        self::assertSame('BVA', $this->styles->findAssignmentByProductPublicId($product->publicId)?->styleCode);
        $ok = $this->assign->replaceSelected($this->actor, [[
            'productPublicId' => $product->publicId,
            'fromStyleCode' => 'BVA',
            'toStyleCode' => 'RCA',
        ]], true);
        self::assertTrue($ok[0]['applied']);
        self::assertSame('RCA', $this->styles->findAssignmentByProductPublicId($product->publicId)?->styleCode);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function row(array $report, string $productPublicId): ProductCodeStyleMappingRow
    {
        foreach ($report['rows'] as $row) {
            if ($row->productPublicId === $productPublicId) {
                return $row;
            }
        }
        self::fail('Missing mapping row for ' . $productPublicId);
    }

    private function snap(
        string $id,
        string $name,
        ?string $code,
        ?string $assigned = null,
        ?string $assignedId = null,
    ): ProductStyleMappingSnapshot {
        return new ProductStyleMappingSnapshot(
            productPublicId: $id,
            productName: $name,
            productCode: $code,
            legacyProductId: $code,
            statusCode: 'draft',
            isActive: true,
            isArchived: false,
            assignedStylePublicId: $assignedId,
            assignedStyleCode: $assigned,
            sourceKey: null,
            canonicalRawStyleCode: null,
            wordpressRawStyleCode: null,
            tcRawStyleCode: null,
            internalCode: $code,
        );
    }
}
