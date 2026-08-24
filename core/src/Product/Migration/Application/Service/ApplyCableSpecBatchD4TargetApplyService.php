<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertSpecificationDefinitionCommand;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Service\SpecificationDefinitionService;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Phase 12.7 Batch D.4 — controlled cable spec target apply.
 * Creates 9 approved Definitions and inserts 538 approved cable style-field mappings.
 * Never writes Products/Variants/values/classification/catalogue/pricing/media or legacy DBs.
 */
final class ApplyCableSpecBatchD4TargetApplyService
{
  public const EXPECTED_DEFINITIONS_PRE = 55;
  public const EXPECTED_DEFINITIONS_POST = 64;
  public const EXPECTED_STYLE_MAPPINGS_PRE = 483;
  public const EXPECTED_TOTAL_MAPPINGS_PRE = 506;
  public const EXPECTED_TOTAL_MAPPINGS_POST = 1044;
  public const EXPECTED_MAPPING_PACKAGE_COUNT = 538;

    /** @return array<string, array{name: string, group: string, dataType: string, unitCode: ?string, sourceField: string}> */
    public static function requiredCreatesMeta(): array
    {
        return self::REQUIRED_CREATES;
    }

    /** @var list<string> */
    public const REQUIRED_CREATE_CODES = [
        'EARTH-CORES',
        'EARTH-CSA',
        'EARTH-INS-OD',
        'BEDDING-OD',
        'ARMOUR-OD',
        'BRAID-OD',
        'CURRENT-30C',
        'CURRENT-40C',
        'CURRENT-45C',
    ];

  /** @var list<string> */
  public const REQUIRED_EXISTING_DEFINITION_CODES = [
        'CORES',
        'CSA',
        'NOM-CABLE-OD',
        'NOM-COND-OD',
        'NOM-INS-OD',
        'MIN-BEND-RADIUS',
        'APPROX-WEIGHT',
    ];

    /** @var array<string, array{name: string, group: string, dataType: string, unitCode: ?string, sourceField: string}> */
    private const REQUIRED_CREATES = [
        'EARTH-CORES' => [
            'name' => 'Earth Cores',
            'group' => 'CONSTRUCTION',
            'dataType' => 'integer',
            'unitCode' => null,
            'sourceField' => 'ne',
        ],
        'EARTH-CSA' => [
            'name' => 'Earth CSA',
            'group' => 'CONSTRUCTION',
            'dataType' => 'decimal',
            'unitCode' => 'MM2',
            'sourceField' => 'xae',
        ],
        'EARTH-INS-OD' => [
            'name' => 'Earth Insulation OD',
            'group' => 'MECHANICAL',
            'dataType' => 'decimal',
            'unitCode' => 'MM',
            'sourceField' => 'ode_ins',
        ],
        'BEDDING-OD' => [
            'name' => 'Bedding OD',
            'group' => 'MECHANICAL',
            'dataType' => 'decimal',
            'unitCode' => 'MM',
            'sourceField' => 'od_bed',
        ],
        'ARMOUR-OD' => [
            'name' => 'Armour OD',
            'group' => 'MECHANICAL',
            'dataType' => 'decimal',
            'unitCode' => 'MM',
            'sourceField' => 'od_arm',
        ],
        'BRAID-OD' => [
            'name' => 'Braid OD',
            'group' => 'MECHANICAL',
            'dataType' => 'decimal',
            'unitCode' => 'MM',
            'sourceField' => 'od_brd',
        ],
        'CURRENT-30C' => [
            'name' => 'Current Rating at 30°C',
            'group' => 'ELECTRICAL',
            'dataType' => 'decimal',
            'unitCode' => 'A',
            'sourceField' => 'A30c',
        ],
        'CURRENT-40C' => [
            'name' => 'Current Rating at 40°C',
            'group' => 'ELECTRICAL',
            'dataType' => 'decimal',
            'unitCode' => 'A',
            'sourceField' => 'A40c',
        ],
        'CURRENT-45C' => [
            'name' => 'Current Rating at 45°C',
            'group' => 'ELECTRICAL',
            'dataType' => 'decimal',
            'unitCode' => 'A',
            'sourceField' => 'A45c',
        ],
    ];

    public function __construct(
        private readonly SpecificationDefinitionService $definitions,
        private readonly MigrationBatchRepository $batches,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $approvedFieldMappings
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    public function apply(
        ActorContext $actor,
        array $approvedFieldMappings,
        array $preflight,
        bool $dryRun = false,
    ): array {
        $this->assertPreflight($preflight);
        $this->assertMappingPackage($approvedFieldMappings);

        $plan = $this->buildPlan($approvedFieldMappings, $preflight);

        if ($dryRun) {
            return [
                'status' => 'DRY_RUN',
                'applied' => false,
                'plan' => $plan,
                'actualWrites' => $this->emptyWrites(),
            ];
        }

        /** @phpstan-ignore argument.unresolvableType */
        return $this->transaction->run(function () use ($actor, $approvedFieldMappings, $preflight, $plan): array {
            $groups = is_array($preflight['groups'] ?? null) ? $preflight['groups'] : [];
            $units = is_array($preflight['units'] ?? null) ? $preflight['units'] : [];
            $definitions = is_array($preflight['definitions'] ?? null) ? $preflight['definitions'] : [];
            $groupByCode = $this->indexByCode($groups);
            $unitByCode = $this->indexUnits($units);
            $defByCode = $this->indexByCode($definitions);
            $writes = $this->emptyWrites();
            $createdDefinitions = [];

            foreach (self::REQUIRED_CREATES as $code => $meta) {
                if (isset($defByCode[$code])) {
                    $createdDefinitions[$code] = [
                        'code' => $code,
                        'publicId' => $defByCode[$code]['publicId'],
                        'created' => false,
                        'reusedExisting' => true,
                    ];
                    $writes['definitionsAlreadyExisting']++;
                    continue;
                }

                $groupCode = $this->normalizeGroupCode($meta['group']);
                if (!isset($groupByCode[$groupCode])) {
                    throw new \RuntimeException(
                        CableSpecBatchD4CliGuard::DEFINITION_CONFLICT . ': group missing for ' . $code . ' → ' . $groupCode,
                    );
                }

                $unitString = null;
                $unitPublicId = null;
                $unitCode = $meta['unitCode'];
                if (is_string($unitCode) && $unitCode !== '') {
                    if (!isset($unitByCode[$unitCode])) {
                        throw new \RuntimeException('Unit missing for Definition create: ' . $code . ' → ' . $unitCode);
                    }
                    $unitPublicId = (string) $unitByCode[$unitCode]['publicId'];
                    $symbol = trim((string) ($unitByCode[$unitCode]['symbol'] ?? ''));
                    $unitString = $symbol !== '' ? $symbol : (string) $unitByCode[$unitCode]['code'];
                }

                try {
                    $record = $this->definitions->create($actor, new UpsertSpecificationDefinitionCommand(
                        name: $meta['name'],
                        code: $code,
                        dataTypeCode: $meta['dataType'],
                        scopeCode: 'product',
                        groupPublicId: (string) $groupByCode[$groupCode]['publicId'],
                        description: 'Batch D.4 operator-approved cable Specification Definition',
                        unit: $unitString,
                        unitPublicId: $unitPublicId,
                        displayOrder: 400 + count($createdDefinitions),
                        isActive: true,
                    ));
                } catch (DuplicateSpecificationCode $e) {
                    throw new \RuntimeException(
                        CableSpecBatchD4CliGuard::DEFINITION_CONFLICT . ': duplicate code ' . $code,
                        0,
                        $e,
                    );
                }

                $writes['definitionsCreated']++;
                $defByCode[$code] = [
                    'code' => $code,
                    'name' => $record->name,
                    'publicId' => $record->publicId,
                    'dataTypeCode' => $record->dataTypeCode,
                    'unit' => $record->unit,
                    'groupCode' => $groupCode,
                ];
                $createdDefinitions[$code] = [
                    'code' => $code,
                    'publicId' => $record->publicId,
                    'created' => true,
                    'reusedExisting' => false,
                ];
            }

            if (isset($defByCode['IND-LSZH-FIRE'])) {
                throw new \RuntimeException('Forbidden IND-LSZH-FIRE Definition exists or was created');
            }

            foreach (self::REQUIRED_EXISTING_DEFINITION_CODES as $existingCode) {
                if (!isset($defByCode[$existingCode])) {
                    throw new \RuntimeException('Required existing Definition missing: ' . $existingCode);
                }
            }

            $nomCable = $defByCode['NOM-CABLE-OD'];
            $outerDia = $defByCode['OUTER-DIAMETER'] ?? null;
            if ($outerDia !== null && (string) ($nomCable['publicId'] ?? '') === (string) ($outerDia['publicId'] ?? '')) {
                throw new \RuntimeException('Semantic guard failed: NOM-CABLE-OD aliased to OUTER-DIAMETER');
            }

            $mappingResults = [];
            foreach ($approvedFieldMappings as $row) {
                $status = (string) ($row['status'] ?? '');
                if (!in_array($status, [
                    ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_MAP_EXISTING,
                    ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_PENDING_DEFINITION,
                    'APPROVED_MAP',
                    'APPROVED_PENDING_DEFINITION',
                ], true)) {
                    continue;
                }

                $style = strtoupper(trim((string) ($row['styleCode'] ?? '')));
                $field = strtolower(trim((string) ($row['sourceField'] ?? $row['legacyField'] ?? '')));
                $targetCode = strtoupper(trim((string) ($row['targetDefinitionCode'] ?? '')));
                if ($style === '' || $field === '' || $targetCode === '') {
                    throw new \RuntimeException('Invalid approved mapping row: ' . json_encode($row));
                }
                if ($targetCode === 'OUTER-DIAMETER' && $field === 'od') {
                    throw new \RuntimeException('Blocked: od must map to NOM-CABLE-OD, never OUTER-DIAMETER');
                }
                if ($targetCode === 'CURRENT' && in_array($field, ['A30c', 'A40c', 'A45c'], true)) {
                    throw new \RuntimeException('Blocked: ambient current must not map to generic CURRENT');
                }
                if (!isset($defByCode[$targetCode])) {
                    throw new \RuntimeException('Mapping target Definition unresolved: ' . $targetCode);
                }

                $result = $this->upsertStyleMappingIdempotent($actor, [
                    'sourceSystem' => MigrationPayload::string($row['sourceSystem'] ?? 'tc'),
                    'sourceTable' => MigrationPayload::string($row['sourceTable'] ?? 'cable_specs'),
                    'sourceField' => $field,
                    'legacyStyleCode' => $style,
                    'targetSpecificationDefinitionPublicId' => (string) $defByCode[$targetCode]['publicId'],
                    'targetDefinitionCode' => $targetCode,
                    'targetScopeCode' => 'product',
                    'transformationRule' => 'text',
                    'sourceUnit' => null,
                    'targetUnit' => $defByCode[$targetCode]['unit'] ?? null,
                    'statusCode' => 'mapped',
                    'notes' => json_encode([
                        'batch' => '12.7-batch-d4',
                        'status' => $status,
                        'decisionSource' => $row['decisionSource'] ?? 'operator D.2',
                    ], JSON_UNESCAPED_SLASHES),
                ]);

                if ($result['conflict']) {
                    throw new \RuntimeException(
                        CableSpecBatchD4CliGuard::MAPPING_CONFLICT
                        . ' style=' . $style . ' field=' . $field
                        . ' existing=' . ($result['existingTarget'] ?? '')
                        . ' approved=' . $targetCode,
                    );
                }
                if ($result['inserted']) {
                    $writes['mappingsInserted']++;
                } elseif ($result['identical']) {
                    $writes['mappingsIdenticalExisting']++;
                } elseif ($result['updated']) {
                    $writes['mappingsUpdated']++;
                }

                $mappingResults[] = [
                    'styleCode' => $style,
                    'sourceField' => $field,
                    'targetDefinitionCode' => $targetCode,
                    'inserted' => $result['inserted'],
                    'identical' => $result['identical'],
                ];
            }

            return [
                'status' => 'APPLIED',
                'applied' => true,
                'plan' => $plan,
                'actualWrites' => $writes,
                'createdDefinitions' => array_values($createdDefinitions),
                'mappingResultsCount' => count($mappingResults),
            ];
        });
    }

    /**
     * @param list<array<string, mixed>> $approvedFieldMappings
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    public function buildPlan(array $approvedFieldMappings, array $preflight): array
    {
        $defByCode = $this->indexByCode(is_array($preflight['definitions'] ?? null) ? $preflight['definitions'] : []);
        $creates = [];
        foreach (self::REQUIRED_CREATES as $code => $meta) {
            $creates[$code] = $meta;
            if (isset($defByCode[$code])) {
                $creates[$code]['alreadyExists'] = true;
            }
        }

        $approvedStatuses = [
            ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_MAP_EXISTING,
            ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_PENDING_DEFINITION,
            'APPROVED_MAP',
            'APPROVED_PENDING_DEFINITION',
        ];
        $toInsert = 0;
        foreach ($approvedFieldMappings as $row) {
            if (in_array((string) ($row['status'] ?? ''), $approvedStatuses, true)) {
                ++$toInsert;
            }
        }

        return [
            'definitionsToCreate' => array_keys(self::REQUIRED_CREATES),
            'definitionsAlreadyExisting' => array_values(array_filter(
                self::REQUIRED_CREATE_CODES,
                static fn (string $code): bool => isset($defByCode[$code]),
            )),
            'fieldMappingsRequested' => $toInsert,
            'forbiddenIndLszhFire' => true,
            'odMapsToNomCableOd' => true,
            'odNeverOuterDiameter' => true,
            'ambientNotGenericCurrent' => true,
            'earthCsaDecimal' => self::REQUIRED_CREATES['EARTH-CSA']['dataType'] === 'decimal',
        ];
    }

    /**
     * @param list<array<string, mixed>> $approvedFieldMappings
     */
    public function assertMappingPackage(array $approvedFieldMappings): void
    {
        $count = 0;
        $approvedStatuses = [
            ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_MAP_EXISTING,
            ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_PENDING_DEFINITION,
            'APPROVED_MAP',
            'APPROVED_PENDING_DEFINITION',
        ];
        foreach ($approvedFieldMappings as $row) {
            if (in_array((string) ($row['status'] ?? ''), $approvedStatuses, true)) {
                ++$count;
            }
        }
        if ($count !== self::EXPECTED_MAPPING_PACKAGE_COUNT) {
            throw new \RuntimeException(
                'Approved mapping package count ' . $count . ' !== expected ' . self::EXPECTED_MAPPING_PACKAGE_COUNT,
            );
        }
    }

    /**
     * @param array<string, mixed> $preflight
     */
    public function assertPreflight(array $preflight): void
    {
        $server = MigrationPayload::string($preflight['server'] ?? '');
        $database = MigrationPayload::string($preflight['database'] ?? '');
        if (!str_contains(strtolower($server), 'tricab-mel')) {
            throw new \RuntimeException('Refusing apply: server is not tricab-mel (' . $server . ')');
        }
        if (strtolower($database) !== 'tc') {
            throw new \RuntimeException('Refusing apply: database is not tc (' . $database . ')');
        }
        if (MigrationPayload::string($preflight['environmentLabel'] ?? '') !== 'NON-PRODUCTION') {
            throw new \RuntimeException('Refusing apply: environment is not NON-PRODUCTION');
        }
        if (MigrationPayload::string($preflight['liveValidation'] ?? '') !== 'AVAILABLE') {
            throw new \RuntimeException('Preflight failed: live Azure validation not AVAILABLE');
        }
        if (!($preflight['operatorApprovalRecognised'] ?? false)) {
            throw new \RuntimeException(CableSpecBatchD4CliGuard::APPLY_NOT_APPROVED);
        }
        if (!($preflight['artifactsValid'] ?? false)) {
            throw new \RuntimeException('Preflight failed: D.3 artifacts invalid');
        }
        if (!($preflight['d3PackageValid'] ?? false)) {
            throw new \RuntimeException('Preflight failed: D.3 package invalid');
        }
        if ((int) ($preflight['mappingConflicts'] ?? 0) !== 0) {
            throw new \RuntimeException(CableSpecBatchD4CliGuard::MAPPING_CONFLICT);
        }
        if ((int) ($preflight['definitionConflicts'] ?? 0) !== 0) {
            throw new \RuntimeException(CableSpecBatchD4CliGuard::DEFINITION_CONFLICT);
        }

        $defCount = (int) ($preflight['definitionCount'] ?? 0);
        if ($defCount !== self::EXPECTED_DEFINITIONS_PRE && $defCount !== self::EXPECTED_DEFINITIONS_POST) {
            throw new \RuntimeException(
                CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT
                . ': unexpected Definition count ' . $defCount,
            );
        }

        $unitCount = (int) ($preflight['unitCount'] ?? 0);
        if ($unitCount !== 11) {
            throw new \RuntimeException(CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT . ': expected 11 Units');
        }

        $optionCount = (int) ($preflight['optionCount'] ?? 0);
        if ($optionCount !== 416) {
            throw new \RuntimeException(CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT . ': expected 416 Options');
        }

        $styleMaps = (int) ($preflight['styleFieldMappingCount'] ?? 0);
        $totalMaps = (int) ($preflight['fieldMappingCount'] ?? 0);
        if ($defCount === self::EXPECTED_DEFINITIONS_PRE) {
            if ($styleMaps !== self::EXPECTED_STYLE_MAPPINGS_PRE) {
                throw new \RuntimeException(
                    CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT
                    . ': expected ' . self::EXPECTED_STYLE_MAPPINGS_PRE . ' style mappings, got ' . $styleMaps,
                );
            }
            if ($totalMaps !== self::EXPECTED_TOTAL_MAPPINGS_PRE) {
                throw new \RuntimeException(
                    CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT
                    . ': expected ' . self::EXPECTED_TOTAL_MAPPINGS_PRE . ' total mappings, got ' . $totalMaps,
                );
            }
        } elseif ($defCount === self::EXPECTED_DEFINITIONS_POST) {
            if ($totalMaps !== self::EXPECTED_TOTAL_MAPPINGS_POST) {
                throw new \RuntimeException(
                    CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT
                    . ': expected ' . self::EXPECTED_TOTAL_MAPPINGS_POST . ' total mappings post-apply, got ' . $totalMaps,
                );
            }
        }

        $defs = is_array($preflight['definitions'] ?? null) ? $preflight['definitions'] : [];
        $byCode = $this->indexByCode($defs);
        foreach (self::REQUIRED_EXISTING_DEFINITION_CODES as $code) {
            if (!isset($byCode[$code])) {
                throw new \RuntimeException(
                    CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT . ': missing required existing Definition ' . $code,
                );
            }
        }

        if ($defCount === self::EXPECTED_DEFINITIONS_PRE) {
            foreach (self::REQUIRED_CREATE_CODES as $code) {
                if (isset($byCode[$code])) {
                    throw new \RuntimeException(
                        CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT
                        . ': unexpected pre-apply Definition already exists: ' . $code,
                    );
                }
            }
        }

        if ($defCount === self::EXPECTED_DEFINITIONS_POST) {
            foreach (self::REQUIRED_CREATE_CODES as $code) {
                if (!isset($byCode[$code])) {
                    throw new \RuntimeException(
                        CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT
                        . ': post-apply Definition missing: ' . $code,
                    );
                }
            }
        }

        foreach (['products' => 118, 'variants' => 4178, 'categories' => 13, 'series' => 97, 'catalogueNodes' => 97] as $key => $expected) {
            if (isset($preflight[$key]) && (int) $preflight[$key] !== $expected) {
                throw new \RuntimeException(
                    CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT
                    . ": {$key} drift expected {$expected} got " . (string) (int) $preflight[$key],
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{inserted: bool, updated: bool, identical: bool, conflict: bool, existingTarget: ?string, row: array<string, mixed>}
     */
    private function upsertStyleMappingIdempotent(ActorContext $actor, array $data): array
    {
        $sourceSystem = MigrationPayload::string($data['sourceSystem'] ?? '');
        $sourceTable = MigrationPayload::string($data['sourceTable'] ?? '');
        $sourceField = MigrationPayload::string($data['sourceField'] ?? '');
        $style = strtoupper(MigrationPayload::string($data['legacyStyleCode'] ?? ''));
        $targetCode = MigrationPayload::string($data['targetDefinitionCode'] ?? '');
        $targetId = MigrationPayload::string($data['targetSpecificationDefinitionPublicId'] ?? '');

        foreach ($this->batches->listSpecificationMappings() as $row) {
            $rowStyle = strtoupper(trim(MigrationPayload::string($row['legacyStyleCode'] ?? '')));
            if (MigrationPayload::string($row['sourceSystem'] ?? '') === $sourceSystem
                && MigrationPayload::string($row['sourceTable'] ?? '') === $sourceTable
                && MigrationPayload::string($row['sourceField'] ?? '') === $sourceField
                && $rowStyle === $style
            ) {
                $existingTarget = strtoupper(MigrationPayload::string($row['targetDefinitionCode'] ?? ''));
                $same = $existingTarget === $targetCode
                    && MigrationPayload::string($row['targetSpecificationDefinitionPublicId'] ?? '') === $targetId
                    && MigrationPayload::string($row['statusCode'] ?? '') === 'mapped';
                if ($same) {
                    return [
                        'inserted' => false,
                        'updated' => false,
                        'identical' => true,
                        'conflict' => false,
                        'existingTarget' => $existingTarget,
                        'row' => $row,
                    ];
                }

                return [
                    'inserted' => false,
                    'updated' => false,
                    'identical' => false,
                    'conflict' => true,
                    'existingTarget' => $existingTarget,
                    'row' => $row,
                ];
            }
        }

        $inserted = $this->batches->upsertSpecificationMapping('new', array_merge($data, [
            'mappedByUserId' => $actor->userId,
        ]));

        return [
            'inserted' => true,
            'updated' => false,
            'identical' => false,
            'conflict' => false,
            'existingTarget' => null,
            'row' => $inserted,
        ];
    }

    private function normalizeGroupCode(string $group): string
    {
        return strtoupper(trim($group));
    }

    /**
     * @return array<string, int>
     */
    private function emptyWrites(): array
    {
        return [
            'definitionsCreated' => 0,
            'definitionsAlreadyExisting' => 0,
            'mappingsInserted' => 0,
            'mappingsIdenticalExisting' => 0,
            'mappingsUpdated' => 0,
            'specificationValues' => 0,
            'products' => 0,
            'variants' => 0,
            'classification' => 0,
            'catalogue' => 0,
            'pricing' => 0,
            'media' => 0,
            'documents' => 0,
            'legacy' => 0,
        ];
    }

    /**
     * @param array<mixed> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexByCode(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $row */
            $code = strtoupper(MigrationPayload::string($row['code'] ?? $row['groupCode'] ?? $row['definitionCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexUnits(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array<string, mixed> $row */
            $code = strtoupper(MigrationPayload::string($row['code'] ?? $row['unitCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }
}
