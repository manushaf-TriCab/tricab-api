<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Pilot;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationPilotCliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveF12LiveSkuPilotDryRunService;
use TriCab\Core\Product\Migration\CatalogueNavigation\CatalogueNavigationDecision;
use TriCab\Core\Product\Migration\Support\MappingSampleValueInvariant;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

final class Phase128F12LiveSkuPilotDryRunTest extends TestCase
{
    public function testApplyWriteFlagsAreRejected(): void
    {
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::refuseApply(['bin.php', '--apply']),
        );
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::refuseApply(['bin.php', '--execute']),
        );
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::refuseApply(['bin.php', '--write']),
        );
        self::assertNull(ProductMigrationPilotCliGuard::refuseApply(['bin.php', '--dry-run', '--confirm-non-production']));
    }

    public function testSampleValuesNeverBecomePayload(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        foreach (MigrationPayload::assocList($payload['specifications']['projectedVariantSpecificationValues'] ?? []) as $row) {
            self::assertFalse(MappingSampleValueInvariant::payloadViolatesInvariant(
                $row,
                ['12.00', '15.00', '10.00', '3.00', '2.00', '4.30'],
            ));
            self::assertSame('live_legacy_row', $row['valueSource'] ?? null);
            self::assertNotSame('12.00', $row['normalizedValue'] ?? '12.00');
            self::assertTrue(MappingSampleValueInvariant::hasRowProvenance($row));
        }
        self::assertSame(MappingSampleValueInvariant::RULE, $payload['invariant']['rule'] ?? null);
        self::assertFalse(MigrationPayload::bool($payload['invariant']['sampleValuesMayBePayload'] ?? true, true));
    }

    public function testEverySpecHasLegacyRowColumnProvenance(): void
    {
        $values = MigrationPayload::assocList($this->payload($this->service()->dryRun($this->input())->toArray())['specifications']['projectedVariantSpecificationValues'] ?? []);
        self::assertNotEmpty($values);
        foreach ($values as $row) {
            self::assertSame('tc', $row['legacyDatabase'] ?? null);
            self::assertSame('accessory_specs', $row['legacyTable'] ?? null);
            self::assertNotSame('', MigrationPayload::string($row['legacyRowId'] ?? ''));
            self::assertNotSame('', MigrationPayload::string($row['legacyProductCode'] ?? ''));
            self::assertMatchesRegularExpression('/^d[1-6]$/', MigrationPayload::string($row['legacyField'] ?? ''));
            self::assertNotSame('', MigrationPayload::string($row['rawValue'] ?? ''));
        }
    }

    public function testAllDiscoveredSkusAreProjectedWithoutHardCodedPair(): void
    {
        $input = $this->input();
        $input['liveSkuRows'][] = [
            'legacyRowId' => '3',
            'productCode' => 'F12-AHXX/XD32GY',
            'd1' => '32.00',
            'd2' => '36.00',
            'd3' => '32.00',
            'd4' => '120.00',
            'd5' => '80.00',
            'd6' => '42.00',
        ];
        $payload = $this->payload($this->service()->dryRun($input)->toArray());
        self::assertSame(3, MigrationPayload::int($payload['sourceInventory']['rowsDiscovered'] ?? 0));
        self::assertSame(3, MigrationPayload::int($payload['variants']['variantsProjected'] ?? 0));
        $codes = [];
        foreach (MigrationPayload::assocList($payload['variants']['projected'] ?? []) as $row) {
            $codes[] = MigrationPayload::string($row['proposedVariantCode'] ?? '');
        }
        self::assertContains('F12-AHXX/XD32GY', $codes);
        self::assertNotContains('F12-01', $codes);
    }

    public function testSyntheticF1201WithoutSourceIsRejected(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        self::assertFalse(MigrationPayload::bool($payload['variants']['syntheticF1201Used'] ?? true, true));
        foreach (MigrationPayload::assocList($payload['variants']['projected'] ?? []) as $row) {
            self::assertNotSame('F12-01', $row['proposedVariantCode'] ?? 'F12-01');
        }
    }

    public function testDuplicateLegacySkuBlocks(): void
    {
        $input = $this->input();
        $dup = $input['liveSkuRows'][0];
        $dup['legacyRowId'] = '99';
        $input['liveSkuRows'][] = $dup;
        $payload = $this->payload($this->service()->dryRun($input)->toArray());
        self::assertGreaterThan(0, MigrationPayload::int($payload['variants']['variantsBlocked'] ?? 0));
        self::assertFalse(MigrationPayload::bool($payload['readiness']['VARIANT_STRUCTURE_READY'] ?? true, true));
    }

    public function testActualD1D6ValuesAreUsed(): void
    {
        $values = MigrationPayload::assocList($this->payload($this->service()->dryRun($this->input())->toArray())['specifications']['projectedVariantSpecificationValues'] ?? []);
        $byKey = [];
        foreach ($values as $row) {
            $byKey[MigrationPayload::string($row['legacyProductCode'] ?? '') . ':' . MigrationPayload::string($row['legacyField'] ?? '')] = MigrationPayload::string($row['normalizedValue'] ?? '');
        }
        self::assertSame('20.00', $byKey['F12-AHXX/XD20GY:d1'] ?? null);
        self::assertSame('24.00', $byKey['F12-AHXX/XD20GY:d2'] ?? null);
        self::assertSame('25.00', $byKey['F12-AHXX/XD25GY:d1'] ?? null);
        self::assertSame('29.00', $byKey['F12-AHXX/XD25GY:d2'] ?? null);
    }

    public function testApprovedMappingSemanticsArePreserved(): void
    {
        $maps = MigrationPayload::assocList($this->payload($this->service()->dryRun($this->input())->toArray())['authoritativeMappings'] ?? []);
        $byField = [];
        foreach ($maps as $row) {
            $byField[MigrationPayload::string($row['legacyField'] ?? '')] = $row;
        }
        self::assertSame('INNER-DIAMETER', $byField['d1']['targetDefinition'] ?? null);
        self::assertSame('OUTER-DIAMETER', $byField['d2']['targetDefinition'] ?? null);
        self::assertSame('DEPTH', $byField['d3']['targetDefinition'] ?? null);
        self::assertSame('OVERALL-LENGTH', $byField['d4']['targetDefinition'] ?? null);
        self::assertSame('WIDTH', $byField['d5']['targetDefinition'] ?? null);
        self::assertSame('HEIGHT', $byField['d6']['targetDefinition'] ?? null);
        self::assertSame('MM', $byField['d2']['targetUnit'] ?? null);
    }

    public function testAccessoryOuterDiameterGuard(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        foreach (MigrationPayload::assocList($payload['authoritativeMappings'] ?? []) as $row) {
            self::assertNotSame('NOM-CABLE-OD', $row['targetDefinition'] ?? null);
        }
        self::assertFalse(MigrationPayload::bool($payload['specifications']['cableNomCableOdApplied'] ?? true, true));
    }

    public function testProductAndVariantScopeFromLiveSkus(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        self::assertSame('CREATE_NEW_DRAFT', $payload['identity']['decision'] ?? null);
        self::assertSame('F12', $payload['identity']['styleCode'] ?? null);
        self::assertGreaterThan(1, MigrationPayload::int($payload['variants']['variantsProjected'] ?? 0));
        self::assertSame([], MigrationPayload::assocList($payload['specifications']['projectedProductSpecificationValues'] ?? []));
        self::assertNotEmpty(MigrationPayload::assocList($payload['specifications']['projectedVariantSpecificationValues'] ?? []));
    }

    public function testClassificationAndAuRegionPreserved(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        self::assertSame('RESOLVED', $payload['classification']['status'] ?? null);
        self::assertSame('ACCESSORIES', $payload['classification']['family']['target'] ?? null);
        self::assertSame('CONDUITS-FITTINGS', $payload['classification']['category']['target'] ?? null);
        self::assertSame('F12', $payload['classification']['series']['target'] ?? null);
        self::assertSame(['AU'], $payload['region']['resolvedRegions'] ?? null);
        self::assertFalse(MigrationPayload::bool($payload['region']['separateCatalogueTreeByRegion'] ?? true, true));
    }

    public function testExistingCatalogueStructureAndManualLinksAreProtected(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'catalogueNavigation' => [
                'targetLiveConnected' => true,
                'legacyProducts' => [[
                    'styleCode' => 'F12',
                    'catalogueRelationships' => [[
                        'sourceKind' => 'taxonomy',
                        'legacyPath' => 'conduit',
                        'legacyTaxonomy' => 'category',
                        'legacyTermId' => '538',
                        'legacyTermSlug' => 'conduit',
                    ]],
                ]],
                'target' => [
                    'tabs' => [['publicId' => 'tab-1', 'tabCode' => 'PRODUCTS', 'tabName' => 'Products', 'slug' => 'products']],
                    'nodes' => [['publicId' => 'node-1', 'tabPublicId' => 'tab-1', 'nodeCode' => 'PROD-ACCESSORIES', 'nodeName' => 'Accessories', 'slug' => 'accessories']],
                    'productNodeLinks' => [[
                        'productPublicId' => 'manual-1',
                        'styleCode' => 'A10',
                        'catalogueNodePublicId' => 'node-1',
                    ]],
                ],
            ],
            'targetLiveConnected' => true,
            'target' => ['liveConnected' => true, 'existingProducts' => [], 'existingVariants' => []],
        ]))->toArray());
        self::assertSame(0, MigrationPayload::int($payload['safety']['catalogueTabWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($payload['safety']['catalogueNodeWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($payload['mutationPlan']['catalogueRelationshipInserts'] ?? 1));
        self::assertNotEmpty(MigrationPayload::assocList($payload['catalogueNavigation']['existingProtectedLinks'] ?? []));
        self::assertTrue(MigrationPayload::bool($payload['catalogueNavigation']['noLinkWillBeCreatedThisTask'] ?? false));
    }

    public function testNameOnlyCatalogueMappingIsRejected(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'catalogueNavigation' => [
                'targetLiveConnected' => true,
                'legacyProducts' => [[
                    'styleCode' => 'F12',
                    'catalogueRelationships' => [[
                        'sourceKind' => 'taxonomy',
                        'legacyPath' => 'something-else',
                        'legacyTaxonomy' => 'category',
                        'legacyTermId' => '1',
                        'legacyTermSlug' => 'not-a-code',
                        'legacyTermName' => 'Accessories',
                    ]],
                ]],
                'target' => [
                    'tabs' => [['publicId' => 'tab-1', 'tabCode' => 'PRODUCTS', 'slug' => 'products']],
                    'nodes' => [['publicId' => 'node-1', 'tabPublicId' => 'tab-1', 'nodeCode' => 'PROD-ACCESSORIES', 'nodeName' => 'Accessories', 'slug' => 'accessories']],
                    'productNodeLinks' => [],
                ],
            ],
        ]))->toArray());
        self::assertSame(CatalogueNavigationDecision::REVIEW_TARGET_NODE_MISSING, $payload['catalogueNavigation']['decision'] ?? null);
        self::assertSame(0, MigrationPayload::int($payload['catalogueNavigation']['projectedLinks'] ?? 1));
    }

    public function testExplicitApprovedNodeMappingIsAccepted(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'targetLiveConnected' => true,
            'target' => ['liveConnected' => true, 'existingProducts' => [], 'existingVariants' => []],
            'catalogueNavigation' => [
                'targetLiveConnected' => true,
                'legacyProducts' => [[
                    'styleCode' => 'F12',
                    'catalogueRelationships' => [[
                        'sourceKind' => 'taxonomy',
                        'legacyPath' => 'conduit',
                        'legacyTaxonomy' => 'category',
                        'legacyTermId' => '538',
                        'legacyTermSlug' => 'conduit',
                    ]],
                ]],
                'explicitMaps' => [[
                    'legacyPath' => 'conduit',
                    'legacyTaxonomy' => 'category',
                    'legacyTermId' => '538',
                    'targetCatalogueNodeId' => 'node-1',
                    'targetNodeCode' => 'PROD-ACCESSORIES',
                    'operatorApproved' => true,
                ]],
                'target' => [
                    'tabs' => [['publicId' => 'tab-1', 'tabCode' => 'PRODUCTS', 'slug' => 'products']],
                    'nodes' => [['publicId' => 'node-1', 'tabPublicId' => 'tab-1', 'nodeCode' => 'PROD-ACCESSORIES', 'nodeName' => 'Accessories', 'slug' => 'accessories']],
                    'productNodeLinks' => [],
                ],
            ],
        ]))->toArray());
        self::assertSame(CatalogueNavigationDecision::MAP_EXISTING_NODE, $payload['catalogueNavigation']['decision'] ?? null);
    }

    public function testUnresolvedCatalogueCreatesZeroLinksAndAllowsDraftPilotWhenTargetLive(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'targetLiveConnected' => true,
            'target' => ['liveConnected' => true, 'existingProducts' => [], 'existingVariants' => []],
        ]))->toArray());
        self::assertFalse(MigrationPayload::bool($payload['readiness']['CATALOGUE_NAVIGATION_READY'] ?? true, true));
        self::assertSame(0, MigrationPayload::int($payload['mutationPlan']['catalogueRelationshipInserts'] ?? 1));
        self::assertTrue(MigrationPayload::bool($payload['readiness']['PRODUCT_DATA_PILOT_READY'] ?? false));
        self::assertSame('YES_WITH_CATALOGUE_NAVIGATION_REVIEW', $payload['readiness']['OVERALL_DRAFT_PILOT_READY'] ?? null);
        self::assertSame('Draft', $payload['publication'] ?? null);
        self::assertFalse(MigrationPayload::bool($payload['catalogueNavigation']['f12']['applyBlockedBySourceDrift'] ?? true, true));
        self::assertSame(0, MigrationPayload::int($payload['catalogueNavigation']['projectedLinks'] ?? 1));
    }

    public function testAzureUnavailablePreventsApplyReadiness(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        self::assertTrue(MigrationPayload::bool($payload['readiness']['TARGET_LIVE_REVALIDATION_REQUIRED'] ?? false));
        self::assertSame('NO', $payload['readiness']['OVERALL_DRAFT_PILOT_READY'] ?? null);
        self::assertSame('TARGET_LIVE_REVALIDATION_REQUIRED', $payload['targetCollision']['status'] ?? null);
    }

    public function testTargetCollisionBlocks(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input([
            'targetLiveConnected' => true,
            'target' => [
                'liveConnected' => true,
                'existingProducts' => [['styleCode' => 'F12', 'slug' => 'f12']],
                'existingVariants' => [],
            ],
        ]))->toArray());
        self::assertSame('BLOCKED', $payload['targetCollision']['status'] ?? null);
        self::assertFalse(MigrationPayload::bool($payload['readiness']['TARGET_COLLISION_READY'] ?? true, true));
        self::assertSame('NO', $payload['readiness']['OVERALL_DRAFT_PILOT_READY'] ?? null);
    }

    public function testPricingBlobAndTargetWritesAreZero(): void
    {
        $data = $this->service()->dryRun($this->input())->toArray();
        $payload = $this->payload($data);
        self::assertSame(0, MigrationPayload::int($data['wordpressWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['tcWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['targetWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['blobWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($data['pricingWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($payload['mutationPlan']['pricingWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($payload['mutationPlan']['blobWrites'] ?? 1));
        self::assertSame(0, MigrationPayload::int($payload['mutationPlan']['actualTargetWrites'] ?? 1));
        self::assertFalse(MigrationPayload::bool($data['applied'] ?? true, true));
    }

    public function testPreviousPackageIsSupersededAndHistoricalArtifactsRemain(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        self::assertSame('SUPERSEDED_BY_LIVE_SKU_RECONCILIATION', $payload['supersession']['previousStatus'] ?? null);
        self::assertSame('PILOT_SOURCE_DRIFT', $payload['supersession']['reasonSuperseded'] ?? null);
        self::assertTrue(MigrationPayload::bool($payload['supersession']['historicalEvidencePreserved'] ?? false));
        self::assertSame(0, MigrationPayload::int($payload['supersession']['previousApply']['productWrites'] ?? 1));
        $dir = dirname(__DIR__, 4) . '/docs/phase12.8';
        self::assertFileExists($dir . '/pilot-final-dry-run-package.json');
        self::assertFileExists($dir . '/pilot-f12-apply-result.json');
    }

    public function testRollbackAndIdempotencyProjection(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input())->toArray());
        self::assertFalse(MigrationPayload::bool($payload['rollbackProjection']['executed'] ?? true, true));
        self::assertSame('F12', $payload['rollbackProjection']['migrationOwned']['product'] ?? null);
        self::assertSame(0, MigrationPayload::int($payload['rollbackProjection']['catalogueRollbackRows'] ?? 1));
        self::assertSame(0, MigrationPayload::int($payload['idempotency']['secondExecution']['productDuplicateCreates'] ?? 1));
        self::assertSame(0, MigrationPayload::int($payload['idempotency']['secondExecution']['variantDuplicateCreates'] ?? 1));
        self::assertFalse(MigrationPayload::bool($payload['idempotency']['manualOverwrite'] ?? true, true));
    }

    public function testDeterministic(): void
    {
        $service = $this->service();
        $a = $this->payload($service->dryRun($this->input())->toArray());
        $b = $this->payload($service->dryRun($this->input())->toArray());
        unset($a['readiness']['publication'], $b['readiness']['publication']);
        self::assertSame($a['sourceInventory']['rows'], $b['sourceInventory']['rows']);
        self::assertSame($a['variants'], $b['variants']);
        self::assertSame($a['specifications']['projectedVariantSpecificationValues'], $b['specifications']['projectedVariantSpecificationValues']);
    }

    public function testNameOnlyIdentityIsBlocked(): void
    {
        $payload = $this->payload($this->service()->dryRun($this->input(['allowNameOnlyMatch' => true]))->toArray());
        self::assertSame('BLOCKED', $payload['identity']['decision'] ?? null);
        self::assertFalse(MigrationPayload::bool($payload['readiness']['IDENTITY_READY'] ?? true, true));
    }

    public function testCliScriptRefusesApplyFlagsAndPreservesHistoricalArtifacts(): void
    {
        $script = dirname(__DIR__, 5) . '/bin/run-f12-live-sku-pilot-dry-run.php';
        self::assertFileExists($script);
        $cmd = 'php ' . escapeshellarg($script) . ' --apply --style=F12 --confirm-non-production';
        $output = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $output, $exit);
        self::assertSame(3, $exit);
        self::assertStringContainsString(ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED, implode("\n", $output));
        $docs = dirname(__DIR__, 4) . '/docs/phase12.8';
        self::assertFileExists($docs . '/pilot-final-dry-run-package.json');
        self::assertFileExists($docs . '/pilot-f12-apply-result.json');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        return MigrationPayload::assoc($data['payload'] ?? []);
    }

    private function service(): ResolveF12LiveSkuPilotDryRunService
    {
        return new ResolveF12LiveSkuPilotDryRunService();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return MigrationPayload::assoc(array_replace_recursive([
            'styleCode' => 'F12',
            'forbiddenWriteAttempts' => 0,
            'tproExtStyleRowCount' => 0,
            'targetLiveConnected' => false,
            'liveSkuRows' => [
                [
                    'legacyRowId' => '1',
                    'productCode' => 'F12-AHXX/XD20GY',
                    'd1' => '20.00',
                    'd2' => '24.00',
                    'd3' => '22.00',
                    'd4' => '92.00',
                    'd5' => '55.00',
                    'd6' => '29.00',
                ],
                [
                    'legacyRowId' => '2',
                    'productCode' => 'F12-AHXX/XD25GY',
                    'd1' => '25.00',
                    'd2' => '29.00',
                    'd3' => '25.00',
                    'd4' => '108.00',
                    'd5' => '69.00',
                    'd6' => '37.00',
                ],
            ],
            'approvedMappings' => $this->f12Maps(),
            'wordpressIdentity' => [
                'postId' => 5877,
                'postType' => 'accessory',
                'title' => 'Inspection Tee',
            ],
            'wordpressContent' => ['title' => 'Inspection Tee'],
            'classification' => [
                'family' => 'ACCESSORIES',
                'category' => 'CONDUITS-FITTINGS',
                'series' => 'F12',
            ],
            'region' => ['resolvedRegions' => ['AU']],
            'target' => [
                'liveConnected' => false,
                'existingProducts' => [],
                'existingVariants' => [],
            ],
            'catalogueNavigation' => [
                'targetLiveConnected' => false,
                'legacyProducts' => [[
                    'styleCode' => 'F12',
                    'catalogueRelationships' => [[
                        'sourceKind' => 'taxonomy',
                        'legacyPath' => 'conduit',
                        'legacyTaxonomy' => 'category',
                        'legacyTermId' => '538',
                        'legacyTermSlug' => 'conduit',
                        'legacyTermName' => 'Flexible Conduit',
                    ]],
                ]],
                'explicitMaps' => [],
                'target' => [
                    'tabs' => [['publicId' => 'tab-1', 'tabCode' => 'PRODUCTS', 'slug' => 'products']],
                    'nodes' => [['publicId' => 'node-1', 'tabPublicId' => 'tab-1', 'nodeCode' => 'PROD-ACCESSORIES', 'nodeName' => 'Accessories', 'slug' => 'accessories']],
                    'productNodeLinks' => [],
                ],
            ],
            'previousPackage' => ['path' => 'core/docs/phase12.8/pilot-final-dry-run-package.json'],
        ], $overrides));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function f12Maps(): array
    {
        $rows = [
            ['d1', 'INNER-DIAMETER', 'Inner dia mm', '12.00'],
            ['d2', 'OUTER-DIAMETER', 'Outer dia mm', '15.00'],
            ['d3', 'DEPTH', 'Depth mm', '10.00'],
            ['d4', 'OVERALL-LENGTH', 'Length mm', '3.00'],
            ['d5', 'WIDTH', 'Width mm', '2.00'],
            ['d6', 'HEIGHT', 'Height mm', '4.30'],
        ];
        $out = [];
        foreach ($rows as [$field, $definition, $label, $sample]) {
            $out[] = [
                'styleCode' => 'F12',
                'sourceField' => $field,
                'legacyLabel' => $label,
                'targetDefinitionCode' => $definition,
                'status' => 'APPROVED_MAP',
                'reason' => 'batch-c2-approved',
                'sampleValues' => [$sample],
            ];
        }

        return $out;
    }
}
