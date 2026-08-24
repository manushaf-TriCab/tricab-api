<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Command\UpsertSpecificationDefinitionCommand;
use TriCab\Core\Product\Application\Command\UpsertSpecificationOptionCommand;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Service\SpecificationDefinitionService;
use TriCab\Core\Product\Application\Service\SpecificationOptionService;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Specification\SpecMasterDecisionState;
use TriCab\Core\Product\Migration\Support\MigrationPayload;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Phase 12.7 Batch B controlled target apply.
 * Creates approved Specification Definitions + Standard options and inserts
 * migration field/option mappings + SAFE aliases only.
 * Never mutates Products, Variants, Catalogue, classification, pricing, media,
 * Product specification values, Batch C d/v mappings, or legacy DBs.
 */
final class ApplyBatchBSpecificationMasterService
{
    private const REQUIRED_DEFINITION_CREATES = [
        'OPERATING-TEMP' => ['name' => 'Operating temperature', 'group' => 'THERMAL', 'dataType' => 'decimal', 'unitCode' => 'C'],
        'HCL-EMISSION' => ['name' => 'HCl emission', 'group' => 'COMPLIANCE', 'dataType' => 'text', 'unitCode' => null],
        'OXYGEN-INDEX' => ['name' => 'Oxygen index', 'group' => 'COMPLIANCE', 'dataType' => 'text', 'unitCode' => null],
        'PIN-COUNT' => ['name' => 'Pins', 'group' => 'CONSTRUCTION', 'dataType' => 'integer', 'unitCode' => null],
        'THREAD-OD' => ['name' => 'Thread OD', 'group' => 'MECHANICAL', 'dataType' => 'decimal', 'unitCode' => 'MM'],
    ];

    public function __construct(
        private readonly SpecificationDefinitionService $definitions,
        private readonly SpecificationOptionService $options,
        private readonly ProductSpecificationRepository $specifications,
        private readonly MigrationBatchRepository $batches,
        private readonly ApplicationTransaction $transaction,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $approvedDefinitions
     * @param list<array<string, mixed>> $approvedStandards
     * @param list<array<string, mixed>> $approvedApprovals
     * @param list<array<string, mixed>> $approvedAliases
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    public function apply(
        ActorContext $actor,
        array $approvedDefinitions,
        array $approvedStandards,
        array $approvedApprovals,
        array $approvedAliases,
        array $preflight,
        bool $dryRun = false,
    ): array {
        $this->assertPreflight($preflight);
        $plan = $this->validateApprovedPackage(
            $approvedDefinitions,
            $approvedStandards,
            $approvedApprovals,
            $approvedAliases,
            $preflight,
        );

        if ($dryRun) {
            return [
                'status' => 'DRY_RUN',
                'applied' => false,
                'plan' => $plan,
                'actualWrites' => $this->emptyWrites(),
            ];
        }

        /** @phpstan-ignore argument.unresolvableType */
        return $this->transaction->run(function () use (
            $actor,
            $approvedDefinitions,
            $approvedStandards,
            $approvedApprovals,
            $approvedAliases,
            $preflight,
            $plan,
        ): array {
            $groupByCode = $this->indexGroups($preflight['groups'] ?? []);
            $unitByCode = $this->indexUnits($preflight['units'] ?? []);
            $defByCode = $this->indexDefinitions($preflight['definitions'] ?? []);

            $writes = $this->emptyWrites();
            $createdDefinitions = [];
            $createdOptions = [];
            $definitionMappings = [];
            $optionMappings = [];
            $aliasRows = [];

            $safeAliasKeys = [];
            foreach ($approvedAliases as $alias) {
                if (($alias['decision'] ?? '') !== SpecMasterDecisionState::SAFE_ALIAS) {
                    continue;
                }
                $safeAliasKeys[$this->aliasKey('wordpress', 'STANDARD', (string) ($alias['rawValue'] ?? ''))] = $alias;
            }

            // 1) Create exactly the 5 approved Definitions (idempotent by code).
            foreach ($plan['uniqueDefinitionCreates'] as $code => $meta) {
                $groupCode = strtoupper((string) $meta['group']);
                if (!isset($groupByCode[$groupCode])) {
                    throw new \RuntimeException('Group missing for Definition create: ' . $code . ' → ' . $groupCode);
                }
                $unitString = null;
                $unitPublicId = null;
                $unitCode = $meta['unitCode'] ?? null;
                if (is_string($unitCode) && $unitCode !== '') {
                    $unitKey = strtoupper($unitCode);
                    if (!isset($unitByCode[$unitKey])) {
                        throw new \RuntimeException('Unit missing for Definition create: ' . $code . ' → ' . $unitCode);
                    }
                    $unitPublicId = (string) $unitByCode[$unitKey]['publicId'];
                    $symbol = trim((string) ($unitByCode[$unitKey]['symbol'] ?? ''));
                    $unitString = $symbol !== '' ? $symbol : (string) $unitByCode[$unitKey]['code'];
                }
                if (isset($defByCode[$code])) {
                    $createdDefinitions[$code] = [
                        'code' => $code,
                        'name' => $defByCode[$code]['name'],
                        'group' => $groupCode,
                        'dataType' => $defByCode[$code]['dataTypeCode'] ?? $meta['dataType'],
                        'unit' => $defByCode[$code]['unit'] ?? $unitString,
                        'publicId' => $defByCode[$code]['publicId'],
                        'created' => false,
                        'reusedExisting' => true,
                    ];
                    continue;
                }
                try {
                    $record = $this->definitions->create($actor, new UpsertSpecificationDefinitionCommand(
                        name: (string) $meta['name'],
                        code: $code,
                        dataTypeCode: (string) $meta['dataType'],
                        scopeCode: 'product',
                        groupPublicId: (string) $groupByCode[$groupCode]['publicId'],
                        description: 'Batch B.1 operator-approved Specification Definition',
                        unit: $unitString,
                        unitPublicId: $unitPublicId,
                        displayOrder: 200 + count($createdDefinitions),
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
                    'name' => $record->name,
                    'group' => $groupCode,
                    'dataType' => $record->dataTypeCode,
                    'unit' => $record->unit,
                    'publicId' => $record->publicId,
                    'created' => true,
                    'reusedExisting' => false,
                ];
            }

            // 2) Insert Definition field mappings (16).
            // Disambiguate duplicate sourceField→multiple targets (e.g. spec_labels:d1 → PIN-COUNT + THREAD-OD).
            $sourceFieldTargets = [];
            foreach ($approvedDefinitions as $row) {
                $decision = (string) ($row['decision'] ?? '');
                if (!in_array($decision, [
                    SpecMasterDecisionState::APPROVED_MAP_EXISTING,
                    SpecMasterDecisionState::APPROVED_CREATE,
                ], true)) {
                    continue;
                }
                $targetCode = strtoupper((string) (
                    $decision === SpecMasterDecisionState::APPROVED_MAP_EXISTING
                        ? ($row['existingTargetDefinition'] ?? $row['proposedDefinitionCode'] ?? '')
                        : ($row['proposedDefinitionCode'] ?? '')
                ));
                if ($targetCode === '' || !isset($defByCode[$targetCode])) {
                    throw new \RuntimeException('Definition mapping target unresolved: ' . $targetCode);
                }
                $sourceFieldOriginal = MigrationPayload::string($row['sourceField'] ?? '');
                if ($sourceFieldOriginal === '') {
                    throw new \RuntimeException('Definition mapping missing sourceField');
                }
                $sourceField = $sourceFieldOriginal;
                if (isset($sourceFieldTargets[$sourceFieldOriginal]) && $sourceFieldTargets[$sourceFieldOriginal] !== $targetCode) {
                    $sourceField = $sourceFieldOriginal . '#' . $targetCode;
                }
                $sourceFieldTargets[$sourceFieldOriginal] = $targetCode;
                $result = $this->upsertDefinitionMappingIdempotent($actor, [
                    'sourceSystem' => MigrationPayload::string($row['sourceSystem'] ?? 'wordpress'),
                    'sourceTable' => 'wp_postmeta',
                    'sourceField' => $sourceField,
                    'targetSpecificationDefinitionPublicId' => (string) $defByCode[$targetCode]['publicId'],
                    'targetDefinitionCode' => $targetCode,
                    'targetScopeCode' => 'product',
                    'transformationRule' => MigrationPayload::string($row['dataType'] ?? 'text') ?: 'text',
                    'sourceUnit' => isset($row['unit']) && is_string($row['unit']) ? $row['unit'] : null,
                    'targetUnit' => $defByCode[$targetCode]['unit'] ?? null,
                    'statusCode' => 'mapped',
                    'notes' => json_encode([
                        'batch' => '12.7-batch-b',
                        'decision' => $decision,
                        'originalSourceField' => $sourceFieldOriginal,
                        'reason' => $row['reason'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
                if ($result['inserted']) {
                    $writes['definitionMappingsInserted']++;
                } elseif ($result['updated']) {
                    $writes['mappingsUpdated']++;
                } else {
                    $writes['definitionMappingsSkippedExisting']++;
                }
                $definitionMappings[] = $result['row'];
            }

            // 3) Resolve STANDARD / APPROVALS definitions and index options.
            if (!isset($defByCode['STANDARD']) || !isset($defByCode['APPROVALS'])) {
                throw new \RuntimeException('STANDARD or APPROVALS definition missing on target');
            }
            $standardPublicId = (string) $defByCode['STANDARD']['publicId'];
            $approvalsPublicId = (string) $defByCode['APPROVALS']['publicId'];
            $standardOptionsByCode = $this->indexOptionsForDefinition($standardPublicId);
            $approvalOptionsByCode = $this->indexOptionsForDefinition($approvalsPublicId);

            // 4) Create 11 approved Standard options.
            foreach ($approvedStandards as $row) {
                if (($row['decision'] ?? '') !== SpecMasterDecisionState::APPROVED_CREATE) {
                    continue;
                }
                $code = strtoupper((string) ($row['proposedOption']['code'] ?? ''));
                $name = trim((string) ($row['proposedOption']['displayName'] ?? $row['normalizedStandard'] ?? $code));
                if ($code === '') {
                    throw new \RuntimeException('Standard option create missing code');
                }
                if (isset($standardOptionsByCode[$code])) {
                    $createdOptions[$code] = [
                        'code' => $code,
                        'name' => $standardOptionsByCode[$code]['name'],
                        'publicId' => $standardOptionsByCode[$code]['publicId'],
                        'definition' => 'STANDARD',
                        'created' => false,
                        'reusedExisting' => true,
                    ];
                    continue;
                }
                try {
                    $record = $this->options->create($actor, new UpsertSpecificationOptionCommand(
                        definitionPublicId: $standardPublicId,
                        name: $name !== '' ? $name : $code,
                        code: $code,
                        value: $name !== '' ? $name : $code,
                        displayOrder: 300 + count($createdOptions),
                        isActive: true,
                    ));
                } catch (DuplicateSpecificationCode $e) {
                    throw new \RuntimeException('Standard option code collision: ' . $code, 0, $e);
                }
                $writes['optionsCreated']++;
                $standardOptionsByCode[$code] = [
                    'code' => $code,
                    'name' => $record->name,
                    'publicId' => $record->publicId,
                ];
                $createdOptions[$code] = [
                    'code' => $code,
                    'name' => $record->name,
                    'publicId' => $record->publicId,
                    'definition' => 'STANDARD',
                    'created' => true,
                    'reusedExisting' => false,
                ];
            }

            // 5) Insert Standard + Approval option mappings (35). SAFE overlap counted too.
            foreach ($approvedStandards as $row) {
                $decision = (string) ($row['decision'] ?? '');
                if (!in_array($decision, [
                    SpecMasterDecisionState::APPROVED_MAP_EXISTING,
                    SpecMasterDecisionState::APPROVED_CREATE,
                ], true)) {
                    continue;
                }
                $legacy = MigrationPayload::string($row['normalizedStandard'] ?? '');
                if ($legacy === '') {
                    throw new \RuntimeException('Standard mapping missing normalizedStandard');
                }
                $targetCode = $decision === SpecMasterDecisionState::APPROVED_MAP_EXISTING
                    ? strtoupper((string) ($row['existingTargetOption'] ?? $row['proposedOption']['code'] ?? ''))
                    : strtoupper((string) ($row['proposedOption']['code'] ?? ''));
                if ($targetCode === '' || !isset($standardOptionsByCode[$targetCode])) {
                    throw new \RuntimeException('Standard option mapping target unresolved: ' . $targetCode . ' for ' . $legacy);
                }
                $isSafe = isset($safeAliasKeys[$this->aliasKey('wordpress', 'STANDARD', $legacy)]);
                $result = $this->upsertOptionAliasIdempotent($actor, [
                    'sourceSystem' => 'wordpress',
                    'definitionCode' => 'STANDARD',
                    'legacyValue' => $legacy,
                    'normalizedLegacyValue' => $legacy,
                    'targetOptionPublicId' => (string) $standardOptionsByCode[$targetCode]['publicId'],
                    'targetOptionCode' => $targetCode,
                    'statusCode' => 'mapped',
                    'notes' => json_encode([
                        'batch' => '12.7-batch-b',
                        'decision' => $decision,
                        'safeAlias' => $isSafe,
                        'reason' => $row['reason'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
                if ($result['inserted']) {
                    $writes['optionMappingsInserted']++;
                    if ($isSafe) {
                        $writes['aliasesInserted']++;
                    }
                } elseif ($result['updated']) {
                    $writes['mappingsUpdated']++;
                } else {
                    $writes['optionMappingsSkippedExisting']++;
                }
                $optionMappings[] = $result['row'];
            }

            foreach ($approvedApprovals as $row) {
                $decision = (string) ($row['decision'] ?? '');
                if ($decision === SpecMasterDecisionState::APPROVED_CREATE) {
                    throw new \RuntimeException('Batch B apply forbids Approval option creates');
                }
                if ($decision !== SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                    continue;
                }
                $legacy = MigrationPayload::string($row['normalizedApproval'] ?? '');
                $targetCode = strtoupper((string) ($row['existingTargetOption'] ?? $row['proposedOption']['code'] ?? ''));
                if ($legacy === '' || $targetCode === '' || !isset($approvalOptionsByCode[$targetCode])) {
                    throw new \RuntimeException('Approval option mapping unresolved: ' . $legacy . ' → ' . $targetCode);
                }
                $result = $this->upsertOptionAliasIdempotent($actor, [
                    'sourceSystem' => 'wordpress',
                    'definitionCode' => 'APPROVALS',
                    'legacyValue' => $legacy,
                    'normalizedLegacyValue' => $legacy,
                    'targetOptionPublicId' => (string) $approvalOptionsByCode[$targetCode]['publicId'],
                    'targetOptionCode' => $targetCode,
                    'statusCode' => 'mapped',
                    'notes' => json_encode([
                        'batch' => '12.7-batch-b',
                        'decision' => $decision,
                        'reason' => $row['reason'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
                if ($result['inserted']) {
                    $writes['optionMappingsInserted']++;
                } elseif ($result['updated']) {
                    $writes['mappingsUpdated']++;
                } else {
                    $writes['optionMappingsSkippedExisting']++;
                }
                $optionMappings[] = $result['row'];
            }

            // 6) Insert SAFE aliases not already covered by option mappings.
            foreach ($approvedAliases as $alias) {
                if (($alias['decision'] ?? '') !== SpecMasterDecisionState::SAFE_ALIAS) {
                    continue;
                }
                $legacy = MigrationPayload::string($alias['rawValue'] ?? '');
                $targetLabel = MigrationPayload::string($alias['targetOption'] ?? '');
                if ($legacy === '' || $targetLabel === '') {
                    throw new \RuntimeException('SAFE alias incomplete');
                }
                $targetCode = $this->resolveStandardOptionCodeByLabelOrCode($standardOptionsByCode, $targetLabel);
                if ($targetCode === null) {
                    throw new \RuntimeException('SAFE alias target option unresolved: ' . $targetLabel);
                }
                // Forbid DNV → DNV-GL.
                if (strtoupper($legacy) === 'DNV' && strtoupper($targetCode) === 'DNV-GL') {
                    throw new \RuntimeException('Refusing DNV → DNV-GL alias');
                }
                $result = $this->upsertOptionAliasIdempotent($actor, [
                    'sourceSystem' => 'wordpress',
                    'definitionCode' => 'STANDARD',
                    'legacyValue' => $legacy,
                    'normalizedLegacyValue' => $legacy,
                    'targetOptionPublicId' => (string) $standardOptionsByCode[$targetCode]['publicId'],
                    'targetOptionCode' => $targetCode,
                    'statusCode' => 'mapped',
                    'notes' => json_encode([
                        'batch' => '12.7-batch-b',
                        'decision' => SpecMasterDecisionState::SAFE_ALIAS,
                        'reason' => $alias['reason'] ?? null,
                    ], JSON_UNESCAPED_SLASHES),
                ]);
                if ($result['inserted']) {
                    $writes['aliasesInserted']++;
                    // Also counts as an option mapping when not already counted via standards list.
                    $writes['optionMappingsInserted']++;
                } elseif ($result['updated']) {
                    $writes['mappingsUpdated']++;
                } else {
                    $writes['aliasesSkippedExisting']++;
                }
                $aliasRows[] = $result['row'];
            }

            return [
                'status' => 'COMPLETED',
                'applied' => true,
                'plan' => $plan,
                'actualWrites' => $writes,
                'createdDefinitions' => array_values($createdDefinitions),
                'createdOptions' => array_values($createdOptions),
                'definitionMappings' => $definitionMappings,
                'optionMappings' => $optionMappings,
                'aliases' => $aliasRows,
                'productMutations' => 0,
                'variantMutations' => 0,
                'classificationMutations' => 0,
                'catalogueMutations' => 0,
                'productSpecificationValueMutations' => 0,
                'approvalOptionsCreated' => 0,
                'legacyWrites' => 0,
            ];
        });
    }

    /**
     * @param array<string, mixed> $preflight
     */
    private function assertPreflight(array $preflight): void
    {
        $server = (string) ($preflight['server'] ?? '');
        $database = (string) ($preflight['database'] ?? '');
        if (!str_contains(strtolower($server), 'tricab-mel')) {
            throw new \RuntimeException('Refusing apply: server is not tricab-mel (' . $server . ')');
        }
        if (strtolower($database) !== 'tc') {
            throw new \RuntimeException('Refusing apply: database is not tc (' . $database . ')');
        }
        if (($preflight['environmentLabel'] ?? '') !== 'NON-PRODUCTION') {
            throw new \RuntimeException('Refusing apply: environment is not NON-PRODUCTION');
        }
        $groups = is_array($preflight['groups'] ?? null) ? $preflight['groups'] : [];
        $defs = is_array($preflight['definitions'] ?? null) ? $preflight['definitions'] : [];
        $units = is_array($preflight['units'] ?? null) ? $preflight['units'] : [];
        $options = (int) ($preflight['options'] ?? 0);
        if (count($groups) !== 6) {
            throw new \RuntimeException('Refusing apply: expected 6 Specification Groups, got ' . count($groups));
        }
        if (count($defs) !== 37 && count($defs) !== 42) {
            // 37 pre-apply; 42 after first apply (idempotent re-entry).
            throw new \RuntimeException('Refusing apply: unexpected Definition count ' . count($defs));
        }
        if (count($units) !== 11) {
            throw new \RuntimeException('Refusing apply: expected 11 Units, got ' . count($units));
        }
        if ($options !== 405 && $options !== 416) {
            throw new \RuntimeException('Refusing apply: unexpected Option count ' . $options);
        }
        foreach (['products' => 118, 'variants' => 4178, 'categories' => 13, 'series' => 97, 'catalogueNodes' => 97] as $key => $expected) {
            $actual = (int) ($preflight[$key] ?? -1);
            if ($actual !== $expected) {
                throw new \RuntimeException("Refusing apply: {$key} drift expected {$expected} got {$actual}");
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $approvedDefinitions
     * @param list<array<string, mixed>> $approvedStandards
     * @param list<array<string, mixed>> $approvedApprovals
     * @param list<array<string, mixed>> $approvedAliases
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function validateApprovedPackage(
        array $approvedDefinitions,
        array $approvedStandards,
        array $approvedApprovals,
        array $approvedAliases,
        array $preflight,
    ): array {
        $mapExisting = 0;
        $createRows = 0;
        $uniqueCreates = [];
        foreach ($approvedDefinitions as $row) {
            $d = (string) ($row['decision'] ?? '');
            if ($d === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$mapExisting;
                $target = strtoupper((string) ($row['existingTargetDefinition'] ?? $row['proposedDefinitionCode'] ?? ''));
                if ($target === '') {
                    throw new \RuntimeException('MAP_EXISTING definition missing target');
                }
                $live = $this->indexDefinitions($preflight['definitions'] ?? []);
                if (!isset($live[$target])) {
                    throw new \RuntimeException('Mapped Definition missing on target: ' . $target);
                }
            }
            if ($d === SpecMasterDecisionState::APPROVED_CREATE) {
                ++$createRows;
                $code = strtoupper((string) ($row['proposedDefinitionCode'] ?? ''));
                $group = strtoupper((string) ($row['proposedGroup'] ?? ''));
                $name = (string) ($row['proposedDefinitionName'] ?? $code);
                $dataType = strtolower((string) ($row['dataType'] ?? ''));
                $unit = isset($row['unit']) && is_string($row['unit']) && trim($row['unit']) !== ''
                    ? strtoupper(trim($row['unit']))
                    : null;
                if ($code === '' || $group === '' || $dataType === '') {
                    throw new \RuntimeException('Invalid APPROVED_CREATE Definition row: ' . $code);
                }
                if (!isset(self::REQUIRED_DEFINITION_CREATES[$code])) {
                    throw new \RuntimeException('Unexpected Definition create not in approved set: ' . $code);
                }
                $expected = self::REQUIRED_DEFINITION_CREATES[$code];
                if ($group !== $expected['group'] || $dataType !== $expected['dataType'] || $unit !== $expected['unitCode']) {
                    throw new \RuntimeException('Definition create metadata mismatch for ' . $code);
                }
                $live = $this->indexDefinitions($preflight['definitions'] ?? []);
                $defCount = count(is_array($preflight['definitions'] ?? null) ? $preflight['definitions'] : []);
                // Collision only on pre-apply baseline (37). Idempotent re-apply allows 42.
                if (isset($live[$code]) && $defCount === 37) {
                    throw new \RuntimeException('Definition code already exists on target: ' . $code);
                }
                $uniqueCreates[$code] = [
                    'name' => $name,
                    'group' => $group,
                    'dataType' => $dataType,
                    'unitCode' => $unit,
                ];
            }
            if (in_array($d, [SpecMasterDecisionState::REVIEW, SpecMasterDecisionState::PROPOSED_CREATE], true)) {
                throw new \RuntimeException('Approved definitions artifact must not include REVIEW/PROPOSED rows');
            }
        }
        foreach (self::REQUIRED_DEFINITION_CREATES as $code => $_meta) {
            if (!isset($uniqueCreates[$code])) {
                throw new \RuntimeException('Missing required Definition create: ' . $code);
            }
        }
        if ($mapExisting !== 10) {
            throw new \RuntimeException('Expected 10 APPROVED_MAP_EXISTING Definitions, got ' . $mapExisting);
        }
        if ($createRows !== 6) {
            // degrees + ind_degrees both APPROVED_CREATE → OPERATING-TEMP (6 rows, 5 unique)
            throw new \RuntimeException('Expected 6 APPROVED_CREATE Definition source rows, got ' . $createRows);
        }

        $stdMap = 0;
        $stdCreate = 0;
        foreach ($approvedStandards as $row) {
            $d = (string) ($row['decision'] ?? '');
            if ($d === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$stdMap;
            }
            if ($d === SpecMasterDecisionState::APPROVED_CREATE) {
                ++$stdCreate;
            }
            if (in_array($d, [SpecMasterDecisionState::REVIEW, SpecMasterDecisionState::PROPOSED_CREATE], true)) {
                throw new \RuntimeException('Approved standards artifact must not include REVIEW rows');
            }
        }
        if ($stdMap !== 23) {
            throw new \RuntimeException('Expected 23 Standard MAP_EXISTING, got ' . $stdMap);
        }
        if ($stdCreate !== 11) {
            throw new \RuntimeException('Expected 11 Standard CREATE, got ' . $stdCreate);
        }

        $aprMap = 0;
        $aprCreate = 0;
        foreach ($approvedApprovals as $row) {
            $d = (string) ($row['decision'] ?? '');
            if ($d === SpecMasterDecisionState::APPROVED_MAP_EXISTING) {
                ++$aprMap;
            }
            if ($d === SpecMasterDecisionState::APPROVED_CREATE) {
                ++$aprCreate;
            }
        }
        if ($aprMap !== 1) {
            throw new \RuntimeException('Expected 1 Approval MAP_EXISTING, got ' . $aprMap);
        }
        if ($aprCreate !== 0) {
            throw new \RuntimeException('Expected 0 Approval CREATE, got ' . $aprCreate);
        }

        $safe = 0;
        foreach ($approvedAliases as $row) {
            $d = (string) ($row['decision'] ?? '');
            if ($d === SpecMasterDecisionState::SAFE_ALIAS) {
                ++$safe;
                $legacy = strtoupper(trim((string) ($row['rawValue'] ?? '')));
                $target = strtoupper(trim((string) ($row['targetOption'] ?? '')));
                if ($legacy === 'DNV' && str_contains($target, 'DNV-GL')) {
                    throw new \RuntimeException('DNV → DNV-GL alias is forbidden');
                }
            } elseif ($d !== '') {
                throw new \RuntimeException('Approved aliases artifact may only contain SAFE_ALIAS rows');
            }
        }
        if ($safe !== 2) {
            throw new \RuntimeException('Expected 2 SAFE aliases, got ' . $safe);
        }

        return [
            'mapExistingDefinitions' => $mapExisting,
            'approvedCreateDefinitionSourceRows' => $createRows,
            'uniqueDefinitionCreates' => $uniqueCreates,
            'definitionMappingsExpected' => $mapExisting + $createRows,
            'standardMapExisting' => $stdMap,
            'standardCreates' => $stdCreate,
            'approvalMapExisting' => $aprMap,
            'approvalCreates' => $aprCreate,
            'optionMappingsExpected' => $stdMap + $stdCreate + $aprMap + $aprCreate,
            'safeAliasesExpected' => $safe,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{inserted: bool, updated: bool, row: array<string, mixed>}
     */
    private function upsertDefinitionMappingIdempotent(ActorContext $actor, array $data): array
    {
        $sourceSystem = (string) $data['sourceSystem'];
        $sourceTable = (string) $data['sourceTable'];
        $sourceField = (string) $data['sourceField'];
        $targetCode = (string) $data['targetDefinitionCode'];
        $targetId = (string) $data['targetSpecificationDefinitionPublicId'];

        foreach ($this->batches->listSpecificationMappings() as $row) {
            if (($row['sourceSystem'] ?? '') === $sourceSystem
                && ($row['sourceTable'] ?? '') === $sourceTable
                && ($row['sourceField'] ?? '') === $sourceField
            ) {
                $same = ($row['targetDefinitionCode'] ?? null) === $targetCode
                    && ($row['targetSpecificationDefinitionPublicId'] ?? null) === $targetId
                    && ($row['statusCode'] ?? '') === 'mapped';
                if ($same) {
                    return ['inserted' => false, 'updated' => false, 'row' => $row];
                }
                $updated = $this->batches->upsertSpecificationMapping((string) $row['publicId'], array_merge($data, [
                    'mappedByUserId' => $actor->userId,
                ]));

                return ['inserted' => false, 'updated' => true, 'row' => $updated];
            }
        }

        $inserted = $this->batches->upsertSpecificationMapping('new', array_merge($data, [
            'mappedByUserId' => $actor->userId,
        ]));

        return ['inserted' => true, 'updated' => false, 'row' => $inserted];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{inserted: bool, updated: bool, row: array<string, mixed>}
     */
    private function upsertOptionAliasIdempotent(ActorContext $actor, array $data): array
    {
        $sourceSystem = (string) $data['sourceSystem'];
        $definitionCode = (string) $data['definitionCode'];
        $normalized = (string) ($data['normalizedLegacyValue'] ?? $data['legacyValue'] ?? '');
        $targetCode = (string) ($data['targetOptionCode'] ?? '');
        $targetId = (string) ($data['targetOptionPublicId'] ?? '');

        foreach ($this->batches->listOptionAliases() as $row) {
            if (($row['sourceSystem'] ?? '') === $sourceSystem
                && ($row['definitionCode'] ?? '') === $definitionCode
                && ($row['normalizedLegacyValue'] ?? '') === $normalized
            ) {
                $same = ($row['targetOptionCode'] ?? null) === $targetCode
                    && ($row['targetOptionPublicId'] ?? null) === $targetId
                    && ($row['statusCode'] ?? '') === 'mapped';
                if ($same) {
                    return ['inserted' => false, 'updated' => false, 'row' => $row];
                }
                $updated = $this->batches->upsertOptionAlias((string) $row['publicId'], array_merge($data, [
                    'mappedByUserId' => $actor->userId,
                ]));

                return ['inserted' => false, 'updated' => true, 'row' => $updated];
            }
        }

        $inserted = $this->batches->upsertOptionAlias('new', array_merge($data, [
            'mappedByUserId' => $actor->userId,
        ]));

        return ['inserted' => true, 'updated' => false, 'row' => $inserted];
    }

    /**
     * @return array<string, int|array<string, mixed>>
     */
    private function emptyWrites(): array
    {
        return [
            'definitionsCreated' => 0,
            'optionsCreated' => 0,
            'definitionMappingsInserted' => 0,
            'optionMappingsInserted' => 0,
            'aliasesInserted' => 0,
            'definitionMappingsSkippedExisting' => 0,
            'optionMappingsSkippedExisting' => 0,
            'aliasesSkippedExisting' => 0,
            'mappingsUpdated' => 0,
            'approvalOptionsCreated' => 0,
            'productSpecificationValueMutations' => 0,
            'productMutations' => 0,
            'variantMutations' => 0,
            'classificationMutations' => 0,
            'catalogueMutations' => 0,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexGroups(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $code = strtoupper((string) ($row['code'] ?? $row['groupCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexUnits(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $code = strtoupper((string) ($row['code'] ?? $row['unitCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexDefinitions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $code = strtoupper((string) ($row['code'] ?? $row['definitionCode'] ?? ''));
            if ($code !== '') {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{code: string, name: string, publicId: string}>
     */
    private function indexOptionsForDefinition(string $definitionPublicId): array
    {
        $out = [];
        foreach ($this->specifications->listOptionsAdmin([
            'definitionPublicId' => $definitionPublicId,
            'includeDeleted' => false,
        ]) as $opt) {
            $code = strtoupper($opt->code);
            $out[$code] = [
                'code' => $code,
                'name' => $opt->name,
                'publicId' => $opt->publicId,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{code: string, name: string, publicId: string}> $byCode
     */
    private function resolveStandardOptionCodeByLabelOrCode(array $byCode, string $labelOrCode): ?string
    {
        $needle = strtoupper(trim($labelOrCode));
        if (isset($byCode[$needle])) {
            return $needle;
        }
        foreach ($byCode as $code => $row) {
            if (strtoupper(trim($row['name'])) === $needle) {
                return $code;
            }
        }
        // Label "IEC 60332-1" vs code "IEC-60332-1"
        $asCode = strtoupper(str_replace([' ', '/'], ['-', '-'], $needle));
        if (isset($byCode[$asCode])) {
            return $asCode;
        }

        return null;
    }

    private function aliasKey(string $sourceSystem, string $definitionCode, string $legacy): string
    {
        return strtolower($sourceSystem) . '|' . strtoupper($definitionCode) . '|' . $legacy;
    }
}
