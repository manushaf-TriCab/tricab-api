<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\BatchC2;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TriCab\Core\Product\Migration\Application\Service\ApplyBatchC2StyleFieldMappingService;

final class ApplyBatchC2StyleFieldMappingServiceTest extends TestCase
{
    public function testRejectsWrongTarget(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tricab-mel');
        $this->invokePrivate($svc, 'assertPreflight', [[
            'server' => 'wrong-server',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'liveValidation' => 'AVAILABLE',
            'definitions' => array_fill(0, 42, ['code' => 'D']),
            'definitionCount' => 42,
            'unitCount' => 11,
            'optionCount' => 416,
            'revalidationConflicts' => 0,
            'mappingConflicts' => 0,
            'operatorApprovalRecognised' => true,
            'artifactsValid' => true,
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
        $pre['definitionCount'] = 40;
        $this->invokePrivate($svc, 'assertPreflight', [$pre]);
    }

    public function testAllowsPostApplyDefinitionCountForIdempotency(): void
    {
        $svc = $this->serviceWithoutMocks();
        $pre = $this->okPreflight();
        $codes = [
            'OVERALL-LENGTH', 'WIDTH', 'HEIGHT', 'INNER-DIAMETER', 'CABLE-LENGTH', 'BARREL-LENGTH',
            'STUD-OD', 'OUTER-DIAMETER', 'THREAD-LENGTH', 'BARREL-OD', 'THICKNESS', 'DEPTH', 'PIN-DIAMETER',
        ];
        $defs = [];
        for ($i = 0; $i < 42; ++$i) {
            $defs[] = ['code' => 'EXISTING-' . $i];
        }
        foreach ($codes as $code) {
            $defs[] = ['code' => $code];
        }
        $pre['definitions'] = $defs;
        $pre['definitionCount'] = 55;
        $this->invokePrivate($svc, 'assertPreflight', [$pre]);
        self::assertTrue(true);
    }

    public function testBuildPlanFromApprovedArtifacts(): void
    {
        $svc = $this->serviceWithoutMocks();
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $defs = $this->loadJson($docs . '/batch-c2-approved-definitions-pre-apply.json');
        if ($defs === []) {
            $defs = $this->loadJson($docs . '/batch-c2-approved-definitions.json');
        }
        $maps = $this->loadJson($docs . '/batch-c2-approved-field-mappings.json');
        if ($defs === [] || $maps === []) {
            self::markTestSkipped('Approved Batch C.2 artifacts not present');
        }
        $plan = $this->invokePrivate($svc, 'buildPlan', [$defs, $maps, $this->okPreflightWithExistingDefs()]);
        self::assertCount(13, $plan['definitionsToCreate']);
        self::assertSame(483, $plan['fieldMappingsRequested']);
        self::assertContains('OUTER-DIAMETER', $plan['definitionsToCreate']);
        self::assertNotContains('IND-LSZH-FIRE', $plan['definitionsToCreate']);
        self::assertNotContains('NOM-CABLE-OD', $plan['definitionsToCreate']);
        // Post-apply artifacts resolve pending → APPROVED_MAP; pre-apply had 177/306.
        self::assertSame(
            483,
            (int) $plan['approvedMapCount'] + (int) $plan['approvedPendingDefinitionCount'],
        );
    }

    public function testPackageDoesNotMapOuterDiameterToNomCableOd(): void
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $maps = $this->loadJson($docs . '/batch-c2-approved-field-mappings.json');
        if ($maps === []) {
            self::markTestSkipped('Approved Batch C.2 artifacts not present');
        }
        foreach ($maps as $row) {
            $label = strtolower((string) ($row['normalizedLabel'] ?? ''));
            if (in_array($label, ['outerdia mm', 'outer dia mm'], true)) {
                self::assertSame('OUTER-DIAMETER', strtoupper((string) ($row['targetDefinitionCode'] ?? '')));
                self::assertNotSame('NOM-CABLE-OD', strtoupper((string) ($row['targetDefinitionCode'] ?? '')));
            }
        }
        self::assertTrue(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function okPreflight(): array
    {
        return [
            'server' => 'tricab-mel.database.windows.net',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'liveValidation' => 'AVAILABLE',
            'definitions' => array_fill(0, 42, ['code' => 'D']),
            'definitionCount' => 42,
            'unitCount' => 11,
            'optionCount' => 416,
            'revalidationConflicts' => 0,
            'mappingConflicts' => 0,
            'operatorApprovalRecognised' => true,
            'artifactsValid' => true,
            'products' => 118,
            'variants' => 4178,
            'categories' => 13,
            'series' => 97,
            'catalogueNodes' => 97,
            'groups' => [],
            'units' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function okPreflightWithExistingDefs(): array
    {
        $pre = $this->okPreflight();
        $pre['definitions'] = [
            ['code' => 'CORE-COLOURS', 'publicId' => 'a'],
            ['code' => 'CORES', 'publicId' => 'b'],
            ['code' => 'PIN-COUNT', 'publicId' => 'c'],
            ['code' => 'NOM-CABLE-OD', 'publicId' => 'd'],
        ];
        for ($i = 0; $i < 38; ++$i) {
            $pre['definitions'][] = ['code' => 'X' . $i, 'publicId' => 'id' . $i];
        }

        return $pre;
    }

    private function serviceWithoutMocks(): ApplyBatchC2StyleFieldMappingService
    {
        $ref = new \ReflectionClass(ApplyBatchC2StyleFieldMappingService::class);

        return $ref->newInstanceWithoutConstructor();
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $svc, string $method, array $args): mixed
    {
        $m = new ReflectionMethod($svc, $method);

        return $m->invokeArgs($svc, $args);
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
