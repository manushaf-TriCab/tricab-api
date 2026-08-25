<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertProductStyleCommand;
use TriCab\Core\Product\Application\DTO\LegacyStyleOccurrence;
use TriCab\Core\Product\Application\DTO\StyleInventorySourceStat;
use TriCab\Core\Product\Application\Service\CreateMissingProductStylesFromInventoryService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleInventoryService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryLegacyStyleOccurrenceSource;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class DiscoverLegacyStyleInventoryServiceTest extends TestCase
{
    private InMemoryProductStore $store;
    private InMemoryProductStyleRepository $styles;
    private InMemoryLegacyStyleOccurrenceSource $source;
    private DiscoverLegacyStyleInventoryService $inventory;
    private ManageProductStyleService $manage;
    private CreateMissingProductStylesFromInventoryService $createMissing;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->styles = new InMemoryProductStyleRepository();
        $this->source = new InMemoryLegacyStyleOccurrenceSource(
            stats: [
                new StyleInventorySourceStat('wordpress', 'wp_postmeta', 'style_code', 10, 8, 6, true, true),
                new StyleInventorySourceStat('tc-mysql', 'tpro_ext', 'style', 20, 18, 6, true, true),
            ],
            wordpressReadCount: 2,
            tcReadCount: 3,
        );
        $this->store = new InMemoryProductStore();
        $audit = new ProductAuditRecorder($this->store->audit());
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-1', emailAddress: 'a@example.test');
        $this->manage = new ManageProductStyleService($this->styles, $this->store->specifications(), new PassthroughApplicationTransaction(), $audit);
        $this->inventory = new DiscoverLegacyStyleInventoryService($this->styles, $this->source);
        $this->createMissing = new CreateMissingProductStylesFromInventoryService($this->manage, $this->styles, $audit);
    }

    public function test_extracts_from_wordpress_and_tc_and_trims_and_uppercases(): void
    {
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', ' BV ', 3, ['RI Cable']));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'bv', 1, ['RI Cable 2']));
        $this->source->seed($this->occ('tc-mysql', 'tpro_ext', 'style', 'BV', 5, ['BV001']));
        $this->source->seed($this->occ('tc-mysql', 'tpro.style', 'style', 'bva', 2, ['BVA1']));

        $report = $this->inventory->discover();
        $byCode = $this->index($report->items);
        self::assertArrayHasKey('BV', $byCode);
        self::assertArrayHasKey('BVA', $byCode);
        self::assertSame('VALID', $byCode['BV']->status);
        self::assertSame([' BV ', 'BV', 'bv'], $byCode['BV']->rawStyleCodes);
        self::assertSame('trim_and_uppercase', $byCode['BV']->normalisationReason);
        self::assertSame('FALLBACK_STYLE_CODE', $byCode['BV']->displayNameSource);
        self::assertSame('BV', $byCode['BV']->displayName);
        self::assertNotSame([], array_filter($byCode['BV']->warnings, static fn (string $w): bool => str_contains($w, 'Multiple raw forms collapsed')));
        self::assertFalse($report->writeGuard['persisted']);
        self::assertSame(0, $report->writeGuard['wordpressWrites']);
        self::assertSame(1, $this->source->listCalls);
    }

    public function test_preserves_meaningful_hyphens_and_does_not_merge_bva_x(): void
    {
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'BVA-X', 1));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'BVA', 4));
        $report = $this->inventory->discover();
        $byCode = $this->index($report->items);
        self::assertArrayHasKey('BVA', $byCode);
        self::assertArrayHasKey('BVA-X', $byCode);
        self::assertSame('BVA-X', $byCode['BVA-X']->normalizedStyleCode);
        self::assertSame('REVIEW', $byCode['BVA-X']->status);
        self::assertSame('POSSIBLE_TEST_DATA', $byCode['BVA-X']->targetClassification);
    }

    public function test_invalid_blank_and_review_values_are_kept(): void
    {
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', '   ', 1));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'B3C, B3M', 2));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'n/a', 1));
        $this->source->seed($this->occ('tc-mysql', 'cable_specs', 'cable_style', '10', 3));
        $report = $this->inventory->discover();
        self::assertGreaterThanOrEqual(1, $report->counters['invalid']);
        self::assertGreaterThanOrEqual(1, $report->counters['blankStyles']);
        self::assertGreaterThanOrEqual(1, $report->counters['review']);
        $raws = array_map(static fn ($i) => $i->rawStyleCode, $report->items);
        self::assertContains('B3C, B3M', $raws);
        self::assertContains('10', $raws);
    }

    public function test_target_matching_and_test_data_detection(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA-X', 'Smoke child'));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'BV', 4));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'XYZ', 2));

        $report = $this->inventory->discover();
        $byCode = $this->index($report->items);
        self::assertSame('EXISTS_MATCH', $byCode['BV']->targetMatch);
        self::assertSame('CONFIRMED_LEGACY', $byCode['BV']->targetClassification);
        self::assertSame('MISSING_TARGET', $byCode['XYZ']->targetMatch);
        self::assertCount(1, $report->targetOnly);
        self::assertSame('BVA-X', $report->targetOnly[0]->normalizedStyleCode);
        self::assertSame('TARGET_ONLY', $report->targetOnly[0]->targetMatch);
        self::assertSame('POSSIBLE_TEST_DATA', $report->targetOnly[0]->targetClassification);
        self::assertSame(0, $report->writeGuard['azureSqlWrites']);
    }

    public function test_preview_does_not_create_missing_styles(): void
    {
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'XYZ', 1));
        $this->inventory->discover();
        self::assertNull($this->styles->findByStyleCode('XYZ'));
    }

    public function test_create_requires_approval_and_only_creates_valid_missing_roots(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BV', 'BV'));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'BV', 1));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'XYZ', 2));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'n/a', 1));
        $report = $this->inventory->discover();
        self::assertTrue($report->readyToCreate);

        try {
            $this->createMissing->createApprovedMissing($this->actor, $report, false, true);
            self::fail('Expected approval exception');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Operator approval', $exception->getMessage());
        }
        self::assertNull($this->styles->findByStyleCode('XYZ'));

        $result = $this->createMissing->createApprovedMissing($this->actor, $report, true, true);
        self::assertCount(1, $result['created']);
        self::assertSame('XYZ', $result['created'][0]['styleCode']);
        self::assertSame(1, $result['counters']['created']);
        self::assertGreaterThanOrEqual(1, $result['counters']['skippedReview']);
        $created = $this->styles->findByStyleCode('XYZ');
        self::assertNotNull($created);
        self::assertNull($created->parentPublicId);
        self::assertSame('LOCAL', $created->inheritance->specifications);
        self::assertSame('LOCAL', $created->inheritance->images);
        self::assertSame('LOCAL', $created->inheritance->documents);
        self::assertSame('LOCAL', $created->inheritance->spin360);
        self::assertSame('LOCAL', $created->inheritance->models3d);
        self::assertSame('LOCAL', $created->inheritance->arAssets);
        self::assertSame('XYZ', $created->displayName);
        self::assertSame(0, $result['productAssignmentWrites']);
        self::assertSame(0, $result['hierarchyWrites']);
        self::assertSame(0, $result['specificationWrites']);
        self::assertSame(0, $result['mediaWrites']);
        $auditActions = array_map(static fn ($e) => $e->actionCode, $this->store->auditEntries());
        self::assertContains('legacy-style-inventory-created', $auditActions);

        $rerunDiscover = $this->inventory->discover();
        $rerun = $this->createMissing->createApprovedMissing($this->actor, $rerunDiscover, true, true);
        self::assertSame(0, $rerun['counters']['created']);
        self::assertGreaterThanOrEqual(1, $rerun['counters']['alreadyExisted']);
        self::assertCount(1, array_filter(
            $this->styles->listAll(false),
            static fn ($style): bool => $style->styleCode === 'XYZ',
        ));
    }

    public function test_review_numeric_and_target_only_are_not_created(): void
    {
        $this->manage->create($this->actor, new UpsertProductStyleCommand('BVA-X', 'Smoke'));
        $this->source->seed($this->occ('tc-mysql', 'cable_specs', 'cable_style', '10', 3));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'B3C, B3M', 1));
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'F12', 2));
        $report = $this->inventory->discover();
        $result = $this->createMissing->createApprovedMissing($this->actor, $report, true, true);
        $codes = array_column($result['created'], 'styleCode');
        self::assertContains('F12', $codes);
        self::assertNotContains('10', $codes);
        self::assertNotContains('B3C, B3M', $codes);
        self::assertNotContains('BVA-X', $codes);
        self::assertNull($this->styles->findByStyleCode('10'));
        self::assertNotNull($this->styles->findByStyleCode('BVA-X'));
        self::assertSame('Smoke', $this->styles->findByStyleCode('BVA-X')?->displayName);
        self::assertGreaterThanOrEqual(1, $result['counters']['skippedReview']);
        self::assertGreaterThanOrEqual(1, $result['counters']['skippedTargetOnly']);
        self::assertSame(0, $result['counters']['failed']);
    }

    public function test_existing_style_between_discover_and_apply_is_skipped_without_suffix(): void
    {
        $this->source->seed($this->occ('wordpress', 'wp_postmeta', 'style_code', 'XYZ', 1));
        $report = $this->inventory->discover();
        $this->manage->create($this->actor, new UpsertProductStyleCommand('XYZ', 'XYZ'));
        $result = $this->createMissing->createApprovedMissing($this->actor, $report, true, true);
        self::assertSame(0, $result['counters']['created']);
        self::assertSame(1, $result['counters']['alreadyExisted']);
        self::assertCount(1, array_filter($this->styles->listAll(false), static fn ($s) => $s->styleCode === 'XYZ'));
        self::assertNull($this->styles->findByStyleCode('XYZ-2'));
        self::assertNull($this->styles->findByStyleCode('XYZ-NEW'));
    }

    /**
     * @return array<string, \TriCab\Core\Product\Application\DTO\StyleInventoryItem>
     */
    private function index(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = $item->normalizedStyleCode ?? $item->rawStyleCode;
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * @param list<string> $samples
     */
    private function occ(
        string $source,
        string $table,
        string $field,
        string $raw,
        int $count,
        array $samples = [],
    ): LegacyStyleOccurrence {
        return new LegacyStyleOccurrence($source, $table, $field, $raw, $count, $count, $samples, null);
    }
}
