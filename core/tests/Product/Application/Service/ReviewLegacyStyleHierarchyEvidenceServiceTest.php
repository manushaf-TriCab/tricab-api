<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\LegacyStyleOccurrence;
use TriCab\Core\Product\Application\DTO\StyleHierarchyEvidenceRow;
use TriCab\Core\Product\Application\DTO\StyleInventorySourceStat;
use TriCab\Core\Product\Application\DTO\StyleMembershipEvidence;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleHierarchyService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleInventoryService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\ReviewLegacyStyleHierarchyEvidenceService;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryLegacyStyleOccurrenceSource;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ReviewLegacyStyleHierarchyEvidenceServiceTest extends TestCase
{
    private InMemoryProductStyleRepository $styles;
    private InMemoryLegacyStyleOccurrenceSource $source;
    private DiscoverLegacyStyleHierarchyService $discovery;
    private ReviewLegacyStyleHierarchyEvidenceService $review;
    private ManageProductStyleService $manage;
    private InMemoryProductStore $store;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->source = new InMemoryLegacyStyleOccurrenceSource(
            stats: [new StyleInventorySourceStat('wordpress', 'wp_postmeta', 'style_code', 10, 8, 5, true, true)],
            wordpressReadCount: 2,
            tcReadCount: 3,
        );
        $this->store = new InMemoryProductStore();
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $audit = new ProductAuditRecorder($this->store->audit());
        $this->manage = new ManageProductStyleService($this->styles, $this->store->specifications(), new PassthroughApplicationTransaction(), $audit);
        $inventory = new DiscoverLegacyStyleInventoryService($this->styles, $this->source);
        $this->discovery = new DiscoverLegacyStyleHierarchyService($this->styles, inventory: $inventory, manage: $this->manage);
        $this->review = new ReviewLegacyStyleHierarchyEvidenceService($this->discovery, $inventory, $this->styles, $this->source);
    }

    public function test_high_proposal_with_supporting_evidence_is_approve_candidate(): void
    {
        $this->seedPair('F1', 'F12', 'Flexible Cable', 'F1 Flexible Cable', 'F12 Flexible Cable');
        $row = $this->row($this->review->review(), 'F12');
        self::assertSame('APPROVE_CANDIDATE', $row->decision);
        self::assertTrue($row->selected);
        self::assertContains($row->reviewedConfidence, ['HIGH', 'VERY_HIGH']);
        self::assertSame(0, $this->review->review()['writeGuard']['parentStyleIdWrites']);
        self::assertNull($this->styles->findByStyleCode('F12')?->parentPublicId);
    }

    public function test_high_prefix_is_downgraded_on_classification_conflict(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('F1', 'F1'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('F12', 'F12'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'F1', 4, 4, ['Power F1'], 'Power'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'F12', 5, 5, ['Data F12'], 'Data'));
        $this->source->seedMembership($this->mem('F1', 4, ['Power Cable F1'], ['Power Cable'], ['f1.jpg']));
        $this->source->seedMembership($this->mem('F12', 5, ['Data Cable F12'], ['Data Cable'], ['f12.jpg']));
        $row = $this->row($this->review->review(), 'F12');
        self::assertSame('REJECT', $row->decision);
        self::assertSame('CLASSIFICATION_CONFLICT', $row->conflict);
        self::assertFalse($row->selected);
    }

    public function test_cable_versus_accessory_post_type_is_rejected(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('F1', 'F1'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('F12', 'F12'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'F1', 4, 4, ['F1 Flexible'], 'Food'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'F12', 5, 5, ['Inspection Tee'], 'Conduits'));
        $this->source->seedMembership($this->mem('F1', 4, ['Flexible Rubber Instrumentation'], ['Food & Beverage'], [], [], ['cable']));
        $this->source->seedMembership($this->mem('F12', 5, ['Inspection Tee'], ['Flexible Conduit'], [], [], ['accessory']));
        $row = $this->row($this->review->review(), 'F12');
        self::assertSame('REJECT', $row->decision);
        self::assertStringContainsString('post types differ', $row->classificationEvidence);
    }

    public function test_medium_prefix_promoted_with_strong_evidence(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('XX', 'XX'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('XXABCD', 'XXABCD'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'XX', 6, 6, ['XX Flexible'], 'Flexible Cable'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'XXABCD', 3, 3, ['XXABCD Flexible'], 'Flexible Cable'));
        $this->source->seedMembership($this->mem('XX', 6, ['XX Flexible Cable'], ['Flexible Cable'], [], ['volts' => ['0.6/1kV']]));
        $this->source->seedMembership($this->mem('XXABCD', 3, ['XXABCD Flexible Cable'], ['Flexible Cable'], [], ['volts' => ['0.6/1kV']]));
        $review = $this->review->review();
        $row = $this->row($review, 'XXABCD');
        self::assertSame('MEDIUM', $row->originalConfidence);
        self::assertSame('APPROVE_CANDIDATE', $row->decision);
        self::assertTrue($row->selected);
    }

    public function test_medium_without_membership_remains_review(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('YY', 'YY'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('YYABCD', 'YYABCD'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'YY', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'YYABCD', 1, 1));
        $row = $this->row($this->review->review(), 'YYABCD');
        self::assertSame('REVIEW', $row->decision);
        self::assertFalse($row->selected);
    }

    public function test_cable_style_is_suspicious_review_not_selected(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('CA', 'CA'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('CABLE_STYLE', 'CABLE_STYLE'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'CA', 4, 4, ['CA'], 'CABLE'));
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', 'CABLE_STYLE', 2, 2));
        $this->source->seedMembership($this->mem('CA', 4, ['CA Flexible'], ['Flexible'], []));
        $this->source->seedMembership($this->mem('CABLE_STYLE', 2, ['CABLE_STYLE'], [], []));
        $row = $this->row($this->review->review(), 'CABLE_STYLE');
        self::assertSame('REVIEW', $row->decision);
        self::assertFalse($row->applyEligible);
        self::assertStringContainsString('suspicious', strtolower($row->reason));
    }

    public function test_numeric_proposals_are_excluded(): void
    {
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '1', 2, 2));
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '10', 1, 1));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('1E', '1E'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', '1E', 3, 3));
        $review = $this->review->review();
        $ten = $this->row($review, '10');
        self::assertSame('EXCLUDED_REVIEW', $ten->decision);
        self::assertFalse($ten->selected);
        $oneE = $this->row($review, '1E');
        self::assertSame('EXCLUDED_REVIEW', $oneE->decision);
        self::assertNull($this->styles->findByStyleCode('1'));
    }

    public function test_ambiguous_parents_are_alternative_parent(): void
    {
        foreach (['ABC', 'ABCD', 'ABCDE'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
            $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', $code, 2, 2));
        }
        $row = $this->row($this->review->review(), 'ABCDE');
        self::assertSame('ALTERNATIVE_PARENT', $row->decision);
        self::assertFalse($row->selected);
    }

    public function test_no_evidence_is_review_not_similarity(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('B1', 'B1'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('B10', 'B10'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'B1', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'B10', 1, 1));
        $row = $this->row($this->review->review(), 'B10');
        self::assertSame('REVIEW', $row->decision);
        self::assertStringContainsString('NO_EVIDENCE', $row->specificationEvidence);
        self::assertSame('NO_MEDIA_EVIDENCE: no legacy attachments associated', $row->mediaEvidence);
    }

    public function test_ps_outliers_are_review_even_if_prefix_matches(): void
    {
        foreach (['PS', 'PS1A', 'PS1B', 'PS3C', 'PS6'] as $code) {
            $this->manage->create($this->actor, new UpsertProductStyleCommand($code, $code));
            $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', $code, 4, 4, [$code . ' Flexible'], 'Flexible Cable'));
            $this->source->seedMembership($this->mem($code, 4, [$code . ' Flexible Cable'], ['Flexible Cable'], [], ['volts' => ['0.6/1kV']]));
        }
        $review = $this->review->review();
        self::assertSame('APPROVE_CANDIDATE', $this->row($review, 'PS1A')->decision);
        self::assertSame('REVIEW', $this->row($review, 'PS3C')->decision);
        self::assertSame('REVIEW', $this->row($review, 'PS6')->decision);
        $selectedCodes = array_map(static fn (StyleHierarchyEvidenceRow $r): string => $r->childStyleCode, $review['selected']);
        self::assertContains('PS1A', $selectedCodes);
        self::assertNotContains('PS3C', $selectedCodes);
    }

    public function test_review_performs_zero_writes(): void
    {
        $this->seedPair('F1', 'F12', 'Flexible Cable', 'F1 Flexible', 'F12 Flexible');
        $parentsBefore = $this->styles->findByStyleCode('F12')?->parentPublicId;
        $review = $this->review->review();
        self::assertSame(0, $review['writeGuard']['wordpressWrites']);
        self::assertSame(0, $review['writeGuard']['tcWrites']);
        self::assertSame(0, $review['writeGuard']['parentStyleIdWrites']);
        self::assertSame(0, $review['writeGuard']['productAssignmentWrites']);
        self::assertSame(0, $review['writeGuard']['specificationWrites']);
        self::assertSame(0, $review['writeGuard']['mediaWrites']);
        self::assertSame(0, $review['writeGuard']['blobWrites']);
        self::assertSame($parentsBefore, $this->styles->findByStyleCode('F12')?->parentPublicId);
        self::assertGreaterThan(0, $this->source->inspectCalls);
    }

    public function test_apply_requires_confirmed_and_explicit_list(): void
    {
        $this->seedPair('F1', 'F12', 'Flexible Cable', 'F1 Flexible', 'F12 Flexible');
        try {
            $this->discovery->applyApprovedHierarchy($this->actor, [
                ['parentStyleCode' => 'F1', 'childStyleCode' => 'F12'],
            ], false);
            self::fail('Expected confirmation exception');
        } catch (\TriCab\Core\Product\Domain\Exception\InvalidProductValue $exception) {
            self::assertStringContainsString('confirmed', $exception->getMessage());
        }
        self::assertNull($this->styles->findByStyleCode('F12')?->parentPublicId);

        $empty = $this->discovery->applyApprovedHierarchy($this->actor, [], true);
        self::assertSame([], $empty);
        self::assertNull($this->styles->findByStyleCode('F12')?->parentPublicId);
    }

    public function test_apply_selected_list_only_with_audit_and_partial_selection(): void
    {
        $this->seedPair('F1', 'F12', 'Flexible Cable', 'F1 Flexible', 'F12 Flexible');
        $this->manage->create($this->actor, new UpsertProductStyleCommand('F14', 'F14'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'F14', 2, 2));
        $results = $this->discovery->applyApprovedHierarchy($this->actor, [
            ['parentStyleCode' => 'F1', 'childStyleCode' => 'F12'],
        ], true);
        self::assertTrue($results[0]['applied']);
        self::assertSame('F1', $this->styles->findByStyleCode('F12')?->parentStyleCode);
        self::assertNull($this->styles->findByStyleCode('F14')?->parentPublicId);
        $actions = array_map(static fn ($e) => $e->actionCode, $this->store->auditEntries());
        self::assertContains('parent-changed', $actions);
    }

    public function test_apply_rejects_numeric_conflict_cycle_and_stale_version(): void
    {
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '1', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '10', 1, 1));
        $numeric = $this->discovery->applyApprovedHierarchy($this->actor, [
            ['parentStyleCode' => '1', 'childStyleCode' => '10'],
        ], true);
        self::assertFalse($numeric[0]['applied']);
        self::assertStringContainsString('EXCLUDED_REVIEW', $numeric[0]['reason']);

        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $bva = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA', $bv->publicId));
        $sd = $this->manage->create($this->actor, new UpsertProductStyleCommand('SD', 'SD'));
        $conflict = $this->discovery->applyApprovedHierarchy($this->actor, [
            ['parentStyleCode' => 'SD', 'childStyleCode' => 'BVA'],
        ], true);
        self::assertFalse($conflict[0]['applied']);
        self::assertStringContainsString('DIFFERENT_PARENT_CONFLICT', $conflict[0]['reason']);
        self::assertSame('BV', $this->styles->findByStyleCode('BVA')?->parentStyleCode);

        $cycle = $this->discovery->applyApprovedHierarchy($this->actor, [
            ['parentStyleCode' => 'BVA', 'childStyleCode' => 'BV'],
        ], true);
        self::assertFalse($cycle[0]['applied']);

        try {
            $this->manage->changeParent($this->actor, new \TriCab\Core\Product\Application\Command\ChangeProductStyleParentCommand(
                stylePublicId: $bva->publicId,
                parentPublicId: $sd->publicId,
                expectedRowVersion: 'stale',
            ));
            self::fail('Expected stale RowVersion failure');
        } catch (\Throwable $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertSame('BV', $this->styles->findByStyleCode('BVA')?->parentStyleCode);
    }

    public function test_selected_set_excludes_review_reject_numeric_and_parent_conflicts(): void
    {
        $this->seedPair('F1', 'F12', 'Flexible Cable', 'F1 Flexible', 'F12 Flexible');
        $this->manage->create($this->actor, new UpsertProductStyleCommand('YY', 'YY'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('YYABCD', 'YYABCD'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'YY', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'YYABCD', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '1', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '10', 1, 1));
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA', $bv->publicId));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('SD', 'SD'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'SD', 2, 2));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BVA', 2, 2));

        $review = $this->review->review();
        $codes = array_map(static fn (StyleHierarchyEvidenceRow $r): string => $r->childStyleCode, $review['selected']);
        self::assertContains('F12', $codes);
        self::assertNotContains('YYABCD', $codes);
        self::assertNotContains('10', $codes);
        self::assertNotContains('BVA', $codes);
        self::assertSame(0, $review['counters']['cycleConflicts']);
        self::assertGreaterThan(0, $review['counters']['excludedReview']);
    }

    /**
     * @param array<string, mixed> $review
     */
    private function row(array $review, string $child): StyleHierarchyEvidenceRow
    {
        foreach ($review['rows'] as $row) {
            if ($row->childStyleCode === $child) {
                return $row;
            }
        }
        self::fail('Missing evidence row for ' . $child);
    }

    private function seedPair(string $parent, string $child, string $term, string $parentTitle, string $childTitle): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand($parent, $parent));
        $this->manage->create($this->actor, new UpsertProductStyleCommand($child, $child));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', $parent, 8, 8, [$parentTitle], $term));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', $child, 5, 5, [$childTitle], $term));
        $this->source->seedMembership($this->mem($parent, 8, [$parentTitle], [$term], [$parent . '.jpg'], ['volts' => ['0.6/1kV']]));
        $this->source->seedMembership($this->mem($child, 5, [$childTitle], [$term], [$parent . '.jpg'], ['volts' => ['0.6/1kV']]));
    }

    /**
     * @param list<string> $titles
     * @param list<string> $terms
     * @param list<string> $files
     * @param array<string, list<string>> $specs
     * @param list<string> $postTypes
     */
    private function mem(
        string $code,
        int $count,
        array $titles,
        array $terms,
        array $files = [],
        array $specs = [],
        array $postTypes = ['cable'],
    ): StyleMembershipEvidence {
        return new StyleMembershipEvidence(
            styleCode: $code,
            wordpressProductCount: $count,
            tcVariantCount: 0,
            sampleTitles: $titles,
            sampleProducts: $titles,
            terms: $terms,
            postTypes: $postTypes,
            attachmentFiles: $files,
            specificationValues: $specs,
            descriptionSnippets: $titles,
            sourcesAvailable: ['wordpress'],
            wordpressAvailable: true,
            tcAvailable: true,
        );
    }
}
