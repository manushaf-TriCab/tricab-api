<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Pilot;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationPilotCliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveProductMigrationPilotDryRunService;

final class Phase128ProductMigrationPilotDryRunTest extends TestCase
{
    public function testApplyWriteFlagsRejected(): void
    {
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::refuseApply(['bin.php', '--write']),
        );
        self::assertNull(ProductMigrationPilotCliGuard::refuseApply(['bin.php', '--dry-run']));
    }

    public function testTwentySevenCandidatePopulation(): void
    {
        $result = $this->service()->resolve($this->inputs());
        self::assertSame(27, $result['candidatesEvaluated']);
        self::assertCount(27, $result['candidateRanking']);
    }

    public function testDeterministicRanking(): void
    {
        $a = $this->service()->resolve($this->inputs());
        $b = $this->service()->resolve($this->inputs());
        self::assertSame(
            array_column($a['candidateRanking'], 'style_code'),
            array_column($b['candidateRanking'], 'style_code'),
        );
        self::assertSame(1, $a['candidateRanking'][0]['rank']);
    }

    public function testSafeCandidateSelectionPrefersCoverage(): void
    {
        $result = $this->service()->resolve($this->inputs());
        $selected = $result['recommendedPilot'];
        self::assertSame('F12', $selected['style_code']);
        self::assertSame(6, $selected['mappedSpecificationCount']);
        self::assertSame(0, $selected['unresolvedSpecificationCount']);
        self::assertStringContainsString('RECOMMENDED_PILOT_PRODUCT', (string) $result['selectionReason']);
    }

    public function testIdentityAmbiguityRejection(): void
    {
        $inputs = $this->inputs([
            'azure' => array_merge($this->azure(), [
                'existingStyleCodes' => ['F12', 'F12'],
            ]),
        ]);
        // Force F12 ambiguous — selector should skip to next safe candidate
        $result = $this->service()->resolve($inputs);
        self::assertNotSame('BLOCKED_IDENTITY', $result['identity']['decision']);
        self::assertNotSame('F12', $result['recommendedPilot']['style_code']);
    }

    public function testClassificationBlocking(): void
    {
        $inputs = $this->inputs();
        // Remove category from all — candidates still need category for RESOLVED
        $products = $inputs['classificationReadyProducts'];
        foreach ($products as &$p) {
            $p['category'] = '';
            $p['classificationStatus'] = 'REVIEW';
        }
        unset($p);
        $inputs['classificationReadyProducts'] = $products;
        $result = $this->service()->resolve($inputs);
        self::assertContains(
            $result['packageStatus'],
            [
                ResolveProductMigrationPilotDryRunService::PILOT_BLOCKED,
                ResolveProductMigrationPilotDryRunService::PILOT_REVIEW,
            ],
        );
    }

    public function testUnmappedPopulatedSpecBlocking(): void
    {
        $inputs = $this->inputs();
        // Add REVIEW row for the would-be winner and all others so unresolved > 0
        $reviews = [];
        foreach ($inputs['approvedFieldMappings'] as $m) {
            $reviews[] = [
                'styleCode' => $m['styleCode'],
                'sourceField' => 'd9',
                'c2Status' => 'REVIEW',
                'status' => 'REVIEW',
            ];
        }
        $inputs['reviewFieldMappings'] = $reviews;
        $result = $this->service()->resolve($inputs);
        self::assertSame(0, $result['candidatesEvaluated']);
        self::assertSame(ResolveProductMigrationPilotDryRunService::PILOT_BLOCKED, $result['packageStatus']);
    }

    public function testCodeCollisionBlocking(): void
    {
        $inputs = $this->inputs([
            'azure' => array_merge($this->azure(), [
                'existingProductCodes' => ['F12', 'A10', 'D07', 'D04', 'D05', 'D09', 'D10', 'F07', 'F09', 'F20', 'F21', 'F22', 'D20', 'D21', 'D22', 'PS1A', 'PS1B', 'PS1C', 'PS1D', 'J90', 'J91', 'J92', 'J93', 'J94', 'J95', 'J96', 'J97'],
            ]),
        ]);
        $result = $this->service()->resolve($inputs);
        self::assertSame(ResolveProductMigrationPilotDryRunService::PILOT_BLOCKED, $result['packageStatus']);
        self::assertTrue($result['collisionCheck']['productCodeCollision']);
    }

    public function testSlugCollisionBlocking(): void
    {
        $inputs = $this->inputs([
            'azure' => array_merge($this->azure(), [
                'existingSlugs' => ['f12', 'a10', 'd07', 'd04', 'd05', 'd09', 'd10', 'f07', 'f09', 'f20', 'f21', 'f22', 'd20', 'd21', 'd22', 'ps1a', 'ps1b', 'ps1c', 'ps1d', 'j90', 'j91', 'j92', 'j93', 'j94', 'j95', 'j96', 'j97'],
            ]),
        ]);
        $result = $this->service()->resolve($inputs);
        self::assertSame(ResolveProductMigrationPilotDryRunService::PILOT_BLOCKED, $result['packageStatus']);
        self::assertTrue($result['collisionCheck']['slugCollision']);
    }

    public function testDraftOnlyPublication(): void
    {
        $result = $this->service()->resolve($this->inputs());
        self::assertSame('Draft', $result['publication']['projectedStatus']);
        self::assertFalse($result['publication']['autoPublish']);
        self::assertSame('Draft', $result['mutationPlan']['publication']);
    }

    public function testPricingExcluded(): void
    {
        $result = $this->service()->resolve($this->inputs());
        self::assertSame(0, $result['pricing']['writes']);
        self::assertTrue($result['pricing']['excluded']);
        self::assertSame(0, $result['mutationPlan']['pricingWrites']);
    }

    public function testZeroLegacyAndTargetWrites(): void
    {
        $result = $this->service()->resolve($this->inputs());
        self::assertSame(0, $result['legacySafety']['wordpressWrites']);
        self::assertSame(0, $result['legacySafety']['tcWrites']);
        self::assertSame(0, $result['targetSafety']['totalWrites']);
        self::assertSame(0, $result['targetSafety']['blobWrites']);
        self::assertSame(0, $result['mutationPlan']['actualTargetWrites']);
    }

    public function testMediaMissingFileNonBlocking(): void
    {
        $inputs = $this->inputs([
            'mediaInventory' => [
                'images' => [],
                'missingFiles' => ['uploads/missing.jpg'],
            ],
        ]);
        $result = $this->service()->resolve($inputs);
        self::assertSame(
            ResolveProductMigrationPilotDryRunService::PILOT_READY,
            $result['packageStatus'],
        );
        self::assertContains('uploads/missing.jpg', $result['mediaProjection']['missingFiles']);
    }

    public function testRollbackOwnershipAndManualProtection(): void
    {
        $rb = $this->service()->resolve($this->inputs())['rollbackProjection'];
        self::assertTrue($rb['manualEditProtection']);
        self::assertFalse($rb['executed']);
        self::assertSame('NEVER_DELETE', $rb['preservedRecords']['preExistingProducts']);
    }

    public function testIdempotencyProjection(): void
    {
        $idemp = $this->service()->resolve($this->inputs())['idempotencyProjection'];
        self::assertSame(0, $idemp['secondRun']['duplicateProductCreates']);
        self::assertSame('CONFLICT_NOT_SILENT_OVERWRITE', $idemp['secondRun']['differentExisting']);
    }

    public function testDeterministicArtifactsList(): void
    {
        $artifacts = $this->service()->resolve($this->inputs())['artifacts'];
        self::assertContains('pilot-final-dry-run-package.json', $artifacts);
        self::assertCount(18, $artifacts);
    }

    public function testCableFieldsUntouched(): void
    {
        $fields = $this->service()->resolve($this->inputs())['cableFieldsUntouched'];
        self::assertSame(
            ['cc', 'ct', 'walu', 'wbnz', 'ins', 'Af', 'r', 'x50', 'x60', 'tension'],
            $fields,
        );
    }

    public function testPilotReadyHappyPath(): void
    {
        $result = $this->service()->resolve($this->inputs());
        self::assertSame(ResolveProductMigrationPilotDryRunService::PILOT_READY, $result['packageStatus']);
        self::assertSame(1, $result['mutationPlan']['productCreate']);
        self::assertSame(1, $result['mutationPlan']['variantsCreate']);
        self::assertSame(6, $result['mutationPlan']['variantSpecValuesInsert']);
        self::assertSame(0, $result['mutationPlan']['definitionCreates']);
    }

    private function service(): ResolveProductMigrationPilotDryRunService
    {
        return new ResolveProductMigrationPilotDryRunService();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function inputs(array $overrides = []): array
    {
        return array_merge([
            'classificationReadyProducts' => $this->products(),
            'approvedFieldMappings' => $this->approvedMaps(),
            'reviewFieldMappings' => $this->reviewMapsForIncomplete(),
            'azure' => $this->azure(),
            'baseline' => [
                'definitions' => 64,
                'totalFieldMappings' => 1044,
                'cableFieldMappings' => 538,
                'productSpecValues' => 322,
                'variantSpecValues' => 52619,
            ],
            'forbiddenWriteAttempts' => 0,
            'mediaInventory' => [],
            'documentInventory' => [],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function azure(): array
    {
        return [
            'definitionCount' => 64,
            'fieldMappingCount' => 1044,
            'cableFieldMappingCount' => 538,
            'productSpecificationValues' => 322,
            'variantSpecificationValues' => 52619,
            'existingStyleCodes' => [],
            'existingProductCodes' => [],
            'existingSlugs' => [],
            'regions' => [['code' => 'AU']],
        ];
    }

    /**
     * 27 DV-ready + 13 incomplete accessories + cables ignored.
     *
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        $ready = [
            'F12' => ['CONDUITS-FITTINGS', 'Inspection Tee', 6],
            'A10' => ['TERMINALS', 'Pin Terminals', 5],
            'F09' => ['CONDUITS-FITTINGS', 'Coupling', 3],
            'J90' => ['TECHNO-CONNECTORS', 'TECHNO Junction', 3],
            'J91' => ['TECHNO-CONNECTORS', 'TECHNO', 3],
            'J92' => ['TECHNO-CONNECTORS', 'TECHNO', 3],
            'J93' => ['TECHNO-CONNECTORS', 'TECHNO Plug', 3],
            'J94' => ['TECHNO-CONNECTORS', 'TECHNO Socket', 3],
            'J95' => ['TECHNO-CONNECTORS', 'TECHNO Plug', 3],
            'J96' => ['TECHNO-CONNECTORS', 'TECHNO Socket', 3],
            'J97' => ['TECHNO-CONNECTORS', 'TECHNO', 3],
            'PS1A' => ['PLUG-SOCKET-LEADS', 'INDUSTRIAL PLUG-SOCKET', 4],
            'PS1B' => ['PLUG-SOCKET-LEADS', 'INDUSTRIAL PLUG-SOCKET', 4],
            'PS1C' => ['PLUG-SOCKET-LEADS', 'INDUSTRIAL PLUG-SOCKET', 4],
            'PS1D' => ['PLUG-SOCKET-LEADS', 'INDUSTRIAL PLUG-SOCKET', 4],
            'D04' => ['GLANDS', 'Long Thread Straight Spiral Top', 2],
            'D05' => ['GLANDS', '90 Watertight', 2],
            'D07' => ['GLANDS', 'Premium Nylon', 2],
            'D09' => ['GLANDS', '90 Spiral Top', 2],
            'D10' => ['GLANDS', 'Straight Spiral Top', 2],
            'F07' => ['CONDUITS-FITTINGS', 'Medium Duty', 2],
            'F20' => ['CONDUITS-FITTINGS', 'Split Loom', 2],
            'F21' => ['CONDUITS-FITTINGS', 'Nylon Split Loom', 2],
            'F22' => ['CONDUITS-FITTINGS', 'Heavy Duty', 2],
            'D20' => ['GLANDS', 'Standard Nylon', 1],
            'D21' => ['GLANDS', 'Standard PG threaded Nylon', 1],
            'D22' => ['GLANDS', 'Standard NPT threaded Nylon', 1],
        ];
        $out = [];
        foreach ($ready as $style => [$cat, $title, $_n]) {
            $out[] = [
                'style_code' => $style,
                'family' => 'ACCESSORIES',
                'category' => $cat,
                'series' => $style,
                'productTitle' => $title,
                'classificationStatus' => 'CLASSIFICATION_READY',
                'fullProductMigrationReady' => false,
                'variantCount' => 0,
            ];
        }
        // Incomplete (have REVIEW) — should be excluded from 27
        foreach (['A12', 'A35', 'A47'] as $style) {
            $out[] = [
                'style_code' => $style,
                'family' => 'ACCESSORIES',
                'category' => 'TERMINALS',
                'series' => $style,
                'productTitle' => 'Incomplete ' . $style,
                'classificationStatus' => 'CLASSIFICATION_READY',
                'fullProductMigrationReady' => false,
            ];
        }
        $out[] = [
            'style_code' => 'FT',
            'family' => 'CABLE',
            'category' => 'FLEXIBLE',
            'series' => 'FT',
            'productTitle' => 'Cable FT',
            'classificationStatus' => 'CLASSIFICATION_READY',
            'fullProductMigrationReady' => true,
        ];

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvedMaps(): array
    {
        $defs = [
            'F12' => ['d1' => 'INNER-DIAMETER', 'd2' => 'OUTER-DIAMETER', 'd3' => 'DEPTH', 'd4' => 'OVERALL-LENGTH', 'd5' => 'WIDTH', 'd6' => 'HEIGHT'],
            'A10' => ['d1' => 'PIN-DIAMETER', 'd2' => 'OVERALL-LENGTH', 'd3' => 'BARREL-LENGTH', 'd4' => 'BARREL-OD', 'd6' => 'WIDTH'],
            'F09' => ['d1' => 'INNER-DIAMETER', 'd2' => 'OUTER-DIAMETER', 'd3' => 'LENGTH'],
            'D20' => ['d1' => 'THREAD-OD'],
            'D21' => ['d1' => 'THREAD-OD'],
            'D22' => ['d1' => 'THREAD-OD'],
        ];
        foreach (['D04', 'D05', 'D07', 'D09', 'D10', 'F07', 'F20', 'F21', 'F22'] as $s) {
            $defs[$s] = ['d1' => 'THREAD-OD', 'd2' => 'THREAD-LENGTH'];
        }
        foreach (['PS1A', 'PS1B', 'PS1C', 'PS1D'] as $s) {
            $defs[$s] = ['d1' => 'PIN-COUNT', 'd2' => 'VOLTAGE', 'd3' => 'CURRENT', 'd4' => 'CABLE-LENGTH'];
        }
        foreach (['J90', 'J91', 'J92', 'J93', 'J94', 'J95', 'J96', 'J97'] as $s) {
            $defs[$s] = ['d1' => 'PIN-COUNT', 'd2' => 'VOLTAGE', 'd3' => 'CURRENT'];
        }

        $out = [];
        foreach ($defs as $style => $fields) {
            foreach ($fields as $field => $code) {
                $out[] = [
                    'styleCode' => $style,
                    'sourceField' => $field,
                    'sourceTable' => 'accessory_specs',
                    'legacyLabel' => $code . ' mm',
                    'targetDefinitionCode' => $code,
                    'status' => 'APPROVED_MAP',
                    'reason' => 'test_approved',
                    'sampleValues' => ['12.00'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewMapsForIncomplete(): array
    {
        return [
            ['styleCode' => 'A12', 'sourceField' => 'd3', 'c2Status' => 'REVIEW', 'status' => 'REVIEW'],
            ['styleCode' => 'A35', 'sourceField' => 'd1', 'c2Status' => 'REVIEW', 'status' => 'REVIEW'],
            ['styleCode' => 'A47', 'sourceField' => 'd2', 'c2Status' => 'REVIEW', 'status' => 'REVIEW'],
        ];
    }
}
