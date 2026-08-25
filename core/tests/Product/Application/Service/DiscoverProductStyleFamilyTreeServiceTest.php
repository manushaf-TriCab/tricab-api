<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\Service\AssignProductStyleService;
use TriCab\Core\Product\Application\Service\DiscoverProductCodeStyleMappingService;
use TriCab\Core\Product\Application\Service\DiscoverProductStyleFamilyTreeService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStyleFamilyEvidenceSource;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStyleMappingIdentitySource;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class DiscoverProductStyleFamilyTreeServiceTest extends TestCase
{
    private const P_RCA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0201';
    private const P_RCP = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0202';
    private const P_RCM = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0203';
    private const P_RCC = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0204';
    private const P_BVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0205';
    private const P_BVC = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0206';
    private const P_BVM = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0207';
    private const P_BVP = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0208';
    private const P_DVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0209';
    private const P_DVC = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0210';
    private const P_DVM = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0211';
    private const P_F1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0212';
    private const P_F12 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0213';
    private const P_XYZ = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0214';
    private const P_C1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0215';
    private const P_C2 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaa0216';

    private InMemoryProductStyleRepository $styles;
    private InMemoryProductStyleMappingIdentitySource $identity;
    private InMemoryProductStyleFamilyEvidenceSource $evidence;
    private DiscoverProductStyleFamilyTreeService $discover;
    private ManageProductStyleService $manage;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->identity = new InMemoryProductStyleMappingIdentitySource(azureReadCount: 1);
        $this->evidence = new InMemoryProductStyleFamilyEvidenceSource();
        $store = new InMemoryProductStore();
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $audit = new ProductAuditRecorder($store->audit());
        $tx = new PassthroughApplicationTransaction();
        $this->manage = new ManageProductStyleService($this->styles, $store->specifications(), $tx, $audit);
        $assign = new AssignProductStyleService($this->styles, $store->adminQuery(), $tx, $audit);
        unset($assign);
        $mapping = new DiscoverProductCodeStyleMappingService($this->styles, $this->identity);
        $this->discover = new DiscoverProductStyleFamilyTreeService(
            $this->styles,
            $this->identity,
            $mapping,
            $this->evidence,
        );
    }

    public function test_operator_confirmed_rc_and_bv_families(): void
    {
        $this->seedConfirmedProducts();
        foreach (['BV', 'BVA', 'BVC', 'BVM', 'BVP'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
        }
        $this->setParent('BVA', 'BV');
        $this->setParent('BVC', 'BV');
        $this->setParent('BVM', 'BV');
        $this->setParent('BVP', 'BV');

        $report = $this->discover->dryRun();
        $rc = $this->family($report, 'RC');
        $bv = $this->family($report, 'BV');
        self::assertSame('OPERATOR_CONFIRMED', $rc['decision']);
        self::assertSame(['RCA', 'RCC', 'RCM', 'RCP'], $rc['childStyleCodes']);
        self::assertFalse($rc['parentExists']);
        self::assertSame('OPERATOR_CONFIRMED', $bv['decision']);
        self::assertSame(4, $report['counters']['alreadyPersistedConfirmedRelationships']);
        self::assertSame(4, $report['counters']['confirmedRelationshipsRequiringApply']);
        self::assertSame(8, $report['counters']['operatorConfirmedRelationships']);
    }

    public function test_product_rca_maps_to_rca_never_rc(): void
    {
        $this->seedConfirmedProducts();
        $map = $this->product($this->discover->dryRun(), self::P_RCA);
        self::assertSame('RCA', $map['exactProductStyle']);
        self::assertSame('RCA', $map['expectedAssignment']);
        self::assertSame('RC', $map['proposedParentStyle']);
        self::assertFalse($map['mapsToParent']);
        self::assertNotSame('RC', $map['expectedAssignment']);
    }

    public function test_product_bva_maps_to_bva_never_bv(): void
    {
        $this->seedConfirmedProducts();
        foreach (['BV', 'BVA'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
        }
        $this->setParent('BVA', 'BV');
        $map = $this->product($this->discover->dryRun(), self::P_BVA);
        self::assertSame('BVA', $map['expectedAssignment']);
        self::assertSame('BV', $map['proposedParentStyle']);
        self::assertNotSame('BV', $map['expectedAssignment']);
    }

    public function test_missing_parent_and_child_styles(): void
    {
        $this->identity->seed($this->snap(self::P_RCA, 'Flexible Instrumentation', 'RCA'));
        $report = $this->discover->dryRun();
        $parents = array_column($report['missingParentStyleCandidates'], 'styleCode');
        self::assertContains('RC', $parents);
        $rc = null;
        foreach ($report['missingParentStyleCandidates'] as $candidate) {
            if ($candidate['styleCode'] === 'RC') {
                $rc = $candidate;
            }
        }
        self::assertNotNull($rc);
        self::assertNull($rc['parentStyleId']);
        self::assertSame('LOCAL', $rc['inheritance']['SPECIFICATIONS']);
        $rcaChild = null;
        foreach ($report['missingChildStyleCandidates'] as $candidate) {
            if ($candidate['productCode'] === 'RCA') {
                $rcaChild = $candidate;
            }
        }
        self::assertNotNull($rcaChild);
        self::assertSame('RCA', $rcaChild['proposedProductStyle']);
        self::assertSame('RC', $rcaChild['proposedParent']);
        self::assertNull($this->styles->findByStyleCode('RC'));
        self::assertNull($this->styles->findByStyleCode('RCA'));
    }

    public function test_standalone_style_when_no_family(): void
    {
        $this->identity->seed($this->snap(self::P_XYZ, 'Unique Cable', 'XYZ'));
        $report = $this->discover->dryRun();
        $codes = array_column($report['standaloneStyles'], 'styleCode');
        self::assertContains('XYZ', $codes);
        self::assertNull($this->product($report, self::P_XYZ)['proposedParentStyle']);
    }

    public function test_sibling_aware_candidate_family_is_approved_without_forcing_prefix_f12(): void
    {
        $this->identity->seed($this->snap(self::P_DVA, 'Braided A', 'DVA'));
        $this->identity->seed($this->snap(self::P_DVC, 'Braided C', 'DVC'));
        $this->identity->seed($this->snap(self::P_DVM, 'Braided M', 'DVM'));
        $this->identity->seed($this->snap(self::P_F1, 'Inspection', 'F1'));
        $this->identity->seed($this->snap(self::P_F12, 'Inspection Tee', 'F12'));
        $report = $this->discover->dryRun();
        $dv = $this->family($report, 'DV');
        self::assertSame('APPROVE_CANDIDATE', $dv['decision']);
        self::assertSame(['DVA', 'DVC', 'DVM'], $dv['childStyleCodes']);
        $f12 = $this->product($report, self::P_F12);
        self::assertSame('F12', $f12['expectedAssignment']);
        self::assertNotSame('F1', $f12['proposedParentStyle']);
        foreach ($report['proposedAdditionalRelationships'] as $rel) {
            self::assertFalse($rel['parentStyleCode'] === 'F1' && $rel['childStyleCode'] === 'F12');
        }
        foreach ($report['operatorConfirmedRelationships'] as $rel) {
            self::assertFalse($rel['parentStyleCode'] === 'F1' && $rel['childStyleCode'] === 'F12');
        }
    }

    public function test_classification_conflict_stays_review(): void
    {
        $this->identity->seed($this->snap(self::P_DVA, 'One', 'DVA'));
        $this->identity->seed($this->snap(self::P_DVC, 'Two', 'DVC'));
        $this->identity->seed($this->snap(self::P_DVM, 'Three', 'DVM'));
        $this->evidence->seed(self::P_DVA, $this->ev('FAM-A', 'CAT-A'));
        $this->evidence->seed(self::P_DVC, $this->ev('FAM-B', 'CAT-B'));
        $this->evidence->seed(self::P_DVM, $this->ev('FAM-C', 'CAT-C'));
        $dv = $this->family($this->discover->dryRun(), 'DV');
        self::assertSame('REVIEW', $dv['decision']);
        self::assertSame('CLASSIFICATION_CONFLICT', $dv['classificationEvidence']);
    }

    public function test_two_letter_digit_codes_are_not_a_c_family(): void
    {
        $this->identity->seed($this->snap(self::P_C1, 'Control 1', 'C1'));
        $this->identity->seed($this->snap(self::P_C2, 'Control 2', 'C2'));
        $report = $this->discover->dryRun();
        foreach ($report['families'] as $family) {
            self::assertNotSame('C', $family['parentStyleCode']);
        }
        self::assertNull($this->product($report, self::P_C1)['proposedParentStyle']);
    }

    public function test_bva_x_is_not_operator_confirmed(): void
    {
        $this->seedConfirmedProducts();
        foreach (['BV', 'BVA', 'BVA-X'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
        }
        $this->setParent('BVA', 'BV');
        $this->setParent('BVA-X', 'BVA');
        $report = $this->discover->dryRun();
        self::assertSame('REVIEW', $report['smokeRelationships'][0]['decision']);
        self::assertSame('BVA-X', $report['smokeRelationships'][0]['childStyleCode']);
        foreach ($report['operatorConfirmedRelationships'] as $rel) {
            self::assertNotSame('BVA-X', $rel['childStyleCode']);
        }
    }

    public function test_rca_current_bva_assignment_is_conflict_and_still_maps_to_rca(): void
    {
        $bva = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA'));
        $this->identity->seed($this->snap(self::P_RCA, 'Flexible Instrumentation', 'RCA', 'BVA', $bva->publicId));
        $this->identity->seed($this->snap(self::P_BVA, 'Flexible Control LSZH', 'BVA'));
        $map = $this->product($this->discover->dryRun(), self::P_RCA);
        self::assertSame('ASSIGNMENT_CONFLICT', $map['assignmentDecision']);
        self::assertSame('RCA', $map['expectedAssignment']);
        self::assertSame('BVA', $map['currentAssignment']);
        self::assertSame('RC', $map['proposedParentStyle']);
        $bvaMap = $this->product($this->discover->dryRun(), self::P_BVA);
        self::assertSame('BVA', $bvaMap['expectedAssignment']);
    }

    public function test_cycle_from_inverted_existing_parent_is_detected(): void
    {
        $this->seedConfirmedProducts();
        foreach (['BV', 'BVA', 'BVC', 'BVM', 'BVP'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
        }
        $this->setParent('BV', 'BVA');
        $report = $this->discover->dryRun();
        self::assertGreaterThan(0, $report['cycleValidation']['directCycles'] + $report['cycleValidation']['deepCycles']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedConfirmedProducts();
        $before = count($this->styles->listAll(false));
        $report = $this->discover->dryRun();
        self::assertSame(0, $report['writeGuard']['productStyleWrites']);
        self::assertSame(0, $report['writeGuard']['parentStyleIdWrites']);
        self::assertSame(0, $report['writeGuard']['productAssignmentWrites']);
        self::assertSame(0, $report['writeGuard']['specificationWrites']);
        self::assertSame(0, $report['writeGuard']['mediaWrites']);
        self::assertSame($before, count($this->styles->listAll(false)));
        self::assertNull($this->styles->findByStyleCode('RC'));
        self::assertNull($this->styles->findAssignmentByProductPublicId(self::P_RCA));
    }

    private function seedConfirmedProducts(): void
    {
        $this->identity->seed($this->snap(self::P_RCA, 'Flexible Instrumentation', 'RCA'));
        $this->identity->seed($this->snap(self::P_RCP, 'Instrumentation P', 'RCP'));
        $this->identity->seed($this->snap(self::P_RCM, 'Instrumentation M', 'RCM'));
        $this->identity->seed($this->snap(self::P_RCC, 'Instrumentation C', 'RCC'));
        $this->identity->seed($this->snap(self::P_BVA, 'Flexible Control LSZH', 'BVA'));
        $this->identity->seed($this->snap(self::P_BVC, 'Control C', 'BVC'));
        $this->identity->seed($this->snap(self::P_BVM, 'Control M', 'BVM'));
        $this->identity->seed($this->snap(self::P_BVP, 'Control P', 'BVP'));
    }

    private function setParent(string $child, string $parent): void
    {
        $childStyle = $this->styles->findByStyleCode($child);
        $parentStyle = $this->styles->findByStyleCode($parent);
        self::assertNotNull($childStyle);
        self::assertNotNull($parentStyle);
        $this->manage->changeParent($this->actor, new ChangeProductStyleParentCommand(
            $childStyle->publicId,
            $parentStyle->publicId,
            $childStyle->rowVersion,
        ));
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function family(array $report, string $parent): array
    {
        foreach ($report['families'] as $family) {
            if ($family['parentStyleCode'] === $parent) {
                return $family;
            }
        }
        self::fail('Missing family ' . $parent);
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function product(array $report, string $productPublicId): array
    {
        foreach ($report['productMappings'] as $row) {
            if ($row['productPublicId'] === $productPublicId) {
                return $row;
            }
        }
        self::fail('Missing product mapping ' . $productPublicId);
    }

    /**
     * @return array<string, mixed>
     */
    private function ev(string $family, string $category): array
    {
        return [
            'familyCode' => $family,
            'familyName' => $family,
            'categoryCode' => $category,
            'categoryName' => $category,
            'seriesCode' => null,
            'seriesName' => null,
            'specificationDefinitionIds' => [],
            'specificationFingerprints' => [],
            'mediaKeys' => [],
        ];
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
