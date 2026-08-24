<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\AssignProductRegionsCommand;
use TriCab\Core\Product\Application\Command\AssignVariantSpecificationsCommand;
use TriCab\Core\Product\Application\Command\ChangeProductSlugCommand;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\Command\CreateProductVariantCommand;
use TriCab\Core\Product\Application\Service\AssignProductRegionsService;
use TriCab\Core\Product\Application\Service\AssignVariantSpecificationsService;
use TriCab\Core\Product\Application\Service\ChangeProductSlugService;
use TriCab\Core\Product\Application\Service\CreateProductService;
use TriCab\Core\Product\Application\Service\CreateProductVariantService;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Phase 12.8 Step B — transactional F12 Inspection Tee pilot apply.
 *
 * Scope: Product F12 + Variant F12-01 + 6 variant specs + AU region + ownership.
 * Forbidden: pricing, blob, master-data creates, publish, other products, cables.
 */
final class ApplyProductMigrationPilotF12Service
{
    public const APPROVED_STYLE = 'F12';
    public const APPROVED_NAME = 'Inspection Tee';
    public const APPROVED_VARIANT = 'F12-01';
    public const APPROVED_SLUG = 'f12';
    public const APPROVED_FAMILY = 'ACCESSORIES';
    public const APPROVED_CATEGORY = 'CONDUITS-FITTINGS';
    public const APPROVED_SERIES = 'F12';
    public const APPROVED_REGION = 'AU';
    public const MIGRATION_BATCH_ID = 'phase12.8-f12-pilot';
    public const SOURCE_SYSTEM = 'tc';
    public const SOURCE_TABLE = 'accessory_specs';

    public const EXPECTED_DEFINITIONS = 64;
    public const EXPECTED_TOTAL_MAPPINGS = 1044;
    public const EXPECTED_CABLE_MAPPINGS = 538;

    /** @var list<array{legacyField: string, definition: string, value: string, unit: string, mappingProvenance: string}> */
    public const APPROVED_SPECS = [
        [
            'legacyField' => 'd1',
            'definition' => 'INNER-DIAMETER',
            'value' => '12.00',
            'unit' => 'MM',
            'mappingProvenance' => 'concept_approved_map_existing_INNER_DIAMETER_MM',
        ],
        [
            'legacyField' => 'd2',
            'definition' => 'OUTER-DIAMETER',
            'value' => '15.00',
            'unit' => 'MM',
            'mappingProvenance' => 'concept_approved_map_existing_OUTER_DIAMETER_MM',
        ],
        [
            'legacyField' => 'd3',
            'definition' => 'DEPTH',
            'value' => '10.00',
            'unit' => 'MM',
            'mappingProvenance' => 'concept_approved_map_existing_DEPTH_MM',
        ],
        [
            'legacyField' => 'd4',
            'definition' => 'OVERALL-LENGTH',
            'value' => '3.00',
            'unit' => 'MM',
            'mappingProvenance' => 'concept_approved_map_existing_OVERALL_LENGTH_MM',
        ],
        [
            'legacyField' => 'd5',
            'definition' => 'WIDTH',
            'value' => '2.00',
            'unit' => 'MM',
            'mappingProvenance' => 'concept_approved_map_existing_WIDTH_MM',
        ],
        [
            'legacyField' => 'd6',
            'definition' => 'HEIGHT',
            'value' => '4.30',
            'unit' => 'MM',
            'mappingProvenance' => 'concept_approved_map_existing_HEIGHT_MM',
        ],
    ];

    public function __construct(
        private readonly CreateProductService $createProduct,
        private readonly CreateProductVariantService $createVariant,
        private readonly AssignVariantSpecificationsService $assignVariantSpecs,
        private readonly AssignProductRegionsService $assignRegions,
        private readonly ChangeProductSlugService $changeSlug,
        private readonly ProductClassificationRepository $classification,
        private readonly ProductSpecificationRepository $specifications,
        private readonly ProductMarketRepository $markets,
        private readonly LegacyProductMappingRepository $legacyMappings,
        private readonly MigrationBatchRepository $batches,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * Independently enforce approved style regardless of CLI args.
     */
    public function assertApprovedStyle(string $styleCode): void
    {
        if (strtoupper(trim($styleCode)) !== self::APPROVED_STYLE) {
            throw new \RuntimeException(
                ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . ': approved style_code must be F12',
            );
        }
    }

    /**
     * @param array<string, mixed> $preflight
     */
    public function assertPreflight(array $preflight): void
    {
        $server = strtolower(MigrationPayload::string($preflight['server'] ?? ''));
        $database = strtolower(MigrationPayload::string($preflight['database'] ?? ''));
        $env = strtoupper(MigrationPayload::string($preflight['environmentLabel'] ?? $preflight['environment'] ?? ''));

        if (!str_contains($server, 'tricab-mel')) {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . ': server must be tricab-mel');
        }
        if ($database !== 'tc') {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . ': database must be tc');
        }
        if ($env !== 'NON-PRODUCTION') {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::NON_PRODUCTION_CONFIRMATION_REQUIRED);
        }
        if (($preflight['liveValidation'] ?? '') !== 'AVAILABLE') {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED . ': live validation unavailable');
        }
        if (!($preflight['operatorApprovalRecognised'] ?? false)) {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED);
        }

        $defs = is_numeric($preflight['definitionCount'] ?? null) ? (int) $preflight['definitionCount'] : -1;
        $maps = is_numeric($preflight['fieldMappingCount'] ?? null) ? (int) $preflight['fieldMappingCount'] : -1;
        $cable = is_numeric($preflight['cableFieldMappingCount'] ?? null) ? (int) $preflight['cableFieldMappingCount'] : -1;
        if ($defs !== self::EXPECTED_DEFINITIONS) {
            throw new \RuntimeException(
                ProductMigrationPilotCliGuard::TARGET_COLLISION . ': definitions expected '
                . self::EXPECTED_DEFINITIONS . ' got ' . $defs,
            );
        }
        if ($maps !== self::EXPECTED_TOTAL_MAPPINGS) {
            throw new \RuntimeException(
                ProductMigrationPilotCliGuard::TARGET_COLLISION . ': total field mappings expected '
                . self::EXPECTED_TOTAL_MAPPINGS . ' got ' . $maps,
            );
        }
        if ($cable !== self::EXPECTED_CABLE_MAPPINGS) {
            throw new \RuntimeException(
                ProductMigrationPilotCliGuard::TARGET_COLLISION . ': cable field mappings expected '
                . self::EXPECTED_CABLE_MAPPINGS . ' got ' . $cable,
            );
        }

        $collision = MigrationPayload::string($preflight['f12CollisionType'] ?? 'NONE');
        if ($collision === 'PRE_EXISTING_MANUAL' || $collision === 'CONFLICT') {
            throw new \RuntimeException(
                ProductMigrationPilotCliGuard::TARGET_COLLISION . ': F12 collision type ' . $collision,
            );
        }
    }

    /**
     * @param array<string, mixed> $legacySource
     * @param array<string, mixed> $approvedPackage
     */
    public function assertSourceMatchesPackage(array $legacySource, array $approvedPackage): void
    {
        $style = strtoupper(MigrationPayload::string($legacySource['style_code'] ?? ''));
        $name = trim(MigrationPayload::string($legacySource['name'] ?? $legacySource['productTitle'] ?? ''));
        if ($style !== self::APPROVED_STYLE || $name !== self::APPROVED_NAME) {
            throw new \RuntimeException(
                ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': identity style/name mismatch',
            );
        }

        $expected = [];
        foreach (self::APPROVED_SPECS as $spec) {
            $expected[$spec['legacyField']] = $spec['value'];
        }

        $actualFields = is_array($legacySource['specValues'] ?? null) ? $legacySource['specValues'] : [];
        foreach ($expected as $field => $value) {
            $actual = MigrationPayload::string($actualFields[$field] ?? '');
            if ($this->normalizeDecimal($actual) !== $this->normalizeDecimal($value)) {
                throw new \RuntimeException(
                    ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': field ' . $field
                    . ' expected ' . $value . ' got ' . $actual,
                );
            }
        }

        $projection = is_array($approvedPackage['specificationProjection'] ?? null)
            ? $approvedPackage['specificationProjection']
            : [];
        $pkgSpecs = is_array($projection['projectedVariantSpecificationValues'] ?? null)
            ? $projection['projectedVariantSpecificationValues']
            : [];
        if (count($pkgSpecs) !== 6) {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': package spec count');
        }
        foreach ($pkgSpecs as $row) {
            if (!is_array($row)) {
                continue;
            }
            $def = strtoupper(MigrationPayload::string($row['definition'] ?? ''));
            $val = MigrationPayload::string($row['value'] ?? '');
            $match = null;
            foreach (self::APPROVED_SPECS as $spec) {
                if ($spec['definition'] === $def) {
                    $match = $spec;
                    break;
                }
            }
            if ($match === null || $this->normalizeDecimal($val) !== $this->normalizeDecimal($match['value'])) {
                throw new \RuntimeException(
                    ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': package drift on ' . $def,
                );
            }
            // Outer-diameter must remain accessory Batch C mapping — never NOM-CABLE-OD.
            if ($def === 'OUTER-DIAMETER') {
                $legacySourceField = MigrationPayload::string($row['legacySource'] ?? '');
                $mappingSource = strtoupper(MigrationPayload::string($row['mappingSource'] ?? ''));
                if (!str_contains($legacySourceField, 'accessory_specs.d2')) {
                    throw new \RuntimeException(
                        ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': OUTER-DIAMETER provenance not accessory',
                    );
                }
                if (str_contains($mappingSource, 'NOM-CABLE-OD')) {
                    throw new \RuntimeException(
                        ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': cable OD rule leaked into F12',
                    );
                }
            }
        }
    }

    /**
     * @param array{
     *   requestedStyle: string,
     *   preflight: array<string, mixed>,
     *   legacySource: array<string, mixed>,
     *   approvedPackage: array<string, mixed>,
     *   existing?: array<string, mixed>|null
     * } $input
     * @return array<string, mixed>
     */
    public function apply(ActorContext $actor, array $input, bool $dryRun = false): array
    {
        $style = MigrationPayload::string($input['requestedStyle']);
        $this->assertApprovedStyle($style);
        $preflight = $input['preflight'];
        $legacySource = $input['legacySource'];
        $package = $input['approvedPackage'];
        $existing = $input['existing'] ?? null;

        $this->assertPreflight($preflight);
        $this->assertSourceMatchesPackage($legacySource, $package);

        $collisionType = MigrationPayload::string($preflight['f12CollisionType'] ?? 'NONE');
        $migrationOwned = $existing !== null && ($existing['migrationOwned'] ?? false) === true;
        if ($collisionType === 'IDENTICAL_MIGRATION_OWNED' || $migrationOwned) {
            return $this->identicalResult($existing ?? [], $actor, $dryRun);
        }

        if ($dryRun) {
            return [
                'status' => 'DRY_RUN',
                'applied' => false,
                'product' => $this->emptyEntityCounts(1),
                'variant' => $this->emptyEntityCounts(1),
                'variantSpecValues' => $this->emptyEntityCounts(6),
                'marketAssignments' => $this->emptyEntityCounts(1),
                'actualWrites' => $this->emptyWrites(),
                'rollbackExecuted' => false,
            ];
        }

        return $this->transaction->run(function () use ($actor, $existing): array {
            $writes = $this->emptyWrites();
            $classification = $this->resolveClassificationPublicIds();
            $regionPublicId = $this->resolveAuRegionPublicId();
            $definitionIds = $this->resolveDefinitionPublicIds();
            $existingState = is_array($existing) ? $existing : [];

            $productPublicId = MigrationPayload::stringOrNull($existingState['productPublicId'] ?? null);
            $productCreated = 0;
            $productIdentical = 0;

            if ($productPublicId === null || $productPublicId === '') {
                $created = $this->createProduct->execute($actor, new CreateProductCommand(
                    productName: self::APPROVED_NAME,
                    internalName: self::APPROVED_NAME,
                    internalCode: self::APPROVED_STYLE,
                    legacyProductId: self::APPROVED_STYLE,
                    familyPublicId: $classification['familyPublicId'],
                    categoryPublicId: $classification['categoryPublicId'],
                    seriesPublicId: $classification['seriesPublicId'],
                    statusCode: 'draft',
                    lifecycleStatusCode: 'concept',
                    isActive: true,
                ));
                $productPublicId = $created->publicId;
                $productCreated = 1;
                $writes['products'] = 1;

                $this->changeSlug->execute($actor, new ChangeProductSlugCommand(
                    productPublicId: $productPublicId,
                    newSlug: self::APPROVED_SLUG,
                    isPrimary: true,
                    regionPublicId: null,
                ));
                $writes['slugWrites'] = 1;
            } else {
                $productIdentical = 1;
            }

            $variantPublicId = MigrationPayload::stringOrNull($existingState['variantPublicId'] ?? null);
            $variantCreated = 0;
            $variantIdentical = 0;
            if ($variantPublicId === null || $variantPublicId === '') {
                $variant = $this->createVariant->execute($actor, new CreateProductVariantCommand(
                    productPublicId: $productPublicId,
                    variantName: self::APPROVED_NAME,
                    statusCode: 'draft',
                    displayOrder: 0,
                    variantCode: self::APPROVED_VARIANT,
                    isActive: true,
                ));
                $variantPublicId = $variant->publicId;
                $variantCreated = 1;
                $writes['variants'] = 1;
            } else {
                $variantIdentical = 1;
            }

            $existingSpecCount = is_numeric($existingState['variantSpecValueCount'] ?? null)
                ? (int) $existingState['variantSpecValueCount']
                : 0;
            $specsIdentical = 0;
            $specsInserted = 0;
            if ($existingSpecCount === 6 && ($existingState['variantSpecsMatch'] ?? false) === true) {
                $specsIdentical = 6;
            } else {
                $values = [];
                foreach (self::APPROVED_SPECS as $spec) {
                    $defId = $definitionIds[$spec['definition']] ?? null;
                    if ($defId === null) {
                        throw new \RuntimeException(
                            ProductMigrationPilotCliGuard::RECONCILIATION_FAILED
                            . ': missing definition ' . $spec['definition'],
                        );
                    }
                    $values[] = [
                        'definitionPublicId' => $defId,
                        'valueDecimal' => $spec['value'],
                    ];
                }
                $this->assignVariantSpecs->execute($actor, new AssignVariantSpecificationsCommand(
                    variantPublicId: $variantPublicId,
                    values: $values,
                ));
                $specsInserted = 6;
                $writes['variantSpecificationValues'] = 6;
            }

            $regionIdentical = 0;
            $regionInserted = 0;
            if (($existingState['auAssigned'] ?? false) === true) {
                $regionIdentical = 1;
            } else {
                $this->assignRegions->execute($actor, new AssignProductRegionsCommand(
                    productPublicId: $productPublicId,
                    regionPublicIds: [$regionPublicId],
                ));
                $regionInserted = 1;
                $writes['productRegions'] = 1;
            }

            $notes = json_encode([
                'migrationBatchId' => self::MIGRATION_BATCH_ID,
                'phase' => '12.8',
                'style_code' => self::APPROVED_STYLE,
                'name' => self::APPROVED_NAME,
                'variantCode' => self::APPROVED_VARIANT,
                'ownership' => 'migration-created',
                'manuallyEdited' => false,
                'outerDiameterGuard' => 'accessory_specs.d2→OUTER-DIAMETER (NOT cable od→NOM-CABLE-OD)',
                'specifications' => array_map(
                    static fn (array $s): array => [
                        'legacySource' => 'accessory_specs.' . $s['legacyField'],
                        'legacyField' => $s['legacyField'],
                        'legacyValue' => $s['value'],
                        'targetDefinition' => $s['definition'],
                        'normalizedValue' => $s['value'],
                        'mappingProvenance' => $s['mappingProvenance'],
                        'migrationBatch' => self::MIGRATION_BATCH_ID,
                    ],
                    self::APPROVED_SPECS,
                ),
            ], JSON_UNESCAPED_SLASHES);

            $this->legacyMappings->upsert([
                'sourceSystemCode' => self::SOURCE_SYSTEM,
                'sourceProductKey' => self::APPROVED_STYLE,
                'productPublicId' => $productPublicId,
                'productVariantPublicId' => $variantPublicId,
                'migrationStatusCode' => 'imported',
                'migrationNotes' => $notes !== false ? $notes : null,
            ]);
            $writes['legacyMappings'] = 1;

            $this->batches->upsertEntityMapping([
                'sourceSystem' => self::SOURCE_SYSTEM,
                'sourceTable' => self::SOURCE_TABLE,
                'sourceIdentifier' => self::APPROVED_STYLE,
                'sourceHash' => 'f12-pilot-v1',
                'targetEntityType' => 'product',
                'targetPublicId' => $productPublicId,
                'migrationStatus' => 'imported',
                'migrationBatchId' => self::MIGRATION_BATCH_ID,
                'manuallyEdited' => false,
            ]);
            $this->batches->upsertEntityMapping([
                'sourceSystem' => self::SOURCE_SYSTEM,
                'sourceTable' => self::SOURCE_TABLE,
                'sourceIdentifier' => self::APPROVED_VARIANT,
                'sourceHash' => 'f12-01-pilot-v1',
                'targetEntityType' => 'product_variant',
                'targetPublicId' => $variantPublicId,
                'migrationStatus' => 'imported',
                'migrationBatchId' => self::MIGRATION_BATCH_ID,
                'manuallyEdited' => false,
            ]);
            $writes['entityMappings'] = 2;

            $this->batches->recordAudit(
                null,
                'pilot-f12-apply',
                $actor->userId,
                $actor->correlationId,
                [
                    'style_code' => self::APPROVED_STYLE,
                    'productPublicId' => $productPublicId,
                    'variantPublicId' => $variantPublicId,
                    'migrationBatchId' => self::MIGRATION_BATCH_ID,
                    'specs' => 6,
                    'region' => self::APPROVED_REGION,
                    'publication' => 'draft',
                ],
            );
            $writes['auditEvents'] = 1;

            return [
                'status' => 'APPLIED',
                'applied' => true,
                'product' => [
                    'requested' => 1,
                    'created' => $productCreated,
                    'identical' => $productIdentical,
                    'conflicts' => 0,
                    'productPublicId' => $productPublicId,
                ],
                'variant' => [
                    'requested' => 1,
                    'created' => $variantCreated,
                    'identical' => $variantIdentical,
                    'conflicts' => 0,
                    'variantPublicId' => $variantPublicId,
                    'variantCode' => self::APPROVED_VARIANT,
                ],
                'variantSpecValues' => [
                    'requested' => 6,
                    'inserted' => $specsInserted,
                    'identical' => $specsIdentical,
                    'conflicts' => 0,
                ],
                'marketAssignments' => [
                    'requested' => 1,
                    'inserted' => $regionInserted,
                    'identical' => $regionIdentical,
                    'region' => self::APPROVED_REGION,
                ],
                'publication' => 'Draft',
                'actualWrites' => $writes,
                'rollbackExecuted' => false,
                'ownership' => [
                    'migrationBatchId' => self::MIGRATION_BATCH_ID,
                    'sourceSystem' => self::SOURCE_SYSTEM,
                    'sourceTable' => self::SOURCE_TABLE,
                    'sourceProductKey' => self::APPROVED_STYLE,
                ],
            ];
        });
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function identicalResult(array $existing, ActorContext $actor, bool $dryRun): array
    {
        $productPublicId = MigrationPayload::string($existing['productPublicId'] ?? '');
        $variantPublicId = MigrationPayload::string($existing['variantPublicId'] ?? '');

        if (!$dryRun) {
            $this->batches->recordAudit(
                null,
                'pilot-f12-apply-idempotent',
                $actor->userId,
                $actor->correlationId,
                [
                    'style_code' => self::APPROVED_STYLE,
                    'productPublicId' => $productPublicId,
                    'variantPublicId' => $variantPublicId,
                    'result' => 'identical',
                ],
            );
        }

        return [
            'status' => $dryRun ? 'DRY_RUN_IDENTICAL' : 'IDENTICAL',
            'applied' => false,
            'product' => [
                'requested' => 1,
                'created' => 0,
                'identical' => 1,
                'conflicts' => 0,
                'productPublicId' => $productPublicId,
            ],
            'variant' => [
                'requested' => 1,
                'created' => 0,
                'identical' => 1,
                'conflicts' => 0,
                'variantPublicId' => $variantPublicId,
                'variantCode' => self::APPROVED_VARIANT,
            ],
            'variantSpecValues' => [
                'requested' => 6,
                'inserted' => 0,
                'identical' => 6,
                'conflicts' => 0,
            ],
            'marketAssignments' => [
                'requested' => 1,
                'inserted' => 0,
                'identical' => 1,
                'region' => self::APPROVED_REGION,
            ],
            'publication' => 'Draft',
            'actualWrites' => $this->emptyWrites(),
            'rollbackExecuted' => false,
            'ownership' => [
                'migrationBatchId' => self::MIGRATION_BATCH_ID,
                'sourceSystem' => self::SOURCE_SYSTEM,
                'sourceTable' => self::SOURCE_TABLE,
                'sourceProductKey' => self::APPROVED_STYLE,
            ],
        ];
    }

    /**
     * @return array{familyPublicId: string, categoryPublicId: string, seriesPublicId: string}
     */
    private function resolveClassificationPublicIds(): array
    {
        $familyPublicId = null;
        foreach ($this->classification->listFamilies(true) as $family) {
            if (strtoupper($family->code) === self::APPROVED_FAMILY) {
                $familyPublicId = $family->publicId;
                break;
            }
        }
        if ($familyPublicId === null) {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::RECONCILIATION_FAILED . ': ACCESSORIES family missing');
        }

        $categoryPublicId = null;
        foreach ($this->classification->listCategories($familyPublicId) as $category) {
            if (strtoupper($category->code) === self::APPROVED_CATEGORY) {
                $categoryPublicId = $category->publicId;
                break;
            }
        }
        if ($categoryPublicId === null) {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::RECONCILIATION_FAILED . ': CONDUITS-FITTINGS missing');
        }

        $seriesPublicId = null;
        foreach ($this->classification->listSeries($categoryPublicId) as $series) {
            if (strtoupper($series->code) === self::APPROVED_SERIES) {
                $seriesPublicId = $series->publicId;
                break;
            }
        }
        if ($seriesPublicId === null) {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::RECONCILIATION_FAILED . ': series F12 missing');
        }

        return [
            'familyPublicId' => $familyPublicId,
            'categoryPublicId' => $categoryPublicId,
            'seriesPublicId' => $seriesPublicId,
        ];
    }

    private function resolveAuRegionPublicId(): string
    {
        foreach ($this->markets->listRegions(true) as $region) {
            if (strtoupper($region->code) === self::APPROVED_REGION) {
                return $region->publicId;
            }
        }

        throw new \RuntimeException(ProductMigrationPilotCliGuard::RECONCILIATION_FAILED . ': AU region missing');
    }

    /**
     * @return array<string, string>
     */
    private function resolveDefinitionPublicIds(): array
    {
        $out = [];
        foreach (self::APPROVED_SPECS as $spec) {
            $def = $this->specifications->findDefinitionByCode($spec['definition']);
            if ($def === null) {
                throw new \RuntimeException(
                    ProductMigrationPilotCliGuard::RECONCILIATION_FAILED
                    . ': definition missing ' . $spec['definition'],
                );
            }
            // Guard: never confuse OUTER-DIAMETER with NOM-CABLE-OD.
            if ($spec['definition'] === 'OUTER-DIAMETER' && strtoupper($def->code) === 'NOM-CABLE-OD') {
                throw new \RuntimeException(ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': OUTER-DIAMETER resolved to NOM-CABLE-OD');
            }
            $out[$spec['definition']] = $def->publicId;
        }

        $cableOd = $this->specifications->findDefinitionByCode('NOM-CABLE-OD');
        if ($cableOd !== null && $out['OUTER-DIAMETER'] === $cableOd->publicId) {
            throw new \RuntimeException(ProductMigrationPilotCliGuard::SOURCE_DRIFT . ': OUTER-DIAMETER === NOM-CABLE-OD');
        }

        return $out;
    }

    /**
     * @return array{requested: int, created: int, identical: int, conflicts: int}
     */
    private function emptyEntityCounts(int $requested): array
    {
        return [
            'requested' => $requested,
            'created' => 0,
            'identical' => 0,
            'conflicts' => 0,
        ];
    }

    /** @return array<string, int> */
    public function emptyWrites(): array
    {
        return [
            'products' => 0,
            'variants' => 0,
            'productSpecificationValues' => 0,
            'variantSpecificationValues' => 0,
            'productRegions' => 0,
            'definitions' => 0,
            'units' => 0,
            'options' => 0,
            'fieldMappings' => 0,
            'classification' => 0,
            'pricing' => 0,
            'media' => 0,
            'documents' => 0,
            'blob' => 0,
            'wordpress' => 0,
            'tcLegacy' => 0,
            'slugWrites' => 0,
            'legacyMappings' => 0,
            'entityMappings' => 0,
            'auditEvents' => 0,
        ];
    }

    private function normalizeDecimal(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return $trimmed;
        }

        return number_format((float) $trimmed, 2, '.', '');
    }
}
