<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchB;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TriCab\Core\Product\Migration\Application\Service\ApplyBatchBSpecificationMasterService;

final class ApplyBatchBSpecificationMasterServiceTest extends TestCase
{
    public function testRejectsWrongTarget(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->invokePrivate($svc, 'assertPreflight', [[
            'server' => 'wrong-server',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'groups' => array_fill(0, 6, ['code' => 'x']),
            'definitions' => array_fill(0, 37, ['code' => 'D']),
            'units' => array_fill(0, 11, ['code' => 'U']),
            'options' => 405,
            'products' => 118,
            'variants' => 4178,
            'categories' => 13,
            'series' => 97,
            'catalogueNodes' => 97,
        ]]);
    }

    public function testRejectsDefinitionCountDrift(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected Definition count');
        $pre = $this->okPreflight();
        $pre['definitions'] = array_fill(0, 40, ['code' => 'D']);
        $this->invokePrivate($svc, 'assertPreflight', [$pre]);
    }

    public function testValidPackageCounts(): void
    {
        $svc = $this->serviceWithoutMocks();
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $defs = $this->loadJson($docs . '/batch-b-approved-definitions.json');
        $std = $this->loadJson($docs . '/batch-b-approved-standards.json');
        $apr = $this->loadJson($docs . '/batch-b-approved-approvals.json');
        $aliases = $this->loadJson($docs . '/batch-b-approved-option-aliases.json');
        if ($defs === [] || $std === [] || $apr === [] || $aliases === []) {
            self::markTestSkipped('Approved Batch B artifacts not present');
        }
        $plan = $this->invokePrivate($svc, 'validateApprovedPackage', [
            $defs,
            $std,
            $apr,
            $aliases,
            $this->okPreflightWithLiveCodes($defs),
        ]);
        self::assertSame(10, $plan['mapExistingDefinitions']);
        self::assertCount(5, $plan['uniqueDefinitionCreates']);
        self::assertSame(16, $plan['definitionMappingsExpected']);
        self::assertSame(23, $plan['standardMapExisting']);
        self::assertSame(11, $plan['standardCreates']);
        self::assertSame(1, $plan['approvalMapExisting']);
        self::assertSame(0, $plan['approvalCreates']);
        self::assertSame(35, $plan['optionMappingsExpected']);
        self::assertSame(2, $plan['safeAliasesExpected']);
        self::assertArrayHasKey('OPERATING-TEMP', $plan['uniqueDefinitionCreates']);
        self::assertArrayHasKey('THREAD-OD', $plan['uniqueDefinitionCreates']);
    }

    public function testForbidsDnvToDnvGlAlias(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DNV');
        $this->invokePrivate($svc, 'validateApprovedPackage', [
            $this->minimalDefs(),
            $this->minimalStandards(),
            $this->minimalApprovals(),
            [[
                'rawValue' => 'DNV',
                'targetOption' => 'DNV-GL',
                'decision' => 'SAFE_ALIAS',
            ], [
                'rawValue' => 'x',
                'targetOption' => 'IEC 60332-1',
                'decision' => 'SAFE_ALIAS',
            ]],
            $this->okPreflightWithLiveCodes($this->minimalDefs()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function okPreflight(): array
    {
        return [
            'server' => 'tricab-mel',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'groups' => [
                ['code' => 'THERMAL', 'publicId' => 'g1'],
                ['code' => 'COMPLIANCE', 'publicId' => 'g2'],
                ['code' => 'CONSTRUCTION', 'publicId' => 'g3'],
                ['code' => 'MECHANICAL', 'publicId' => 'g4'],
                ['code' => 'ELECTRICAL', 'publicId' => 'g5'],
                ['code' => 'PACKAGING', 'publicId' => 'g6'],
            ],
            'definitions' => array_merge(
                array_fill(0, 32, ['code' => 'X', 'publicId' => 'd0']),
                [
                    ['code' => 'STANDARD', 'publicId' => 'std'],
                    ['code' => 'APPROVALS', 'publicId' => 'apr'],
                    ['code' => 'SHEATH', 'publicId' => 'sh'],
                    ['code' => 'VOLTAGE', 'publicId' => 'v'],
                    ['code' => 'BRAID', 'publicId' => 'b'],
                ],
            ),
            'units' => [
                ['code' => 'C', 'symbol' => '°C', 'publicId' => 'u1'],
                ['code' => 'MM', 'symbol' => 'mm', 'publicId' => 'u2'],
                ['code' => 'V', 'symbol' => 'V', 'publicId' => 'u3'],
                ['code' => 'A', 'symbol' => 'A', 'publicId' => 'u4'],
                ['code' => 'M', 'symbol' => 'm', 'publicId' => 'u5'],
                ['code' => 'EA', 'symbol' => 'ea', 'publicId' => 'u6'],
                ['code' => 'FT', 'symbol' => 'ft', 'publicId' => 'u7'],
                ['code' => 'PK', 'symbol' => 'pk', 'publicId' => 'u8'],
                ['code' => 'MM2', 'symbol' => 'mm²', 'publicId' => 'u9'],
                ['code' => 'KG', 'symbol' => 'kg', 'publicId' => 'u10'],
                ['code' => 'KG_PER_KM', 'symbol' => 'kg/km', 'publicId' => 'u11'],
            ],
            'options' => 405,
            'products' => 118,
            'variants' => 4178,
            'categories' => 13,
            'series' => 97,
            'catalogueNodes' => 97,
        ];
    }

    /**
     * @param list<array<string, mixed>> $approvedDefs
     * @return array<string, mixed>
     */
    private function okPreflightWithLiveCodes(array $approvedDefs): array
    {
        $pre = $this->okPreflight();
        $defs = [];
        foreach ($approvedDefs as $row) {
            if (($row['decision'] ?? '') === 'APPROVED_MAP_EXISTING') {
                $code = strtoupper((string) ($row['existingTargetDefinition'] ?? $row['proposedDefinitionCode'] ?? ''));
                if ($code !== '') {
                    $defs[$code] = ['code' => $code, 'publicId' => 'pid-' . $code];
                }
            }
        }
        $defs['STANDARD'] = ['code' => 'STANDARD', 'publicId' => 'std'];
        $defs['APPROVALS'] = ['code' => 'APPROVALS', 'publicId' => 'apr'];
        // Pad to 37
        $i = 0;
        while (count($defs) < 37) {
            $defs['PAD' . $i] = ['code' => 'PAD' . $i, 'publicId' => 'pad' . $i];
            ++$i;
        }
        $pre['definitions'] = array_values($defs);

        return $pre;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function minimalDefs(): array
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7/batch-b-approved-definitions.json';
        $rows = $this->loadJson($docs);

        return $rows !== [] ? $rows : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function minimalStandards(): array
    {
        $rows = [];
        for ($i = 0; $i < 23; ++$i) {
            $rows[] = [
                'normalizedStandard' => 'STD-' . $i,
                'existingTargetOption' => 'STD-' . $i,
                'proposedOption' => ['code' => 'STD-' . $i, 'displayName' => 'STD-' . $i],
                'decision' => 'APPROVED_MAP_EXISTING',
            ];
        }
        for ($i = 0; $i < 11; ++$i) {
            $rows[] = [
                'normalizedStandard' => 'NEW-' . $i,
                'proposedOption' => ['code' => 'NEW-' . $i, 'displayName' => 'NEW-' . $i],
                'decision' => 'APPROVED_CREATE',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function minimalApprovals(): array
    {
        return [[
            'normalizedApproval' => 'BV',
            'existingTargetOption' => 'BV',
            'proposedOption' => ['code' => 'BV', 'displayName' => 'BV'],
            'decision' => 'APPROVED_MAP_EXISTING',
        ]];
    }

    private function serviceWithoutMocks(): ApplyBatchBSpecificationMasterService
    {
        $ref = new \ReflectionClass(ApplyBatchBSpecificationMasterService::class);

        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(object $svc, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($svc, $method);

        return $ref->invokeArgs($svc, $args);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
