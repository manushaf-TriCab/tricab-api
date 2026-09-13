<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Http\Action\CableCalculator\CableCalculatorDtoSerializer;
use TriCab\Core\CableCalculator\Application\ProductResolution\CalculatedProductReference;
use TriCab\Core\CableCalculator\Application\ProductResolution\NullCableCalculatorProductResolver;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Application\Service\ResolveCableCalculationProductsService;
use TriCab\Core\CableCalculator\Contract\CableCalculatorProductResolver;
use TriCab\Core\CableCalculator\Engine\NativeCableCalculatorEngine;

final class ProductResolutionIndependenceTest extends TestCase
{
    public function test_null_resolver_marks_not_migrated_without_changing_engineering(): void
    {
        $engine = new NativeCableCalculatorEngine(dirname(__DIR__, 2) . '/resources/cable-calculator/v1');
        $service = new CalculateCableService($engine);
        $request = LegacyParitySupport::requestFromLegacyInput([
            'load' => 1,
            'length' => 100,
            'pf' => 0.85,
            'maxvd' => 5,
            'voltage' => 400,
            'phase' => 3,
            'ambient' => 40,
            'max_op_temp' => 110,
            'cabletype' => 1,
            'type' => 1,
            'installation' => 1,
            'minruns' => 2,
            'activeConductor' => 'Cu',
            'earthConductor' => 'Cu',
            'Erh' => 'auto',
            'mftime' => 1,
            'acablesize' => 'N/A',
            'compare' => '0',
            'compare_with' => '0',
        ]);

        putenv('CABLE_CALCULATOR_ENGINE=native');
        $engineering = $service->calculate($request, false);
        $resolution = (new ResolveCableCalculationProductsService(new NullCableCalculatorProductResolver()))
            ->resolve($engineering);
        $payload = CableCalculatorDtoSerializer::result($engineering, $resolution);

        self::assertSame('COMPLETE', $payload['calculationStatus']);
        self::assertSame('native-v1', $payload['engineVersion']);
        self::assertSame('legacy-tc-2026-08', $payload['datasetVersion']);
        self::assertSame('LOCAL_LEGACY_PARITY_BASELINE', $payload['parityBaseline']);
        self::assertNotEmpty($payload['productResolution']);
        foreach ($payload['productResolution'] as $item) {
            self::assertSame('CATALOGUE_UNAVAILABLE', $item['resolutionStatus']);
        }

        $band90 = $payload['bands'][0];
        self::assertSame(6.0, (float) $band90['activeSizeMm2']);
        self::assertSame(2.5, (float) $band90['earthSizeMm2']);
        self::assertSame(49.0, (float) $band90['currentRatingA']);
        self::assertSame(0.13, (float) $band90['voltageDropPercent']);
        self::assertSame(1386.2, (float) $band90['faultLoop']);
        self::assertSame('XL-PAXN/1C6OR', $band90['productCodeActive']);
        self::assertSame('LP-XXXD/1C2.5EA', $band90['productCodeEarth']);
    }

    public function test_resolver_unavailable_keeps_identical_engineering_fields(): void
    {
        $engine = new NativeCableCalculatorEngine(dirname(__DIR__, 2) . '/resources/cable-calculator/v1');
        $service = new CalculateCableService($engine);
        $request = LegacyParitySupport::requestFromLegacyInput([
            'load' => 150,
            'length' => 100,
            'pf' => 0.85,
            'maxvd' => 5,
            'voltage' => 400,
            'phase' => 3,
            'ambient' => 40,
            'max_op_temp' => 110,
            'cabletype' => 1,
            'type' => 1,
            'installation' => 1,
            'minruns' => 2,
            'activeConductor' => 'Cu',
            'earthConductor' => 'Cu',
            'Erh' => 'auto',
            'mftime' => 1,
            'acablesize' => 'N/A',
            'compare' => '0',
            'compare_with' => '0',
        ]);

        $engineering = $service->calculate($request, false);

        $nullPayload = CableCalculatorDtoSerializer::result(
            $engineering,
            (new ResolveCableCalculationProductsService(new NullCableCalculatorProductResolver()))->resolve($engineering),
        );

        $fakeResolved = new class implements CableCalculatorProductResolver {
            public function resolve(
                string $productCode,
                string $role,
                string $temperatureBand,
                ?float $sizeMm2,
            ): CalculatedProductReference {
                return new CalculatedProductReference(
                    productCode: $productCode,
                    role: $role,
                    temperatureBand: $temperatureBand,
                    sizeMm2: $sizeMm2,
                    resolutionStatus: 'RESOLVED',
                    resolvedProductPublicId: '00000000-0000-0000-0000-000000000001',
                    slug: 'fake-product',
                    displayName: 'Fake',
                    customerUrl: '/products/fake-product',
                );
            }
        };

        $resolvedPayload = CableCalculatorDtoSerializer::result(
            $engineering,
            (new ResolveCableCalculationProductsService($fakeResolved))->resolve($engineering),
        );

        self::assertSame($nullPayload['bands'], $resolvedPayload['bands']);
        self::assertNotSame(
            $nullPayload['productResolution'][0]['resolutionStatus'],
            $resolvedPayload['productResolution'][0]['resolutionStatus'],
        );
    }

    public function test_exact_code_missing_ambiguous_and_resolved_statuses(): void
    {
        $resolver = new class implements CableCalculatorProductResolver {
            public function resolve(
                string $productCode,
                string $role,
                string $temperatureBand,
                ?float $sizeMm2,
            ): CalculatedProductReference {
                $status = match ($productCode) {
                    'XL-PAXN/1C6OR' => 'RESOLVED',
                    'AMBIG-CODE' => 'AMBIGUOUS',
                    default => 'NOT_MIGRATED',
                };

                return new CalculatedProductReference(
                    productCode: $productCode,
                    role: $role,
                    temperatureBand: $temperatureBand,
                    sizeMm2: $sizeMm2,
                    resolutionStatus: $status,
                    resolvedProductPublicId: $status === 'RESOLVED' ? 'pid' : null,
                );
            }
        };

        self::assertSame('RESOLVED', $resolver->resolve('XL-PAXN/1C6OR', 'ACTIVE', '90', 6.0)->resolutionStatus);
        self::assertSame('AMBIGUOUS', $resolver->resolve('AMBIG-CODE', 'ACTIVE', '90', 6.0)->resolutionStatus);
        self::assertSame('NOT_MIGRATED', $resolver->resolve('MISSING-CODE', 'ACTIVE', '90', 6.0)->resolutionStatus);
    }
}
