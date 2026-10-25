<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\CableSpec;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService;
use TriCab\Core\Product\Migration\Application\Service\CableSpecBatchD4CliGuard;
use TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD3FinalDryRunService;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class BatchD4CableSpecTargetApplyTest extends TestCase
{
    public function testApprovalRequired(): void
    {
        self::assertSame(
            CableSpecBatchD4CliGuard::APPLY_NOT_APPROVED,
            CableSpecBatchD4CliGuard::validateApplyArgs(['--batch-d4', '--confirm-non-production']),
        );
    }

    public function testNonProductionConfirmationRequired(): void
    {
        self::assertSame(
            CableSpecBatchD4CliGuard::NON_PRODUCTION_CONFIRMATION_REQUIRED,
            CableSpecBatchD4CliGuard::validateApplyArgs(['--apply', '--batch-d4', '--cable-spec-d4-apply-approved']),
        );
    }

    public function testProductionApplyRejectedViaPreflight(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tricab-mel');
        $this->invokePrivate($svc, 'assertPreflight', [[
            'server' => 'prod-server',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'liveValidation' => 'AVAILABLE',
            'definitionCount' => 55,
            'unitCount' => 11,
            'optionCount' => 416,
            'operatorApprovalRecognised' => true,
            'artifactsValid' => true,
            'd3PackageValid' => true,
            'mappingConflicts' => 0,
            'definitionConflicts' => 0,
            'styleFieldMappingCount' => 483,
            'fieldMappingCount' => 506,
            'definitions' => $this->definitions55(),
        ]]);
    }

    public function testLivePreflightDriftRejected(): void
    {
        $svc = $this->serviceWithoutMocks();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(CableSpecBatchD4CliGuard::LIVE_PREFLIGHT_DRIFT);
        $pre = $this->okPreflight();
        $pre['definitionCount'] = 54;
        $this->invokePrivate($svc, 'assertPreflight', [$pre]);
    }

    public function testD3ArtifactMappingPackageValidated(): void
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $maps = $this->loadJson($docs . '/cable-spec-d3-approved-field-mappings.json');
        if ($maps === []) {
            self::markTestSkipped('D.3 mapping artifact missing');
        }
        $items = is_array($maps['items'] ?? null) ? $maps['items'] : $maps;
        $svc = $this->serviceWithoutMocks();
        $this->invokePrivate($svc, 'assertMappingPackage', [$items]);
        self::assertSame(538, is_int($maps['count'] ?? null) ? $maps['count'] : count($items));
    }

    public function testNineDefinitionsInPlan(): void
    {
        $svc = $this->serviceWithoutMocks();
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $items = $this->mappingItemsFromArtifact($docs);
        if ($items === []) {
            self::markTestSkipped('D.3 mapping artifact missing');
        }
        $plan = $this->invokePrivate($svc, 'buildPlan', [$items, $this->okPreflight()]);
        self::assertCount(9, $plan['definitionsToCreate']);
    }

    public function testEarthCsaDecimal(): void
    {
        $meta = ApplyCableSpecBatchD4TargetApplyService::requiredCreatesMeta();
        self::assertSame('decimal', $meta['EARTH-CSA']['dataType']);
        self::assertSame('MM2', $meta['EARTH-CSA']['unitCode']);
    }

    public function testAmbientCurrentDefinitionsSeparate(): void
    {
        $meta = ApplyCableSpecBatchD4TargetApplyService::requiredCreatesMeta();
        self::assertArrayHasKey('CURRENT-30C', $meta);
        self::assertArrayHasKey('CURRENT-40C', $meta);
        self::assertArrayHasKey('CURRENT-45C', $meta);
        self::assertArrayNotHasKey('CURRENT', $meta);
    }

    public function testGenericCurrentNotUsedInArtifact(): void
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        foreach ($this->mappingItemsFromArtifact($docs) as $row) {
            $field = (string) ($row['sourceField'] ?? $row['legacyField'] ?? '');
            if (in_array($field, ['A30c', 'A40c', 'A45c'], true)) {
                self::assertNotSame('CURRENT', strtoupper((string) ($row['targetDefinitionCode'] ?? '')));
            }
        }
        self::assertTrue(true);
    }

    public function testSevenExistingTargetMappingsInArtifact(): void
    {
        $expected = [
            'nc' => 'CORES',
            'xa' => 'CSA',
            'od' => 'NOM-CABLE-OD',
            'cdia' => 'NOM-COND-OD',
            'od_ins' => 'NOM-INS-OD',
            'br' => 'MIN-BEND-RADIUS',
            'wght' => 'APPROX-WEIGHT',
        ];
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $seen = [];
        foreach ($this->mappingItemsFromArtifact($docs) as $row) {
            $field = (string) ($row['legacyField'] ?? $row['sourceField'] ?? '');
            if (isset($expected[$field])) {
                $seen[$field] = strtoupper((string) ($row['targetDefinitionCode'] ?? ''));
            }
        }
        foreach ($expected as $field => $code) {
            self::assertSame($code, $seen[$field] ?? null, $field);
        }
    }

    public function testReviewFieldsExcludedFromArtifact(): void
    {
        $review = ['cc', 'ct', 'ins', 'walu', 'wbnz', 'Af', 'r', 'x50', 'x60', 'tension'];
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        foreach ($this->mappingItemsFromArtifact($docs) as $row) {
            self::assertNotContains((string) ($row['legacyField'] ?? ''), $review);
        }
    }

    public function testNotSpecFieldsExcludedFromArtifact(): void
    {
        $notSpec = ['cable_style', 'my_row_id', 'height', 'width', 'A15c', 'A25c', 'cap', 'core_con', 'mt'];
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        foreach ($this->mappingItemsFromArtifact($docs) as $row) {
            self::assertNotContains((string) ($row['legacyField'] ?? ''), $notSpec);
        }
    }

    public function testOdMapsToNomCableOdNeverOuterDiameter(): void
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        foreach ($this->mappingItemsFromArtifact($docs) as $row) {
            if ((string) ($row['legacyField'] ?? '') === 'od') {
                self::assertSame('NOM-CABLE-OD', strtoupper((string) ($row['targetDefinitionCode'] ?? '')));
                self::assertNotSame('OUTER-DIAMETER', strtoupper((string) ($row['targetDefinitionCode'] ?? '')));
            }
        }
        self::assertTrue(true);
    }

    public function testNoIndLszhFireInCreates(): void
    {
        self::assertNotContains('IND-LSZH-FIRE', ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES);
    }

    public function testDryRunReportsZeroWrites(): void
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $items = $this->mappingItemsFromArtifact($docs);
        if ($items === []) {
            self::markTestSkipped('D.3 mapping artifact missing');
        }
        $svc = $this->serviceWithoutMocks();
        $result = $svc->apply($this->actor(), $items, $this->okPreflight(), true);
        self::assertSame(0, $result['actualWrites']['definitionsCreated']);
        self::assertSame(0, $result['actualWrites']['mappingsInserted']);
        self::assertSame(0, $result['actualWrites']['specificationValues']);
    }

    public function testMappingConflictDetected(): void
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $items = $this->mappingItemsFromArtifact($docs);
        if ($items === []) {
            self::markTestSkipped('D.3 mapping artifact missing');
        }
        $first = $items[0];
        $style = strtoupper((string) ($first['styleCode'] ?? ''));
        $field = strtolower((string) ($first['sourceField'] ?? ''));
        $repo = $this->createMock(MigrationBatchRepository::class);
        $repo->method('listSpecificationMappings')->willReturn([[
            'sourceSystem' => 'tc',
            'sourceTable' => 'cable_specs',
            'sourceField' => $field,
            'legacyStyleCode' => $style,
            'targetDefinitionCode' => 'WRONG-CODE',
            'targetSpecificationDefinitionPublicId' => '00000000-0000-4000-8000-000000000099',
            'statusCode' => 'mapped',
        ]]);
        $svc = $this->serviceWithRepository($repo);
        $result = $this->invokePrivate($svc, 'upsertStyleMappingIdempotent', [
            $this->actor(),
            [
                'sourceSystem' => 'tc',
                'sourceTable' => 'cable_specs',
                'sourceField' => $field,
                'legacyStyleCode' => $style,
                'targetDefinitionCode' => strtoupper((string) ($first['targetDefinitionCode'] ?? '')),
                'targetSpecificationDefinitionPublicId' => 'id-' . strtoupper((string) ($first['targetDefinitionCode'] ?? '')),
                'statusCode' => 'mapped',
            ],
        ]);
        self::assertTrue($result['conflict']);
        self::assertSame('WRONG-CODE', $result['existingTarget']);
    }

    public function testIdempotentSecondApplyProjection(): void
    {
        $docs = dirname(__DIR__, 4) . '/docs/phase12.7';
        $items = $this->mappingItemsFromArtifact($docs);
        if ($items === []) {
            self::markTestSkipped('D.3 mapping artifact missing');
        }
        $pre = $this->okPreflightPostApply();
        $svc = $this->serviceWithoutMocks();
        $plan = $this->invokePrivate($svc, 'buildPlan', [$items, $pre]);
        self::assertCount(9, $plan['definitionsAlreadyExisting']);
        self::assertSame(538, $plan['fieldMappingsRequested']);

        $first = $items[0];
        $code = strtoupper((string) ($first['targetDefinitionCode'] ?? ''));
        $repo = $this->createMock(MigrationBatchRepository::class);
        $repo->method('listSpecificationMappings')->willReturn([[
            'sourceSystem' => 'tc',
            'sourceTable' => 'cable_specs',
            'sourceField' => strtolower((string) ($first['sourceField'] ?? '')),
            'legacyStyleCode' => strtoupper((string) ($first['styleCode'] ?? '')),
            'targetDefinitionCode' => $code,
            'targetSpecificationDefinitionPublicId' => 'id-' . $code,
            'statusCode' => 'mapped',
        ]]);
        $svc = $this->serviceWithRepository($repo);
        $result = $this->invokePrivate($svc, 'upsertStyleMappingIdempotent', [
            $this->actor(),
            [
                'sourceSystem' => 'tc',
                'sourceTable' => 'cable_specs',
                'sourceField' => strtolower((string) ($first['sourceField'] ?? '')),
                'legacyStyleCode' => strtoupper((string) ($first['styleCode'] ?? '')),
                'targetDefinitionCode' => $code,
                'targetSpecificationDefinitionPublicId' => 'id-' . $code,
                'statusCode' => 'mapped',
            ],
        ]);
        self::assertTrue($result['identical']);
        self::assertFalse($result['inserted']);
    }

    public function testApprovalFlagsAccepted(): void
    {
        self::assertNull(CableSpecBatchD4CliGuard::validateApplyArgs([
            '--apply',
            '--batch-d4',
            '--confirm-non-production',
            '--cable-spec-d4-apply-approved',
        ]));
    }

    public function testD3PackageStatusesUsed(): void
    {
        self::assertSame('APPROVED_MAP_EXISTING', ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_MAP_EXISTING);
        self::assertSame('APPROVED_PENDING_DEFINITION', ResolveCableSpecBatchD3FinalDryRunService::STATUS_APPROVED_PENDING_DEFINITION);
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
            'definitions' => $this->definitions55(),
            'definitionCount' => 55,
            'unitCount' => 11,
            'optionCount' => 416,
            'styleFieldMappingCount' => 483,
            'fieldMappingCount' => 506,
            'operatorApprovalRecognised' => true,
            'artifactsValid' => true,
            'd3PackageValid' => true,
            'mappingConflicts' => 0,
            'definitionConflicts' => 0,
            'products' => 118,
            'variants' => 4178,
            'categories' => 13,
            'series' => 97,
            'catalogueNodes' => 97,
            'groups' => [
                ['code' => 'CONSTRUCTION', 'publicId' => 'g1'],
                ['code' => 'MECHANICAL', 'publicId' => 'g2'],
                ['code' => 'ELECTRICAL', 'publicId' => 'g3'],
            ],
            'units' => [
                ['code' => 'MM', 'publicId' => 'u1', 'symbol' => 'mm'],
                ['code' => 'MM2', 'publicId' => 'u2', 'symbol' => 'mm²'],
                ['code' => 'A', 'publicId' => 'u3', 'symbol' => 'A'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function okPreflightPostApply(): array
    {
        $pre = $this->okPreflight();
        $defs = $pre['definitions'];
        foreach (ApplyCableSpecBatchD4TargetApplyService::REQUIRED_CREATE_CODES as $code) {
            $defs[] = ['code' => $code, 'publicId' => 'id-' . $code];
        }
        $pre['definitions'] = $defs;
        $pre['definitionCount'] = 64;
        $pre['fieldMappingCount'] = 1044;

        return $pre;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions55(): array
    {
        $codes = array_merge(
            ApplyCableSpecBatchD4TargetApplyService::REQUIRED_EXISTING_DEFINITION_CODES,
            ['OUTER-DIAMETER', 'COLOUR', 'VOLTAGE'],
        );
        while (count($codes) < 55) {
            $codes[] = 'EXTRA-' . count($codes);
        }
        $out = [];
        foreach ($codes as $code) {
            $out[] = ['code' => $code, 'publicId' => 'id-' . $code];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mappingItemsFromArtifact(string $docs): array
    {
        $maps = $this->loadJson($docs . '/cable-spec-d3-approved-field-mappings.json');
        if ($maps === []) {
            return [];
        }

        return is_array($maps['items'] ?? null) ? $maps['items'] : $maps;
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, '00000000-0000-4000-8000-000000000003', 'd4@test.com');
    }

    private function serviceWithoutMocks(): ApplyCableSpecBatchD4TargetApplyService
    {
        $ref = new \ReflectionClass(ApplyCableSpecBatchD4TargetApplyService::class);

        return $ref->newInstanceWithoutConstructor();
    }

    private function serviceWithRepository(MigrationBatchRepository $batches): ApplyCableSpecBatchD4TargetApplyService
    {
        $svc = $this->serviceWithoutMocks();
        $ref = new \ReflectionClass($svc);
        foreach ([
            'batches' => $batches,
            'transaction' => new class implements ApplicationTransaction {
                public function run(callable $operation): mixed
                {
                    return $operation();
                }
            },
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setValue($svc, $value);
        }

        return $svc;
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
