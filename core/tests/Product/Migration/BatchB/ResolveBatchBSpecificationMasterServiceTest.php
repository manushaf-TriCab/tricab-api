<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchB;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Migration\Application\Service\ResolveBatchBSpecificationMasterService;
use TriCab\Core\Product\Migration\Specification\SpecMasterDecisionState;

final class ResolveBatchBSpecificationMasterServiceTest extends TestCase
{
    public function testMapsExistingBraidAndStandardDefinitions(): void
    {
        $result = $this->service()->resolve($this->baseInputs([
            'definitionProposals' => [
                [
                    'legacyField' => 'amour_braid_screen',
                    'usageCount' => 120,
                    'exampleValues' => ['TCWB'],
                    'candidateDefinition' => 'BRAID',
                    'status' => 'EXISTING_DEFINITION',
                ],
                [
                    'legacyField' => 'standards',
                    'usageCount' => 490,
                    'exampleValues' => ['IEC 60332-1'],
                    'candidateDefinition' => 'STANDARDS',
                    'status' => 'NEW_DEFINITION_REQUIRED',
                ],
                [
                    'legacyField' => 'search_keywords',
                    'usageCount' => 10,
                    'exampleValues' => ['lug'],
                    'candidateDefinition' => '',
                    'status' => 'AMBIGUOUS',
                ],
                [
                    'legacyField' => 'al-control-image-1',
                    'usageCount' => 5,
                    'exampleValues' => ['1'],
                    'candidateDefinition' => 'MEDIA',
                    'status' => 'NEW_DEFINITION_REQUIRED',
                ],
            ],
        ]));

        $byField = [];
        foreach ($result['definitions']['items'] as $row) {
            $byField[$row['sourceField']] = $row;
        }
        self::assertSame(SpecMasterDecisionState::APPROVED_MAP_EXISTING, $byField['amour_braid_screen']['decision']);
        self::assertSame('BRAID', $byField['amour_braid_screen']['existingTargetDefinition']);
        self::assertSame(SpecMasterDecisionState::APPROVED_MAP_EXISTING, $byField['standards']['decision']);
        self::assertSame('STANDARD', $byField['standards']['existingTargetDefinition']);
        self::assertSame(SpecMasterDecisionState::NOT_A_SPECIFICATION, $byField['search_keywords']['decision']);
        self::assertSame(SpecMasterDecisionState::NOT_A_SPECIFICATION, $byField['al-control-image-1']['decision']);
        self::assertSame(0, $result['definitions']['counts'][SpecMasterDecisionState::APPROVED_CREATE]);
    }

    public function testMm2UnitEquivalence(): void
    {
        $svc = $this->service();
        self::assertSame('mm2', $svc->unitEquivalenceKey('mm²'));
        self::assertSame('mm2', $svc->unitEquivalenceKey('mm2'));
        self::assertSame('mm2', $svc->unitEquivalenceKey('mm^2'));
        $result = $svc->resolve($this->baseInputs([
            'unitsProposed' => [
                'mappings' => [
                    ['legacyRepresentation' => 'mm²', 'canonicalUnitCode' => 'MM2'],
                    ['legacyRepresentation' => 'mm2', 'canonicalUnitCode' => 'MM2'],
                ],
            ],
        ]));
        foreach ($result['units']['items'] as $u) {
            if (in_array($u['rawUnit'], ['mm²', 'mm2', 'mm^2'], true)) {
                self::assertSame('MM2', $u['targetUnit']);
                self::assertSame(SpecMasterDecisionState::APPROVED_MAP_EXISTING, $u['decision']);
            }
        }
    }

    public function testStandardsParsingHtmlAndSerialized(): void
    {
        $svc = $this->service();
        self::assertSame(['IEC 60332-1', 'IEC 60332-3'], $svc->parseStandardsBlob('IEC 60332-1; IEC 60332-3'));
        self::assertSame(['AS/NZS 5000'], $svc->parseStandardsBlob('<p>AS/NZS 5000</p>'));
        $serialized = serialize(['IEC 60502', 'BS 6724']);
        self::assertSame(['IEC 60502', 'BS 6724'], $svc->parseStandardsBlob($serialized));
    }

    public function testDnvAmbiguityNotSafeAlias(): void
    {
        $svc = $this->service();
        self::assertTrue($svc->dnvPairCheck('DNV', 'DNV-GL'));
        self::assertTrue($svc->dnvPairCheck('DNV GL', 'DNV'));
        self::assertFalse($svc->safeAliasCheck('DNV', 'DNV-GL'));
        self::assertTrue($svc->safeAliasCheck('dnv', 'DNV'));
        self::assertTrue($svc->safeAliasCheck('DNV&nbsp;GL', 'DNV GL'));
    }

    public function testCoreColoursPreservedSeparateFromCoding(): void
    {
        $result = $this->service()->resolve($this->baseInputs());
        self::assertSame('READY', $result['coreColours']['fullCoreColourSpecification']);
        self::assertSame('SEPARATE', $result['coreColours']['codingCoreColour']);
        self::assertSame('NO', $result['coreColours']['informationLoss']);
    }

    public function testDryRunDoesNotCreateApprovedDefinitions(): void
    {
        $result = $this->service()->resolve($this->baseInputs([
            'definitionProposals' => [
                [
                    'legacyField' => 'oxygen_index',
                    'usageCount' => 90,
                    'exampleValues' => ['32'],
                    'candidateDefinition' => 'OXYGEN-INDEX',
                    'status' => 'NEW_DEFINITION_REQUIRED',
                ],
            ],
        ]));
        self::assertSame(SpecMasterDecisionState::PROPOSED_CREATE, $result['definitions']['items'][0]['decision']);
        self::assertSame(0, $result['dryRunTargetPlan']['definitionsToCreate']);
        self::assertSame(0, $result['dryRunTargetPlan']['productSpecificationValues']);
        self::assertSame('BATCH_B_APPLY_NOT_APPROVED', $result['approvalBoundary']['batchBApplyGuard']);
        self::assertSame('BATCH_C_NOT_STARTED', $result['approvalBoundary']['batchCGuard']);
        self::assertSame('CONTINUE SPECIFICATION REVIEW', $result['recommendation']);
    }

    public function testDvConceptsDoNotProcessBatchCRowsAsWrites(): void
    {
        $result = $this->service()->resolve($this->baseInputs([
            'specLabels' => [
                ['style' => 'D04', 'dimension' => 'd1', 'label' => 'Overall Diameter<br><i>mm</i>'],
                ['style' => 'J90', 'dimension' => 'd1', 'label' => 'Pins<br>&nbsp;'],
            ],
            'dvRows' => array_fill(0, 10, ['style' => 'X', 'sourceField' => 'd1', 'status' => 'SUGGESTED']),
        ]));
        self::assertSame(10, $result['dvConceptDiscovery']['batchCRowsNotProcessed']);
        self::assertTrue($result['dvConceptDiscovery']['globalFieldAssumptionForbidden']);
        self::assertGreaterThan(0, $result['dvConceptDiscovery']['conceptCount']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseInputs(array $overrides = []): array
    {
        return array_merge([
            'azure' => [
                'server' => 'tricab-mel',
                'database' => 'tc',
                'liveValidation' => 'AVAILABLE',
                'groups' => [
                    ['code' => 'construction', 'name' => 'Construction'],
                    ['code' => 'compliance', 'name' => 'Compliance'],
                ],
                'definitions' => [
                    ['code' => 'BRAID', 'name' => 'Braid', 'dataType' => 'long-text', 'group' => 'construction'],
                    ['code' => 'STANDARD', 'name' => 'Standard', 'dataType' => 'multi-select', 'group' => 'compliance'],
                    ['code' => 'APPROVALS', 'name' => 'Approvals', 'dataType' => 'multi-select', 'group' => 'compliance'],
                    ['code' => 'CORE-COLOURS', 'name' => 'Core Colours', 'dataType' => 'long-text', 'group' => 'construction'],
                    ['code' => 'CODE_CORE_COLOUR', 'name' => 'Core Colour Configuration', 'dataType' => 'single-select'],
                    ['code' => 'NOM-CABLE-OD', 'name' => 'Nom. Cable', 'dataType' => 'decimal'],
                    ['code' => 'CURRENT', 'name' => 'Current Rating', 'dataType' => 'decimal'],
                ],
                'units' => [
                    ['code' => 'MM', 'name' => 'Millimetre'],
                    ['code' => 'MM2', 'name' => 'Square millimetre'],
                    ['code' => 'A', 'name' => 'Ampere'],
                    ['code' => 'V', 'name' => 'Volt'],
                    ['code' => 'C', 'name' => 'Degree Celsius'],
                    ['code' => 'M', 'name' => 'Metre'],
                    ['code' => 'EA', 'name' => 'Each'],
                    ['code' => 'FT', 'name' => 'Foot'],
                    ['code' => 'PK', 'name' => 'Pack'],
                    ['code' => 'KG', 'name' => 'Kilogram'],
                    ['code' => 'KG_PER_KM', 'name' => 'Kilogram per km'],
                ],
                'options' => [
                    ['definitionCode' => 'STANDARD', 'code' => 'IEC-60332-1', 'name' => 'IEC 60332-1'],
                    ['definitionCode' => 'APPROVALS', 'code' => 'ABS', 'name' => 'ABS'],
                ],
                'optionCount' => 2,
                'optionActiveCount' => 2,
                'optionInactiveCount' => 0,
            ],
            'definitionProposals' => [],
            'unitsProposed' => ['mappings' => []],
            'standardsProposed' => ['values' => [
                ['rawValue' => 'IEC 60332-1', 'parsedValue' => 'IEC 60332-1', 'canonicalCandidate' => 'IEC 60332-1', 'usageCount' => 3],
                ['rawValue' => 'IEC 60332-3', 'parsedValue' => 'IEC 60332-3', 'canonicalCandidate' => 'IEC 60332-3', 'usageCount' => 2],
            ]],
            'approvalsProposed' => ['values' => [
                ['rawValue' => 'DNV', 'canonicalCandidate' => 'DNV', 'usageCount' => 4],
                ['rawValue' => 'DNV-GL', 'canonicalCandidate' => 'DNV-GL', 'usageCount' => 2],
                ['rawValue' => 'ABS', 'canonicalCandidate' => 'ABS', 'usageCount' => 1],
                ['rawValue' => 'News', 'canonicalCandidate' => 'News', 'usageCount' => 5],
            ]],
            'optionsProposed' => ['proposals' => [
                ['sourceTable' => 'tpro_ext', 'sourceColumn' => 'colour', 'canonicalOption' => 'BK', 'legacyAliases' => ['bk', 'Bk'], 'usageCount' => 10],
            ]],
            'dvRows' => [],
            'specLabels' => [],
            'operatorDecisions' => [],
            'classificationReady' => 95,
            'fullProductMigrationReady' => 54,
            'fullProductMigrationBlocked' => 336,
        ], $overrides);
    }

    private function service(): ResolveBatchBSpecificationMasterService
    {
        return new ResolveBatchBSpecificationMasterService();
    }
}
