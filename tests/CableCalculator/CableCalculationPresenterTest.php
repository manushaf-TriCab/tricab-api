<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PHPUnit\Framework\TestCase;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;
use TriCab\Core\CableCalculator\Application\Presentation\CableCalculationPresenter;

final class CableCalculationPresenterTest extends TestCase
{
    public function test_cr_display_is_raw_not_nearest_ten(): void
    {
        $result = $this->resultWithCr(49.0, 61.0, 0.368);
        $display = CableCalculationPresenter::present($result);

        self::assertSame('49A', $display['bands'][0]['currentRatingDisplay']);
        self::assertSame(49.0, $display['bands'][0]['currentRatingA']);
        self::assertSame('61A', $display['bands'][1]['currentRatingDisplay']);
        self::assertSame(61.0, $display['bands'][1]['currentRatingA']);
        self::assertSame(0.4, $display['bands'][0]['weightDisplayKg']);
        self::assertSame(0.368, $display['bands'][0]['weightKg']);
        self::assertSame(0.52, $display['bands'][0]['voltageDropVolts']);
    }

    public function test_space_strips_trailing_point_zero(): void
    {
        $request = $this->request();
        $band = new CableCalculationBand(
            temperatureBand: '90',
            style: 'XL',
            activeSizeMm2: 6.0,
            earthSizeMm2: 2.5,
            earthScreenSizeMm2: 2.5,
            runs: 4,
            currentRatingA: 49.0,
            operatingTemperatureC: 40,
            voltageDropPercent: 0.13,
            faultLoop: 1432.1,
            faultRatingActive: 0.86,
            faultRatingEarth: 0.34,
            spaceRequirement: '15.0',
            weightKg: 0.368,
            quantityM: 400.0,
            multiple: 4,
            productCodeActive: 'XL-PAXN/1C6OR',
            productCodeEarth: 'LP-XXXD/1C2.5EA',
            nc: 1,
            ne: 0,
            earthNe: 1,
        );
        $result = new CableCalculationResult(
            request: $request,
            bands: [$band],
            warnings: [],
            isPreview: false,
            calculatedAtUtc: '2026-09-01T00:00:00Z',
            parityBaseline: 'LOCAL_LEGACY_PARITY_BASELINE',
        );

        $display = CableCalculationPresenter::presentBand($band, $request);
        self::assertSame('15', $display['spaceRequirementDisplay']);
        self::assertSame(40, $display['operatingTemperatureDisplayC']);
    }

    private function resultWithCr(float $cr90, float $cr110, float $weight): CableCalculationResult
    {
        $request = $this->request();
        $mk = static function (string $band, string $style, float $cr) use ($weight): CableCalculationBand {
            return new CableCalculationBand(
                temperatureBand: $band,
                style: $style,
                activeSizeMm2: 6.0,
                earthSizeMm2: 2.5,
                earthScreenSizeMm2: 2.5,
                runs: 4,
                currentRatingA: $cr,
                operatingTemperatureC: 40,
                voltageDropPercent: 0.13,
                faultLoop: 1432.1,
                faultRatingActive: 0.86,
                faultRatingEarth: 0.34,
                spaceRequirement: '15',
                weightKg: $weight,
                quantityM: 400.0,
                multiple: 4,
                productCodeActive: $style . '-PAXN/1C6OR',
                productCodeEarth: 'LP-XXXD/1C2.5EA',
                nc: 1,
                ne: 0,
                earthNe: 1,
            );
        };

        return new CableCalculationResult(
            request: $request,
            bands: [$mk('90', 'XL', $cr90), $mk('110', 'KL', $cr110)],
            warnings: [],
            isPreview: false,
            calculatedAtUtc: '2026-09-01T00:00:00Z',
            parityBaseline: 'LOCAL_LEGACY_PARITY_BASELINE',
        );
    }

    private function request(): CableCalculationRequest
    {
        return new CableCalculationRequest(
            reference: '',
            loadAmps: 1.0,
            runLengthM: 100.0,
            powerFactor: 0.85,
            maximumVoltageDropPercent: 5.0,
            voltage: 400,
            phaseClass: 3,
            ambientTemperatureC: 40,
            maxOperatingTemperatureC: 110,
            activeConductorMaterial: 'copper',
            earthConductorMaterial: 'copper',
            cableType: 1,
            installationMethod: 1,
            cableRunsMode: 2,
            fixedCableSize: null,
            earthResistanceMode: 'auto',
            maximumFaultTimeSeconds: 1.0,
            compare: '0',
            compareWith: '0',
        );
    }
}
