<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\Command\ProductStyleStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingRow;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\DTO\UnresolvedLegacyStyleProduct;
use TriCab\Core\Product\Application\Service\AssignProductStyleService;
use TriCab\Core\Product\Application\Service\CreateProductService;
use TriCab\Core\Product\Application\Service\DiscoverExistingProductStyleMappingService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStyleMappingIdentitySource;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class DiscoverExistingProductStyleMappingServiceTest extends TestCase
{
    private const P_UPA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0001';
    private const P_F12 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0002';
    private const P_NONE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0003';
    private const P_NATIVE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0004';
    private const P_NUM = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0005';
    private const P_CABLE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0006';
    private const P_MULTI = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0007';
    private const P_AMBIG = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0008';
    private const P_CONFLICT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0009';
    private const P_SAME = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0010';
    private const P_MISSING = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0011';
    private const P_NAME = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0012';
    private const P_VAR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0013';
    private const P_CASE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0014';

    private InMemoryProductStyleRepository $styles;
    private InMemoryProductStyleMappingIdentitySource $identity;
    private DiscoverExistingProductStyleMappingService $discover;
    private ManageProductStyleService $manage;
    private InMemoryProductStore $store;
    private AssignProductStyleService $assign;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->identity = new InMemoryProductStyleMappingIdentitySource(wordpressReadCount: 1, azureReadCount: 2);
        $this->store = new InMemoryProductStore();
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $audit = new ProductAuditRecorder($this->store->audit());
        $tx = new PassthroughApplicationTransaction();
        $this->manage = new ManageProductStyleService($this->styles, $this->store->specifications(), $tx, $audit);
        $this->assign = new AssignProductStyleService($this->styles, $this->store->adminQuery(), $tx, $audit);
        $this->discover = new DiscoverExistingProductStyleMappingService($this->styles, $this->identity);
        foreach (['UPA', 'F12', 'F1', 'H7', 'CABLE_STYLE', 'PS'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
        }
    }

    public function test_exact_style_match_is_safe_candidate(): void
    {
        $this->identity->seed($this->snap(self::P_UPA, 'Flexible Switchboard', 'UPA', assigned: null));
        $row = $this->row($this->discover->dryRun(), self::P_UPA);
        self::assertSame('EXACT_MATCH', $row->decision);
        self::assertSame('UPA', $row->matchedStyleCode);
        self::assertTrue($row->applyEligible);
        self::assertSame('NOT_APPLIED', $row->applicationState);
        self::assertCount(1, $this->discover->dryRun()['safeAssignmentCandidates']);
        self::assertNull($this->styles->findAssignmentByProductPublicId(self::P_UPA));
    }

    public function test_case_and_trim_canonicalisation(): void
    {
        $this->identity->seed($this->snap(self::P_CASE, 'Trimmed', ' upa ', assigned: null));
        $row = $this->row($this->discover->dryRun(), self::P_CASE);
        self::assertSame('EXACT_MATCH', $row->decision);
        self::assertSame('UPA', $row->canonicalStyleCode);
        self::assertSame(' upa ', $row->rawLegacyStyleCode);
    }

    public function test_exact_child_style_is_preserved_never_parent(): void
    {
        $this->identity->seed($this->snap(self::P_F12, 'Inspection Tee', 'F12', assigned: null));
        $row = $this->row($this->discover->dryRun(), self::P_F12);
        self::assertSame('EXACT_MATCH', $row->decision);
        self::assertSame('F12', $row->matchedStyleCode);
        self::assertNotSame('F1', $row->matchedStyleCode);
    }

    public function test_no_prefix_fallback(): void
    {
        $this->identity->seed($this->snap(self::P_MISSING, 'Unknown child', 'F99', assigned: null));
        $row = $this->row($this->discover->dryRun(), self::P_MISSING);
        self::assertSame('STYLE_NOT_FOUND', $row->decision);
        self::assertSame('F99', $row->canonicalStyleCode);
        self::assertNotSame('F1', $row->matchedStyleCode);
        self::assertFalse($row->applyEligible);
    }

    public function test_no_name_only_fallback(): void
    {
        $this->identity->seed(new ProductStyleMappingSnapshot(
            productPublicId: self::P_NAME,
            productName: 'Flexible Switchboard Cable UPA',
            productCode: 'NAME-ONLY',
            legacyProductId: '99',
            statusCode: 'draft',
            isActive: true,
            isArchived: false,
            assignedStylePublicId: null,
            assignedStyleCode: null,
            sourceKey: 'wordpress:wp_posts:99',
            canonicalRawStyleCode: null,
            wordpressRawStyleCode: null,
            tcRawStyleCode: null,
            variantRawStyleCodes: [],
            legacyTitle: 'Flexible Switchboard Cable UPA',
            legacyKind: 'cable',
            matchMethod: null,
            sourcesAvailable: ['wordpress'],
        ));
        $row = $this->row($this->discover->dryRun(), self::P_NAME);
        self::assertSame('NO_STYLE', $row->decision);
        self::assertNull($row->matchedStyleCode);
    }

    public function test_legacy_product_id_matching_style_code_is_not_an_assignment(): void
    {
        $this->identity->seed($this->snap(self::P_NONE, 'Shipboard', null, assigned: null, legacyId: 'UPA'));
        $row = $this->row($this->discover->dryRun(), self::P_NONE);
        self::assertSame('NO_STYLE', $row->decision);
        self::assertNull($row->matchedStyleCode);
        self::assertFalse($row->applyEligible);
        self::assertNotSame([], array_filter(
            $row->warnings,
            static fn (string $warning): bool => str_contains($warning, 'LegacyProductId equals StyleCode UPA'),
        ));
    }

    public function test_style_not_found_and_no_style(): void
    {
        $this->identity->seed($this->snap(self::P_MISSING, 'Missing', 'ZZZ9', assigned: null));
        $this->identity->seed($this->snap(self::P_NONE, 'Blank', null, assigned: null, legacyId: '12'));
        $report = $this->discover->dryRun();
        self::assertSame('STYLE_NOT_FOUND', $this->row($report, self::P_MISSING)->decision);
        self::assertSame('NO_STYLE', $this->row($report, self::P_NONE)->decision);
    }

    public function test_numeric_and_cable_style_and_multi_value_are_review(): void
    {
        $this->identity->seed($this->snap(self::P_NUM, 'Numeric', '10', assigned: null));
        $this->identity->seed($this->snap(self::P_CABLE, 'Header', 'CABLE_STYLE', assigned: null));
        $this->identity->seed($this->snap(self::P_MULTI, 'Multi', 'B3C, B3M', assigned: null));
        $report = $this->discover->dryRun();
        self::assertSame('REVIEW', $this->row($report, self::P_NUM)->decision);
        self::assertTrue($this->row($report, self::P_NUM)->numericStyle);
        self::assertSame('REVIEW', $this->row($report, self::P_CABLE)->decision);
        self::assertTrue($this->row($report, self::P_CABLE)->cableStyleOccurrence);
        self::assertSame('REVIEW', $this->row($report, self::P_MULTI)->decision);
        self::assertTrue($this->row($report, self::P_MULTI)->multiValueStyle);
        self::assertSame([], $report['safeAssignmentCandidates']);
    }

    public function test_source_disagreement_and_variant_disagreement(): void
    {
        $this->identity->seed(new ProductStyleMappingSnapshot(
            productPublicId: self::P_AMBIG,
            productName: 'Disagree',
            productCode: 'D1',
            legacyProductId: '7',
            statusCode: 'draft',
            isActive: true,
            isArchived: false,
            assignedStylePublicId: null,
            assignedStyleCode: null,
            sourceKey: 'wordpress:wp_posts:7',
            canonicalRawStyleCode: 'ABC',
            wordpressRawStyleCode: 'XYZ',
            tcRawStyleCode: null,
            variantRawStyleCodes: [],
        ));
        $this->identity->seed(new ProductStyleMappingSnapshot(
            productPublicId: self::P_VAR,
            productName: 'Variants',
            productCode: 'V1',
            legacyProductId: '8',
            statusCode: 'draft',
            isActive: true,
            isArchived: false,
            assignedStylePublicId: null,
            assignedStyleCode: null,
            sourceKey: 'wordpress:wp_posts:8',
            canonicalRawStyleCode: 'F12',
            wordpressRawStyleCode: 'F12',
            tcRawStyleCode: null,
            variantRawStyleCodes: ['F12', 'F1'],
        ));
        $report = $this->discover->dryRun();
        self::assertSame('AMBIGUOUS_SOURCE', $this->row($report, self::P_AMBIG)->decision);
        self::assertSame('AMBIGUOUS_SOURCE', $this->row($report, self::P_VAR)->decision);
    }

    public function test_already_assigned_same_and_conflict(): void
    {
        $upa = $this->styles->findByStyleCode('UPA');
        $f1 = $this->styles->findByStyleCode('F1');
        self::assertNotNull($upa);
        self::assertNotNull($f1);
        $this->styles->assignProduct(self::P_SAME, $upa->publicId, \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1));
        $this->styles->assignProduct(self::P_CONFLICT, $f1->publicId, \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1));
        $this->identity->seed($this->snap(self::P_SAME, 'Same', 'UPA', assigned: 'UPA', assignedId: $upa->publicId));
        $this->identity->seed($this->snap(self::P_CONFLICT, 'Conflict', 'F12', assigned: 'F1', assignedId: $f1->publicId));
        $report = $this->discover->dryRun();
        self::assertSame('ALREADY_ASSIGNED_SAME', $this->row($report, self::P_SAME)->decision);
        self::assertSame('ALREADY_ASSIGNED_CONFLICT', $this->row($report, self::P_CONFLICT)->decision);
        self::assertSame('F12', $this->row($report, self::P_CONFLICT)->matchedStyleCode);
        self::assertSame('F1', $this->row($report, self::P_CONFLICT)->currentAssignedStyleCode);
    }

    public function test_native_product_is_not_applicable(): void
    {
        $this->identity->seed(new ProductStyleMappingSnapshot(
            productPublicId: self::P_NATIVE,
            productName: 'Native',
            productCode: 'N1',
            legacyProductId: null,
            statusCode: 'draft',
            isActive: true,
            isArchived: false,
            assignedStylePublicId: null,
            assignedStyleCode: null,
            sourceKey: null,
            canonicalRawStyleCode: null,
            wordpressRawStyleCode: null,
            tcRawStyleCode: null,
        ));
        $row = $this->row($this->discover->dryRun(), self::P_NATIVE);
        self::assertSame('NOT_APPLICABLE', $row->decision);
        self::assertSame('NATIVE_TARGET', $row->origin);
    }

    public function test_unresolved_legacy_is_separate_from_target_population(): void
    {
        $this->identity->seed($this->snap(self::P_UPA, 'UPA product', 'UPA', assigned: null));
        $this->identity->seedUnresolved(new UnresolvedLegacyStyleProduct(
            sourceKey: 'wordpress:wp_posts:500',
            rawStyleCode: 'H7',
            title: 'Orphan',
            kind: 'cable',
            reason: 'Canonical product has no matching target Product.',
        ));
        $report = $this->discover->dryRun();
        self::assertSame(1, $report['counters']['totalTargetProducts']);
        self::assertSame(1, $report['counters']['unresolvedLegacyProducts']);
        self::assertSame(0, $report['counters']['productIdentityUnresolved']);
        self::assertSame(1, $report['counters']['exactMatch'] + $report['counters']['notApplicable'] + $report['counters']['noStyle'] + $report['counters']['review'] + $report['counters']['styleNotFound'] + $report['counters']['ambiguousSource'] + $report['counters']['alreadyAssignedSame'] + $report['counters']['alreadyAssignedConflict'] + $report['counters']['productIdentityUnresolved']);
    }

    public function test_dry_run_creates_zero_writes(): void
    {
        $this->identity->seed($this->snap(self::P_UPA, 'UPA product', 'UPA', assigned: null));
        $beforeParents = 0;
        foreach ($this->styles->listAll(false) as $style) {
            if ($style->parentPublicId !== null) {
                ++$beforeParents;
            }
        }
        $report = $this->discover->dryRun();
        self::assertSame(0, $report['writeGuard']['productAssignmentWrites']);
        self::assertSame(0, $report['writeGuard']['parentStyleIdWrites']);
        self::assertSame(0, $report['writeGuard']['productWrites']);
        self::assertSame(0, $report['writeGuard']['variantWrites']);
        self::assertSame(0, $report['writeGuard']['specificationWrites']);
        self::assertSame(0, $report['writeGuard']['mediaWrites']);
        self::assertSame(0, $report['writeGuard']['blobWrites']);
        self::assertNull($this->styles->findAssignmentByProductPublicId(self::P_UPA));
        $afterParents = 0;
        foreach ($this->styles->listAll(false) as $style) {
            if ($style->parentPublicId !== null) {
                ++$afterParents;
            }
        }
        self::assertSame($beforeParents, $afterParents);
        self::assertGreaterThan(0, $this->identity->listCalls);
    }

    public function test_safe_candidates_exclude_review_and_conflicts(): void
    {
        $this->identity->seed($this->snap(self::P_UPA, 'UPA', 'UPA', assigned: null));
        $this->identity->seed($this->snap(self::P_NUM, 'Numeric', '10', assigned: null));
        $f1 = $this->styles->findByStyleCode('F1');
        self::assertNotNull($f1);
        $this->identity->seed($this->snap(self::P_CONFLICT, 'Conflict', 'F12', assigned: 'F1', assignedId: $f1->publicId));
        $codes = array_map(static fn (ProductStyleMappingRow $r): string => $r->productPublicId, $this->discover->dryRun()['safeAssignmentCandidates']);
        self::assertSame([self::P_UPA], $codes);
    }

    public function test_apply_requires_confirmed_and_explicit_list(): void
    {
        $created = (new CreateProductService($this->store->products(), $this->store->classification(), new PassthroughApplicationTransaction(), new ProductAuditRecorder($this->store->audit())))
            ->execute($this->actor, new CreateProductCommand(productName: 'Widget', legacyProductId: '1'));
        $upa = $this->styles->findByStyleCode('UPA');
        self::assertNotNull($upa);
        try {
            $this->assign->applySelected($this->actor, [
                ['productPublicId' => $created->publicId, 'productStylePublicId' => $upa->publicId],
            ], false);
            self::fail('Expected confirmation exception');
        } catch (InvalidProductValue $exception) {
            self::assertStringContainsString('confirmed', $exception->getMessage());
        }
        self::assertNull($this->styles->findAssignmentByProductPublicId($created->publicId));
        $empty = $this->assign->applySelected($this->actor, [], true);
        self::assertSame([], $empty);
        self::assertNull($this->styles->findAssignmentByProductPublicId($created->publicId));
    }

    public function test_apply_selected_list_idempotent_conflict_and_audit(): void
    {
        $create = new CreateProductService($this->store->products(), $this->store->classification(), new PassthroughApplicationTransaction(), new ProductAuditRecorder($this->store->audit()));
        $first = $create->execute($this->actor, new CreateProductCommand(productName: 'One'));
        $second = $create->execute($this->actor, new CreateProductCommand(productName: 'Two'));
        $upa = $this->styles->findByStyleCode('UPA');
        $f12 = $this->styles->findByStyleCode('F12');
        self::assertNotNull($upa);
        self::assertNotNull($f12);
        $applied = $this->assign->applySelected($this->actor, [
            ['productPublicId' => $first->publicId, 'productStylePublicId' => $upa->publicId],
        ], true);
        self::assertTrue($applied[0]['applied']);
        self::assertSame('UPA', $this->styles->findAssignmentByProductPublicId($first->publicId)?->styleCode);
        self::assertNull($this->styles->findAssignmentByProductPublicId($second->publicId));
        $same = $this->assign->applySelected($this->actor, [
            ['productPublicId' => $first->publicId, 'productStylePublicId' => $upa->publicId],
        ], true);
        self::assertFalse($same[0]['applied']);
        self::assertStringContainsString('ALREADY_ASSIGNED_SAME', $same[0]['reason']);
        $conflict = $this->assign->applySelected($this->actor, [
            ['productPublicId' => $first->publicId, 'productStylePublicId' => $f12->publicId],
        ], true);
        self::assertFalse($conflict[0]['applied']);
        self::assertStringContainsString('ALREADY_ASSIGNED_CONFLICT', $conflict[0]['reason']);
        self::assertSame('UPA', $this->styles->findAssignmentByProductPublicId($first->publicId)?->styleCode);
        $actions = array_map(static fn ($e) => $e->actionCode, $this->store->auditEntries());
        self::assertContains('assigned', $actions);
        $missing = $this->assign->applySelected($this->actor, [
            ['productPublicId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'productStylePublicId' => $upa->publicId],
        ], true);
        self::assertFalse($missing[0]['applied']);
        $missingStyle = $this->assign->applySelected($this->actor, [
            ['productPublicId' => $first->publicId, 'productStylePublicId' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
        ], true);
        self::assertFalse($missingStyle[0]['applied']);
        self::assertStringContainsString('ProductStyle was not found', $missingStyle[0]['reason']);

        $inactive = $this->manage->create($this->actor, new UpsertProductStyleCommand('ZZINACT', 'ZZINACT'));
        $this->manage->deactivate($this->actor, new ProductStyleStatusCommand($inactive->publicId, $inactive->rowVersion));
        $rejectedInactive = $this->assign->applySelected($this->actor, [
            ['productPublicId' => $second->publicId, 'productStylePublicId' => $inactive->publicId],
        ], true);
        self::assertFalse($rejectedInactive[0]['applied']);
        self::assertStringContainsString('inactive', $rejectedInactive[0]['reason']);
        self::assertNull($this->styles->findAssignmentByProductPublicId($second->publicId));
    }

    /**
     * @param array<string, mixed> $report
     */
    private function row(array $report, string $productPublicId): ProductStyleMappingRow
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
        ?string $style,
        ?string $assigned,
        ?string $assignedId = null,
        ?string $legacyId = '1',
    ): ProductStyleMappingSnapshot {
        return new ProductStyleMappingSnapshot(
            productPublicId: $id,
            productName: $name,
            productCode: strtoupper($name),
            legacyProductId: $legacyId,
            statusCode: 'draft',
            isActive: true,
            isArchived: false,
            assignedStylePublicId: $assignedId,
            assignedStyleCode: $assigned,
            sourceKey: $legacyId !== null ? 'wordpress:wp_posts:' . $legacyId : null,
            canonicalRawStyleCode: $style,
            wordpressRawStyleCode: $style,
            tcRawStyleCode: null,
        );
    }
}
