<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PHPUnit\Framework\TestCase;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Engine\NativeCableCalculatorEngine;

/**
 * Documents intentional legacy parity behaviours — do not "fix" without engineering approval.
 */
final class NativeLegacyCharacterizationTest extends TestCase
{
    public function test_110_degree_band_uses_maxvd_times_1_1(): void
    {
        $dir = realpath(dirname(__DIR__, 2) . '/resources/cable-calculator/v1');
        self::assertNotFalse($dir);

        $engine = new NativeCableCalculatorEngine($dir);
        $service = new CalculateCableService($engine);

        $base = LegacyParitySupport::requestFromLegacyInput([
            'load' => 200,
            'length' => 10,
            'pf' => 0.85,
            'maxvd' => 5,
            'voltage' => 400,
            'phase' => 3,
            'ambient' => 40,
            'max_op_temp' => 110,
            'cabletype' => 1,
            'installation' => 1,
            'minruns' => 2,
            'activeConductor' => 'Cu',
            'earthConductor' => 'Cu',
            'Erh' => 'auto',
            'mftime' => 1,
            'acablesize' => 'N/A',
        ]);

        $strict = clone $base;
        $strictRequest = new \TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest(
            reference: $base->reference,
            loadAmps: $base->loadAmps,
            runLengthM: $base->runLengthM,
            powerFactor: $base->powerFactor,
            maximumVoltageDropPercent: 5.0,
            voltage: $base->voltage,
            phaseClass: $base->phaseClass,
            ambientTemperatureC: $base->ambientTemperatureC,
            maxOperatingTemperatureC: $base->maxOperatingTemperatureC,
            activeConductorMaterial: $base->activeConductorMaterial,
            earthConductorMaterial: $base->earthConductorMaterial,
            cableType: $base->cableType,
            installationMethod: $base->installationMethod,
            cableRunsMode: $base->cableRunsMode,
            fixedCableSize: $base->fixedCableSize,
            earthResistanceMode: $base->earthResistanceMode,
            maximumFaultTimeSeconds: $base->maximumFaultTimeSeconds,
            compare: $base->compare,
            compareWith: $base->compareWith,
        );

        $relaxed = new \TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest(
            reference: $base->reference,
            loadAmps: $base->loadAmps,
            runLengthM: $base->runLengthM,
            powerFactor: $base->powerFactor,
            maximumVoltageDropPercent: 5.5,
            voltage: $base->voltage,
            phaseClass: $base->phaseClass,
            ambientTemperatureC: $base->ambientTemperatureC,
            maxOperatingTemperatureC: $base->maxOperatingTemperatureC,
            activeConductorMaterial: $base->activeConductorMaterial,
            earthConductorMaterial: $base->earthConductorMaterial,
            cableType: $base->cableType,
            installationMethod: $base->installationMethod,
            cableRunsMode: $base->cableRunsMode,
            fixedCableSize: $base->fixedCableSize,
            earthResistanceMode: $base->earthResistanceMode,
            maximumFaultTimeSeconds: $base->maximumFaultTimeSeconds,
            compare: $base->compare,
            compareWith: $base->compareWith,
        );

        $strictResult = $service->calculate($strictRequest, false);
        $relaxedResult = $service->calculate($relaxed, false);

        $strict110 = null;
        $relaxed110 = null;
        foreach ($strictResult->bands as $band) {
            if ($band->temperatureBand === '110') {
                $strict110 = $band;
            }
        }
        foreach ($relaxedResult->bands as $band) {
            if ($band->temperatureBand === '110') {
                $relaxed110 = $band;
            }
        }

        self::assertNotNull($strict110);
        self::assertNotNull($relaxed110);
        self::assertSame($strict110->activeSizeMm2, $relaxed110->activeSizeMm2);
        self::assertSame($strict110->voltageDropPercent, $relaxed110->voltageDropPercent);
    }
}
