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
 * Phase 12.7 Batch C.2 controlled target apply.
 * Creates 13 approved Definitions and inserts approved style-field mappings.
 * Never writes Products/Variants/values/classification/catalogue/pricing/media or legacy DBs.
 */
final class ApplyBatchC2StyleFieldMappingService
{
    /** @var array<string, array{name: string, group: string, dataType: string, unitCode: ?string}> */
    private const REQUIRED_CREATES = [
        'OVERALL-LENGTH' => ['name' => 'Overall Length', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'WIDTH' => ['name' => 'Width', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'HEIGHT' => ['name' => 'Height', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'INNER-DIAMETER' => ['name' => 'Inner Diameter', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'CABLE-LENGTH' => ['name' => 'Cable Length', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'M'],
        'BARREL-LENGTH' => ['name' => 'Barrel Length', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'STUD-OD' => ['name' => 'Stud OD', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'OUTER-DIAMETER' => ['name' => 'Outer Diameter', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'THREAD-LENGTH' => ['name' => 'Thread Length', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'BARREL-OD' => ['name' => 'Barrel OD', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'THICKNESS' => ['name' => 'Thickness', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'DEPTH' => ['name' => 'Depth', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
        'PIN-DIAMETER' => ['name' => 'Pin Diameter', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
    ];

    public function __construct(
        private readonly SpecificationDefinitionService $definitions,
        private readonly MigrationBatchRepository $batches,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $approvedDefinitions
     * @param list<array<string, mixed>> $approvedFieldMappings
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    public function apply(
        ActorContext $actor,
        array $approvedDefinitions,
        array $approvedFieldMappings,
        array $preflight,
        bool $dryRun = false,
    ): array {
        $this->assertPreflight($preflight);
        $plan = $this->buildPlan($approvedDefinitions, $approvedFieldMappings, $preflight);

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
                $groupCode = $meta['group'];
                if (!isset($groupByCode[$groupCode])) {
                    throw new \RuntimeException('Group missing for Definition create: ' . $code . ' → ' . $groupCode);
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

                try {
                    $record = $this->definitions->create($actor, new UpsertSpecificationDefinitionCommand(
                        name: $meta['name'],
                        code: $code,
                        dataTypeCode: $meta['dataType'],
                        scopeCode: 'product',
                        groupPublicId: (string) $groupByCode[$groupCode]['publicId'],
                        description: 'Batch C.2 operator-approved Specification Definition',
                        unit: $unitString,
                        unitPublicId: $unitPublicId,
                        displayOrder: 300 + count($createdDefinitions),
                        isActive: true,
                    ));
                } catch (DuplicateSpecificationCode $e) {
                    throw new \RuntimeException('Definition code collision during create: ' . $code, 0, $e);
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

            // Hard safety: never alias OUTER-DIAMETER to NOM-CABLE-OD
            if (!isset($defByCode['OUTER-DIAMETER'])) {
                throw new \RuntimeException('OUTER-DIAMETER missing after create step');
            }

            $insertedMappings = [];
            foreach ($approvedFieldMappings as $row) {
                $status = (string) ($row['status'] ?? '');
                if (!in_array($status, ['APPROVED_MAP', 'APPROVED_PENDING_DEFINITION'], true)) {
                    continue;
                }
                $style = strtoupper(trim((string) ($row['styleCode'] ?? '')));
                $field = strtolower(trim((string) ($row['sourceField'] ?? '')));
                $targetCode = strtoupper(trim((string) ($row['targetDefinitionCode'] ?? '')));
                if ($style === '' || $style === '*' || $field === '' || $targetCode === '') {
                    throw new \RuntimeException('Invalid approved mapping row: ' . json_encode($row));
                }
                if ($targetCode === 'NOM-CABLE-OD' && in_array(strtolower((string) ($row['normalizedLabel'] ?? '')), ['outerdia mm', 'outer dia mm'], true)) {
                    throw new \RuntimeException('Blocked: accessory outer diameter must not map to NOM-CABLE-OD');
                }
                if (!isset($defByCode[$targetCode])) {
                    throw new \RuntimeException('Mapping target Definition unresolved: ' . $targetCode);
                }

                $result = $this->upsertStyleMappingIdempotent($actor, [
                    'sourceSystem' => MigrationPayload::string($row['sourceSystem'] ?? 'tc'),
                    'sourceTable' => MigrationPayload::string($row['sourceTable'] ?? 'accessory_specs'),
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
                        'batch' => '12.7-batch-c2',
                        'status' => $status,
                        'legacyLabel' => $row['legacyLabel'] ?? null,
                        'normalizedLabel' => $row['normalizedLabel'] ?? null,
                        'reason' => $row['reason'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);

                if ($result['conflict']) {
                    throw new \RuntimeException(
                        'MAPPING_TARGET_CONFLICT style=' . $style . ' field=' . $field
                        . ' existing=' . ($result['existingTarget'] ?? '')
                        . ' approved=' . $targetCode,
                    );
                }
                if ($result['inserted']) {
                    $writes['mappingsInserted']++;
                } elseif ($result['identical']) {
                    $writes['mappingsIdenticalExisting']++;
                } elseif ($result['updated']) {
                    // Policy: do not silently update conflicting targets — already thrown above.
                    $writes['mappingsUpdated']++;
                }
                $insertedMappings[] = [
                    'styleCode' => $style,
                    'sourceField' => $field,
                    'targetDefinitionCode' => $targetCode,
                    'inserted' => $result['inserted'],
                    'identical' => $result['identical'],
                ];
            }

            $writes['products'] = 0;
            $writes['variants'] = 0;
            $writes['specificationValues'] = 0;
            $writes['classification'] = 0;
            $writes['catalogue'] = 0;
            $writes['pricing'] = 0;
            $writes['media'] = 0;
            $writes['documents'] = 0;
            $writes['legacy'] = 0;

            return [
                'status' => 'APPLIED',
                'applied' => true,
                'plan' => $plan,
                'actualWrites' => $writes,
                'createdDefinitions' => array_values($createdDefinitions),
                'mappingResultsCount' => count($insertedMappings),
            ];
        });
    }

    /**
     * @param list<array<string, mixed>> $approvedDefinitions
     * @param list<array<string, mixed>> $approvedFieldMappings
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function buildPlan(array $approvedDefinitions, array $approvedFieldMappings, array $preflight): array
    {
        $defByCode = $this->indexByCode(is_array($preflight['definitions'] ?? null) ? $preflight['definitions'] : []);
        $creates = [];
        $mapExisting = [];
        foreach ($approvedDefinitions as $row) {
            $decision = MigrationPayload::string($row['decision'] ?? '');
            $code = strtoupper(MigrationPayload::string($row['code'] ?? ''));
            if ($decision === 'APPROVED_CREATE') {
                if (!isset(self::REQUIRED_CREATES[$code])) {
                    throw new \RuntimeException('Unexpected APPROVED_CREATE code not in required set: ' . $code);
                }
                $creates[$code] = self::REQUIRED_CREATES[$code];
            } elseif ($decision === 'APPROVED_MAP_EXISTING') {
                // Post-apply packages convert APPROVED_CREATE → APPROVED_MAP_EXISTING once codes are live.
                if ($code === '') {
                    throw new \RuntimeException('APPROVED_MAP_EXISTING missing code');
                }
                if (!isset($defByCode[$code]) && !isset(self::REQUIRED_CREATES[$code])) {
                    throw new \RuntimeException('APPROVED_MAP_EXISTING target missing live: ' . $code);
                }
                $mapExisting[] = $code;
            }
        }
        if (count($creates) !== 13) {
            // Allow subset only if some already exist — plan unique creates from REQUIRED_CREATES intersect artifact
            $fromArtifact = array_keys($creates);
            $missingRequired = array_diff(array_keys(self::REQUIRED_CREATES), $fromArtifact);
            if ($missingRequired !== []) {
                // Still plan all 13 from REQUIRED_CREATES when artifact has all 13 creates
                if (count($fromArtifact) === 13) {
                    // ok
                } elseif (count($fromArtifact) < 13) {
                    throw new \RuntimeException('Approved create set incomplete: ' . implode(',', $fromArtifact));
                }
            }
        }
        // Always create exactly the 13 required codes (idempotent if already present)
        $creates = self::REQUIRED_CREATES;

        $toInsert = 0;
        $pending = 0;
        $approved = 0;
        foreach ($approvedFieldMappings as $row) {
            $st = MigrationPayload::string($row['status'] ?? '');
            if ($st === 'APPROVED_PENDING_DEFINITION') {
                ++$pending;
                ++$toInsert;
            } elseif ($st === 'APPROVED_MAP') {
                ++$approved;
                ++$toInsert;
            }
        }

        return [
            'definitionsToCreate' => array_keys($creates),
            'definitionsMappedExisting' => array_values(array_unique($mapExisting)),
            'fieldMappingsRequested' => $toInsert,
            'approvedMapCount' => $approved,
            'approvedPendingDefinitionCount' => $pending,
            'forbiddenIndLszhFire' => true,
            'forbiddenNekClassCreate' => true,
            'outerDiameterMustNotBeNomCableOd' => true,
        ];
    }

    /**
     * @param array<string, mixed> $preflight
     */
    private function assertPreflight(array $preflight): void
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
            throw new \RuntimeException('Preflight failed: target not AVAILABLE');
        }

        $defs = is_array($preflight['definitions'] ?? null) ? $preflight['definitions'] : [];
        $defCount = count($defs) > 0 ? count($defs) : (int) ($preflight['definitionCount'] ?? 0);
        // 42 = pre-apply baseline; 55 = after first successful C.2 apply (42 + 13) for idempotent re-entry.
        if ($defCount !== 42 && $defCount !== 55) {
            throw new \RuntimeException('Preflight failed: unexpected Definition count ' . $defCount);
        }
        $unitCount = (int) ($preflight['unitCount'] ?? 0);
        if ($unitCount === 0 && is_array($preflight['units'] ?? null)) {
            $unitCount = count($preflight['units']);
        }
        if ($unitCount !== 11) {
            throw new \RuntimeException('Preflight failed: expected 11 Units');
        }
        $options = (int) ($preflight['optionCount'] ?? $preflight['options'] ?? 0);
        if ($options !== 416) {
            throw new \RuntimeException('Preflight failed: expected 416 Options, got ' . $options);
        }
        if ((int) ($preflight['revalidationConflicts'] ?? 0) !== 0) {
            throw new \RuntimeException('Preflight failed: revalidation conflicts != 0');
        }
        if ((int) ($preflight['mappingConflicts'] ?? 0) !== 0) {
            throw new \RuntimeException('Preflight failed: mapping conflicts != 0');
        }
        if (!($preflight['operatorApprovalRecognised'] ?? false)) {
            throw new \RuntimeException('Preflight failed: operator approval not recognised');
        }
        if (!($preflight['artifactsValid'] ?? false)) {
            throw new \RuntimeException('Preflight failed: artifacts invalid');
        }
        foreach (['products' => 118, 'variants' => 4178, 'categories' => 13, 'series' => 97, 'catalogueNodes' => 97] as $key => $expected) {
            if (isset($preflight[$key]) && (int) $preflight[$key] !== $expected) {
                throw new \RuntimeException(
                    "Preflight failed: {$key} drift expected {$expected} got " . (string) (int) $preflight[$key],
                );
            }
        }
        // Semantic guard: if defs already at 55, all 13 required codes must already exist.
        if ($defCount === 55) {
            $byCode = $this->indexByCode($defs);
            foreach (array_keys(self::REQUIRED_CREATES) as $code) {
                if (!isset($byCode[$code])) {
                    throw new \RuntimeException('Preflight failed: expected Definition ' . $code . ' after prior C.2 apply');
                }
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
