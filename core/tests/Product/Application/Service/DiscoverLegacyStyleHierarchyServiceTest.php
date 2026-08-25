<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\LegacyStyleOccurrence;
use TriCab\Core\Product\Application\DTO\StyleInventorySourceStat;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleHierarchyService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleInventoryService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryLegacyStyleOccurrenceSource;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class DiscoverLegacyStyleHierarchyServiceTest extends TestCase
{
    private InMemoryProductStyleRepository $styles;
    private InMemoryLegacyStyleOccurrenceSource $source;
    private DiscoverLegacyStyleHierarchyService $discovery;
    private ManageProductStyleService $manage;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->source = new InMemoryLegacyStyleOccurrenceSource(
            stats: [new StyleInventorySourceStat('wordpress', 'wp_postmeta', 'style_code', 10, 8, 5, true, true)],
            wordpressReadCount: 1,
        );
        $store = new InMemoryProductStore();
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $audit = new ProductAuditRecorder($store->audit());
        $this->manage = new ManageProductStyleService($this->styles, $store->specifications(), new PassthroughApplicationTransaction(), $audit);
        $inventory = new DiscoverLegacyStyleInventoryService($this->styles, $this->source);
        $this->discovery = new DiscoverLegacyStyleHierarchyService($this->styles, inventory: $inventory);
    }

    public function test_bv_family_prefix_is_evidence_not_authority_and_not_applied(): void
    {
        foreach (['BV', 'BVA', 'BVC', 'BVM', 'BVP'] as $code) {
            $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', $code, 2, 2, [$code], 'CABLE'));
        }
        $preview = $this->discovery->preview();
        $map = $this->proposalMap($preview['proposals']);
        self::assertArrayHasKey('BVA', $map);
        self::assertSame('BV', $map['BVA']->parentStyleCode);
        self::assertSame('HIGH', $map['BVA']->confidence);
        self::assertSame('NOT_APPLIED', $map['BVA']->applicationState);
        self::assertStringContainsString('evidence only', $map['BVA']->reason);
        self::assertSame(0, $preview['writeGuard']['relationshipsApplied']);
        self::assertFalse($preview['writeGuard']['persisted']);
        self::assertNull($this->styles->findByStyleCode('BVA'));
    }

    public function test_ambiguous_parents_are_review(): void
    {
        foreach (['ABC', 'ABCD', 'ABCDE'] as $code) {
            $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', $code, 1, 1));
        }
        $preview = $this->discovery->preview();
        $map = $this->proposalMap($preview['proposals']);
        self::assertTrue($map['ABCDE']->ambiguousParent);
        self::assertSame('REVIEW', $map['ABCDE']->confidence);
        self::assertContains('ABC', $map['ABCDE']->prefixCandidates);
        self::assertContains('ABCD', $map['ABCDE']->prefixCandidates);
        self::assertGreaterThanOrEqual(1, $preview['counters']['ambiguousParentCases']);
    }

    public function test_standalone_style_has_no_proposal(): void
    {
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'XYZ', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BV', 1, 1));
        $preview = $this->discovery->preview();
        self::assertContains('XYZ', $preview['standaloneStyleCodes']);
        $children = array_map(static fn ($p) => $p->childStyleCode, $preview['proposals']);
        self::assertNotContains('XYZ', $children);
    }

    public function test_existing_parent_is_already_persisted_not_a_new_write(): void
    {
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA', $bv->publicId));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BV', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BVA', 1, 1));
        $preview = $this->discovery->preview();
        $map = $this->proposalMap($preview['proposals']);
        self::assertSame('ALREADY_PERSISTED', $map['BVA']->applicationState);
        self::assertSame('BV', $map['BVA']->persistedParentStyleCode);
        self::assertSame(0, $preview['counters']['relationshipsApplied']);
    }

    public function test_conflicting_persisted_parent_is_review(): void
    {
        $sd = $this->manage->create($this->actor, new UpsertProductStyleCommand('SD', 'SD'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA', $sd->publicId));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BV', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BVA', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'SD', 1, 1));
        $preview = $this->discovery->preview();
        $map = $this->proposalMap($preview['proposals']);
        self::assertSame('CONFLICTS_WITH_PERSISTED', $map['BVA']->applicationState);
        self::assertSame('REVIEW', $map['BVA']->confidence);
        self::assertSame('SD', $this->styles->findByStyleCode('BVA')?->parentStyleCode);
    }

    public function test_cycle_detection_marks_review_and_does_not_apply(): void
    {
        $bva = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA'));
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV', $bva->publicId));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BV', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BVA', 1, 1));
        $preview = $this->discovery->preview();
        $map = $this->proposalMap($preview['proposals']);
        self::assertTrue($map['BVA']->cycleConflict);
        self::assertSame('REVIEW', $map['BVA']->confidence);
        self::assertSame(0, $preview['counters']['relationshipsApplied']);
        self::assertSame('BVA', $this->manage->get($bv->publicId)->parentStyleCode);
    }

    public function test_zero_vs_zero_spec_and_media_is_no_evidence_not_similarity(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('B1', 'B1'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('B10', 'B10'));
        $preview = $this->discovery->preview(['B1', 'B10']);
        $map = $this->proposalMap($preview['proposals']);
        self::assertArrayHasKey('B10', $map);
        self::assertNull($map['B10']->sharedSpecificationEvidence);
        self::assertNull($map['B10']->sharedMediaEvidence);
        self::assertNotSame(0, $map['B10']->sharedSpecificationEvidence);
        $joined = implode(' ', $map['B10']->warnings);
        self::assertStringContainsString('NO_EVIDENCE', $joined);
        self::assertSame(0, $preview['counters']['relationshipsApplied']);
    }

    public function test_numeric_codes_are_review_and_not_apply_eligible(): void
    {
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '1', 2, 2));
        $this->source->seed(new LegacyStyleOccurrence('tc-mysql', 'cable_specs', 'cable_style', '10', 1, 1));
        $this->source->seed(new LegacyStyleOccurrence('wordpress', 'wp_postmeta', 'style_code', 'BV', 1, 1));
        $preview = $this->discovery->preview();
        self::assertContains('1', $preview['styleCodes']);
        self::assertContains('10', $preview['styleCodes']);
        self::assertSame(0, $preview['counters']['relationshipsApplied']);
        $map = $this->proposalMap($preview['proposals']);
        self::assertArrayHasKey('10', $map);
        self::assertSame('1', $map['10']->parentStyleCode);
        self::assertSame('REVIEW', $map['10']->confidence);
        self::assertNotSame('HIGH', $map['10']->confidence);
    }

    public function test_cycle_guard_rejects_apply_but_preview_never_calls_apply(): void
    {
        $bv = $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $bva = $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA', 'BVA', $bv->publicId));
        $preview = $this->discovery->preview(['BV', 'BVA']);
        self::assertSame(0, $preview['counters']['relationshipsApplied']);
        self::assertSame($bv->publicId, $this->manage->get($bva->publicId)->parentPublicId);
        $serialized = json_encode($preview['proposals'][0]);
        self::assertIsString($serialized);
        self::assertStringContainsString('BVA', $serialized);
    }

    /**
     * @param list<\TriCab\Core\Product\Application\DTO\StyleDiscoveryProposal> $proposals
     * @return array<string, \TriCab\Core\Product\Application\DTO\StyleDiscoveryProposal>
     */
    private function proposalMap(array $proposals): array
    {
        $out = [];
        foreach ($proposals as $proposal) {
            $out[$proposal->childStyleCode] = $proposal;
        }

        return $out;
    }
}
