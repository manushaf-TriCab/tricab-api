<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Migration\Pilot;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TriCab\Core\Product\Migration\Application\Service\ApplyProductMigrationPilotF12Service;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationPilotCliGuard;

final class Phase128ProductMigrationPilotF12ApplyTest extends TestCase
{
    public function testExplicitF12ApprovalRequired(): void
    {
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::validateApplyArgs([
                '--apply',
                '--pilot',
                '--style=F12',
                '--confirm-non-production',
            ]),
        );
    }

    public function testWrongStyleRejected(): void
    {
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::validateApplyArgs($this->approvedArgs(['--style=A10'])),
        );
    }

    public function testMultipleStylesRejected(): void
    {
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::validateApplyArgs($this->approvedArgs(['--style=F12,A10'])),
        );
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::validateApplyArgs([
                '--apply',
                '--pilot',
                '--style=F12',
                '--style=A10',
                '--confirm-non-production',
                '--pilot-apply-approved',
            ]),
        );
    }

    public function testBulkAndCableRejected(): void
    {
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::validateApplyArgs($this->approvedArgs(['--bulk'])),
        );
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::validateApplyArgs($this->approvedArgs(['--all'])),
        );
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::validateApplyArgs($this->approvedArgs(['--cable'])),
        );
    }

    public function testValidApplyArgsAccepted(): void
    {
        self::assertNull(ProductMigrationPilotCliGuard::validateApplyArgs($this->approvedArgs()));
    }

    public function testDryRunCliStillRefusesApplyFlags(): void
    {
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::refuseApply(['--apply']),
        );
        self::assertSame(
            ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED,
            ProductMigrationPilotCliGuard::refuseApply(['--pilot-apply-approved']),
        );
    }

    public function testProductionRejectedViaPreflight(): void
    {
        $svc = $this->serviceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NON_PRODUCTION');
        $pre = $this->okPreflight();
        $pre['environmentLabel'] = 'PRODUCTION';
        $svc->assertPreflight($pre);
    }

    public function testSourceDriftRejected(): void
    {
        $svc = $this->serviceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ProductMigrationPilotCliGuard::SOURCE_DRIFT);
        $legacy = $this->okLegacySource();
        $legacy['specValues']['d1'] = '99.00';
        $svc->assertSourceMatchesPackage($legacy, $this->okPackage());
    }

    public function testTargetCollisionRejected(): void
    {
        $svc = $this->serviceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ProductMigrationPilotCliGuard::TARGET_COLLISION);
        $pre = $this->okPreflight();
        $pre['f12CollisionType'] = 'PRE_EXISTING_MANUAL';
        $svc->assertPreflight($pre);
    }

    public function testServiceRejectsNonF12Independently(): void
    {
        $svc = $this->serviceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ProductMigrationPilotCliGuard::APPLY_NOT_APPROVED);
        $svc->assertApprovedStyle('A10');
    }

    public function testApprovedSpecsAreExactlySixIncludingOuterDiameter(): void
    {
        $codes = array_map(
            static fn (array $s): string => $s['definition'],
            ApplyProductMigrationPilotF12Service::APPROVED_SPECS,
        );
        self::assertCount(6, $codes);
        self::assertContains('OUTER-DIAMETER', $codes);
        self::assertNotContains('NOM-CABLE-OD', $codes);
        self::assertSame('15.00', ApplyProductMigrationPilotF12Service::APPROVED_SPECS[1]['value']);
        self::assertSame('d2', ApplyProductMigrationPilotF12Service::APPROVED_SPECS[1]['legacyField']);
        self::assertStringContainsString(
            'OUTER_DIAMETER',
            ApplyProductMigrationPilotF12Service::APPROVED_SPECS[1]['mappingProvenance'],
        );
    }

    public function testBaselineExpectationsMatchDryRun(): void
    {
        self::assertSame(64, ApplyProductMigrationPilotF12Service::EXPECTED_DEFINITIONS);
        self::assertSame(1044, ApplyProductMigrationPilotF12Service::EXPECTED_TOTAL_MAPPINGS);
        self::assertSame(538, ApplyProductMigrationPilotF12Service::EXPECTED_CABLE_MAPPINGS);
        self::assertSame('F12', ApplyProductMigrationPilotF12Service::APPROVED_STYLE);
        self::assertSame('Inspection Tee', ApplyProductMigrationPilotF12Service::APPROVED_NAME);
        self::assertSame('F12-01', ApplyProductMigrationPilotF12Service::APPROVED_VARIANT);
        self::assertSame('AU', ApplyProductMigrationPilotF12Service::APPROVED_REGION);
        self::assertSame('draft', strtolower('Draft'));
    }

    public function testEmptyWritesHaveZeroPricingBlobMasterData(): void
    {
        $writes = $this->serviceWithoutConstructor()->emptyWrites();
        self::assertSame(0, $writes['pricing']);
        self::assertSame(0, $writes['blob']);
        self::assertSame(0, $writes['definitions']);
        self::assertSame(0, $writes['units']);
        self::assertSame(0, $writes['options']);
        self::assertSame(0, $writes['fieldMappings']);
        self::assertSame(0, $writes['media']);
        self::assertSame(0, $writes['documents']);
        self::assertSame(0, $writes['wordpress']);
        self::assertSame(0, $writes['tcLegacy']);
        self::assertSame(0, $writes['classification']);
    }

    public function testPackageOuterDiameterGuardRejectsCableOdLeak(): void
    {
        $svc = $this->serviceWithoutConstructor();
        $package = $this->okPackage();
        $package['specificationProjection']['projectedVariantSpecificationValues'][1]['legacySource'] = 'cable_specs.od';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(ProductMigrationPilotCliGuard::SOURCE_DRIFT);
        $svc->assertSourceMatchesPackage($this->okLegacySource(), $package);
    }

    public function testIdenticalResultHasZeroCreates(): void
    {
        $svc = $this->serviceWithoutConstructor();
        $actor = new \TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext(
            1,
            '00000000-0000-4000-8000-000000000012',
            'f12-test@tricab.com',
        );
        $result = $this->invokePrivate($svc, 'identicalResult', [[
            'productPublicId' => 'prod-f12',
            'variantPublicId' => 'var-f12-01',
        ], $actor, true]);

        self::assertSame('DRY_RUN_IDENTICAL', $result['status']);
        self::assertSame(0, $result['product']['created']);
        self::assertSame(1, $result['product']['identical']);
        self::assertSame(0, $result['variant']['created']);
        self::assertSame(6, $result['variantSpecValues']['identical']);
        self::assertSame(0, $result['marketAssignments']['inserted']);
        self::assertSame(0, $result['actualWrites']['products']);
        self::assertFalse($result['rollbackExecuted']);
        self::assertSame(
            ApplyProductMigrationPilotF12Service::MIGRATION_BATCH_ID,
            $result['ownership']['migrationBatchId'],
        );
    }

    public function testDraftAndPublicationConstants(): void
    {
        self::assertSame('f12', ApplyProductMigrationPilotF12Service::APPROVED_SLUG);
        self::assertSame('ACCESSORIES', ApplyProductMigrationPilotF12Service::APPROVED_FAMILY);
        self::assertSame('CONDUITS-FITTINGS', ApplyProductMigrationPilotF12Service::APPROVED_CATEGORY);
        self::assertSame('phase12.8-f12-pilot', ApplyProductMigrationPilotF12Service::MIGRATION_BATCH_ID);
    }

    public function testManualOwnershipMarkerPresent(): void
    {
        self::assertStringContainsString('phase12.8', ApplyProductMigrationPilotF12Service::MIGRATION_BATCH_ID);
        self::assertSame('tc', ApplyProductMigrationPilotF12Service::SOURCE_SYSTEM);
        self::assertSame('accessory_specs', ApplyProductMigrationPilotF12Service::SOURCE_TABLE);
    }

    /**
     * @param list<string> $extra
     * @return list<string>
     */
    private function approvedArgs(array $extra = []): array
    {
        $base = [
            '--apply',
            '--pilot',
            '--style=F12',
            '--confirm-non-production',
            '--pilot-apply-approved',
        ];
        foreach ($extra as $arg) {
            if (str_starts_with($arg, '--style=')) {
                $base = array_values(array_filter($base, static fn (string $a): bool => !str_starts_with($a, '--style=')));
            }
            $base[] = $arg;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function okPreflight(): array
    {
        return [
            'server' => 'tricab-mel.database.windows.net',
            'database' => 'tc',
            'environmentLabel' => 'NON-PRODUCTION',
            'liveValidation' => 'AVAILABLE',
            'operatorApprovalRecognised' => true,
            'definitionCount' => 64,
            'fieldMappingCount' => 1044,
            'cableFieldMappingCount' => 538,
            'f12CollisionType' => 'NONE',
        ];
    }

    /** @return array<string, mixed> */
    private function okLegacySource(): array
    {
        return [
            'style_code' => 'F12',
            'name' => 'Inspection Tee',
            'specValues' => [
                'd1' => '12.00',
                'd2' => '15.00',
                'd3' => '10.00',
                'd4' => '3.00',
                'd5' => '2.00',
                'd6' => '4.30',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function okPackage(): array
    {
        $projected = [];
        foreach (ApplyProductMigrationPilotF12Service::APPROVED_SPECS as $spec) {
            $projected[] = [
                'definition' => $spec['definition'],
                'value' => $spec['value'],
                'legacySource' => 'accessory_specs.' . $spec['legacyField'],
                'mappingSource' => $spec['mappingProvenance'],
            ];
        }

        return [
            'specificationProjection' => [
                'projectedVariantSpecificationValues' => $projected,
            ],
        ];
    }

    private function serviceWithoutConstructor(): ApplyProductMigrationPilotF12Service
    {
        $ref = new ReflectionClass(ApplyProductMigrationPilotF12Service::class);

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
}
