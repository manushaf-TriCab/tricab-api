<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchB;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchB1OperatorApprovalService;
use TriCab\Core\Product\Migration\Specification\SpecMasterDecisionState;

final class ResolveBatchB1OperatorApprovalServiceTest extends TestCase
{
    public function testDefinitionDuplicatePreventionMapsToExisting(): void
    {
        $svc = new ResolveBatchB1OperatorApprovalService();
        $result = $svc->build($this->package(), [
            'definitions' => [
                'degrees' => [
                    'decision' => SpecMasterDecisionState::APPROVED_CREATE,
                    'proposedDefinitionCode' => 'VOLTAGE',
                    'proposedDefinitionName' => 'Voltage',
                    'proposedGroup' => 'electrical',
                    'dataType' => 'text',
                ],
            ],
            'standards' => [],
            'approvals' => ['__rejectStyleCodes' => true],
            'aliases' => ['rejectMultilineBlobs' => true],
        ], $this->azure());

        $degrees = null;
        foreach ($result['definitions']['items'] as $d) {
            if (($d['sourceField'] ?? '') === 'degrees') {
                $degrees = $d;
            }
        }
        self::assertNotNull($degrees);
        self::assertSame(SpecMasterDecisionState::APPROVED_MAP_EXISTING, $degrees['decision']);
        self::assertSame('VOLTAGE', $degrees['existingTargetDefinition']);
    }

    public function testStandardsRejectFragmentsAndApproveGenuine(): void
    {
        $svc = new ResolveBatchB1OperatorApprovalService();
        $result = $svc->build($this->package(), $svc->defaultOperatorDecisions(), $this->azure());
        $byName = [];
        foreach ($result['standards']['items'] as $s) {
            $byName[$s['normalizedStandard']] = $s;
        }
        self::assertSame(SpecMasterDecisionState::REJECT, $byName['W']['decision']);
        self::assertSame(SpecMasterDecisionState::APPROVED_CREATE, $byName['AS/NZS 5000.1']['decision']);
        self::assertSame(SpecMasterDecisionState::APPROVED_MAP_EXISTING, $byName['IEC 61034-1&2']['decision']);
    }

    public function testApprovalSemanticRejectsStyleCodes(): void
    {
        $svc = new ResolveBatchB1OperatorApprovalService();
        $result = $svc->build($this->package(), $svc->defaultOperatorDecisions(), $this->azure());
        self::assertGreaterThan(0, $result['approvalSemanticAudit']['styleCodeValuesExcluded']);
        $bva = null;
        foreach ($result['approvals']['items'] as $a) {
            if (($a['normalizedApproval'] ?? '') === 'BVA') {
                $bva = $a;
            }
        }
        self::assertNotNull($bva);
        self::assertSame(SpecMasterDecisionState::REJECT, $bva['decision']);
        self::assertSame(0, $result['approvals']['counts']['approved']);
    }

    public function testDnvGlRemainsUnmerged(): void
    {
        $svc = new ResolveBatchB1OperatorApprovalService();
        $pkg = $this->package();
        $pkg['approvals']['items'][] = [
            'normalizedApproval' => 'DNV',
            'rawValues' => ['DNV'],
            'usageCount' => 2,
            'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
        ];
        $pkg['approvals']['items'][] = [
            'normalizedApproval' => 'DNV-GL',
            'rawValues' => ['DNV-GL'],
            'usageCount' => 2,
            'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
        ];
        $result = $svc->build($pkg, $svc->defaultOperatorDecisions(), $this->azure());
        $dnv = null;
        foreach ($result['approvals']['items'] as $a) {
            if (($a['normalizedApproval'] ?? '') === 'DNV') {
                $dnv = $a;
            }
        }
        self::assertNotNull($dnv);
        self::assertSame(SpecMasterDecisionState::REVIEW, $dnv['decision']);
        self::assertStringContainsString('dnv', strtolower((string) $dnv['reason']));
    }

    public function testSafeAliasAndMultilineReject(): void
    {
        $svc = new ResolveBatchB1OperatorApprovalService();
        $result = $svc->build($this->package(), $svc->defaultOperatorDecisions(), $this->azure());
        self::assertGreaterThanOrEqual(1, $result['aliases']['counts'][SpecMasterDecisionState::SAFE_ALIAS]);
        $rejected = 0;
        foreach ($result['aliases']['items'] as $a) {
            if (($a['decision'] ?? '') === SpecMasterDecisionState::ALIAS_REJECT) {
                ++$rejected;
            }
        }
        self::assertGreaterThan(0, $rejected);
    }

    public function testSpecMasterReadySeparateFromFieldMapping(): void
    {
        $svc = new ResolveBatchB1OperatorApprovalService();
        $result = $svc->build($this->package(), $svc->defaultOperatorDecisions(), $this->azure());
        self::assertSame(0, $result['productReadinessImpact']['SPEC_FIELD_MAPPING_READY']);
        self::assertGreaterThan(0, $result['productReadinessImpact']['SPEC_MASTER_READY']);
        self::assertSame('BATCH_B_APPLY_NOT_APPROVED', $result['approvalBoundary']['batchBApplyGuard']);
        self::assertSame('BATCH_C_NOT_STARTED', $result['approvalBoundary']['batchCGuard']);
        self::assertSame(0, $result['dryRunTargetPlan']['productSpecificationValues']);
        self::assertSame(0, $result['dryRunTargetPlan']['idempotencyProjection']['secondRunDefinitionCreates']);
    }

    public function testUniqueOperatingTempCreateFromTwoSourceFields(): void
    {
        $svc = new ResolveBatchB1OperatorApprovalService();
        $result = $svc->build($this->package(), $svc->defaultOperatorDecisions(), $this->azure());
        $codes = array_column($result['definitions']['uniqueApprovedCreates'], 'code');
        self::assertContains('OPERATING-TEMP', $codes);
        self::assertSame(1, count(array_filter($codes, static fn (string $c): bool => $c === 'OPERATING-TEMP')));
    }

    /**
     * @return array<string, mixed>
     */
    private function package(): array
    {
        return [
            'definitions' => [
                'items' => [
                    [
                        'sourceField' => 'degrees',
                        'proposedDefinitionCode' => 'DEGREES',
                        'proposedDefinitionName' => 'Degrees',
                        'proposedGroup' => 'thermal',
                        'dataType' => 'decimal',
                        'unit' => 'C',
                        'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
                        'usageCount' => 90,
                        'sampleValues' => ['105'],
                    ],
                    [
                        'sourceField' => 'ind_degrees',
                        'proposedDefinitionCode' => 'IND-DEGREES',
                        'proposedDefinitionName' => 'Ind Degrees',
                        'proposedGroup' => 'thermal',
                        'dataType' => 'decimal',
                        'unit' => 'C',
                        'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
                        'usageCount' => 150,
                        'sampleValues' => ['105'],
                    ],
                    [
                        'sourceField' => 'kilovolts',
                        'proposedDefinitionCode' => 'KILOVOLTS',
                        'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
                        'usageCount' => 90,
                        'sampleValues' => ['0.6/1kV'],
                    ],
                    [
                        'sourceField' => 'industry_code',
                        'decision' => SpecMasterDecisionState::REVIEW,
                        'usageCount' => 230,
                        'sampleValues' => ['UX'],
                    ],
                    [
                        'sourceField' => 'spec_labels:d1',
                        'proposedDefinitionCode' => 'PIN-COUNT',
                        'proposedDefinitionName' => 'Pins',
                        'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
                        'usageCount' => 14,
                    ],
                    [
                        'sourceField' => 'spec_labels:d1',
                        'proposedDefinitionCode' => 'THREAD-OD',
                        'proposedDefinitionName' => 'Thread OD',
                        'decision' => SpecMasterDecisionState::PROPOSED_CREATE,
                        'usageCount' => 16,
                    ],
                    [
                        'sourceField' => 'standards',
                        'existingTargetDefinition' => 'STANDARD',
                        'decision' => SpecMasterDecisionState::APPROVED_MAP_EXISTING,
                    ],
                ],
            ],
            'standards' => [
                'items' => [
                    ['normalizedStandard' => 'AS/NZS 5000.1', 'rawValues' => ['AS/NZS 5000.1'], 'usageCount' => 34, 'decision' => SpecMasterDecisionState::PROPOSED_CREATE],
                    ['normalizedStandard' => 'W', 'rawValues' => ['W'], 'usageCount' => 3, 'decision' => SpecMasterDecisionState::PROPOSED_CREATE],
                    ['normalizedStandard' => 'IEC 61034-1&2', 'rawValues' => ['IEC 61034-1&2'], 'usageCount' => 14, 'decision' => SpecMasterDecisionState::PROPOSED_CREATE],
                    ['normalizedStandard' => 'IEC 60228', 'rawValues' => ['IEC 60228'], 'usageCount' => 50, 'decision' => SpecMasterDecisionState::APPROVED_MAP_EXISTING, 'existingTargetOption' => 'IEC-60228'],
                ],
            ],
            'approvals' => [
                'items' => [
                    ['normalizedApproval' => 'BVA', 'rawValues' => ['BVA'], 'usageCount' => 3, 'decision' => SpecMasterDecisionState::PROPOSED_CREATE],
                    ['normalizedApproval' => 'ABS', 'rawValues' => ['ABS'], 'usageCount' => 1, 'decision' => SpecMasterDecisionState::PROPOSED_CREATE],
                    ['normalizedApproval' => 'AS', 'rawValues' => ['AS'], 'usageCount' => 20, 'decision' => SpecMasterDecisionState::PROPOSED_CREATE],
                    ['normalizedApproval' => 'Manufacturing-Assessment', 'rawValues' => ['Manufacturing-Assessment'], 'usageCount' => 2, 'decision' => SpecMasterDecisionState::PROPOSED_CREATE],
                ],
            ],
            'aliases' => [
                'items' => [
                    [
                        'rawValue' => "AS/NZS 1125\nIEC 60228",
                        'targetOption' => 'IEC 60228',
                        'decision' => SpecMasterDecisionState::ALIAS_REVIEW,
                    ],
                    [
                        'rawValue' => 'iec 60228',
                        'targetOption' => 'IEC 60228',
                        'decision' => SpecMasterDecisionState::ALIAS_REVIEW,
                    ],
                ],
            ],
            'units' => [
                'items' => [
                    ['rawUnit' => 'mm', 'targetUnit' => 'MM', 'decision' => SpecMasterDecisionState::APPROVED_MAP_EXISTING],
                ],
            ],
            'coreColours' => [
                'fullCoreColourSpecification' => 'READY',
                'codingCoreColour' => 'SEPARATE',
                'informationLoss' => 'NO',
            ],
            'productReadinessImpact' => [
                'CLASSIFICATION_READY' => 95,
                'FULL_PRODUCT_MIGRATION_READY' => 54,
                'FULL_PRODUCT_MIGRATION_BLOCKED' => 336,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function azure(): array
    {
        return [
            'server' => 'tricab-mel',
            'database' => 'tc',
            'liveValidation' => 'AVAILABLE',
            'groupCount' => 6,
            'definitionCount' => 37,
            'unitCount' => 11,
            'optionCount' => 405,
            'definitions' => [
                ['code' => 'VOLTAGE', 'name' => 'Voltage', 'dataType' => 'text', 'group' => 'electrical'],
                ['code' => 'SHEATH', 'name' => 'Sheath material', 'dataType' => 'text', 'group' => 'construction'],
                ['code' => 'STANDARD', 'name' => 'Standard', 'dataType' => 'multi-select', 'group' => 'compliance'],
                ['code' => 'APPROVALS', 'name' => 'Approvals', 'dataType' => 'multi-select', 'group' => 'compliance'],
                ['code' => 'CORE-COLOURS', 'name' => 'Core Colours', 'dataType' => 'long-text', 'group' => 'construction'],
            ],
            'units' => [['code' => 'MM'], ['code' => 'C'], ['code' => 'V']],
            'options' => [
                ['definitionCode' => 'STANDARD', 'code' => 'IEC-60228', 'name' => 'IEC 60228'],
                ['definitionCode' => 'STANDARD', 'code' => 'IEC-61034-1_2', 'name' => 'IEC 61034-1/2'],
                ['definitionCode' => 'STANDARD', 'code' => 'IEC-60332-1', 'name' => 'IEC 60332-1'],
                ['definitionCode' => 'APPROVALS', 'code' => 'ABS', 'name' => 'ABS'],
                ['definitionCode' => 'APPROVALS', 'code' => 'DNV-GL', 'name' => 'DNV-GL'],
            ],
        ];
    }
}
