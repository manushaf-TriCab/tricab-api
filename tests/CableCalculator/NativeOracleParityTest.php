<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CableCalculator\LegacyCliTriCalcEngine;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Engine\NativeCableCalculatorEngine;

final class NativeOracleParityTest extends TestCase
{
    private static ?NativeCableCalculatorEngine $nativeEngine = null;
    private static bool $legacyChecked = false;
    private static bool $legacyAvailable = false;

    public function test_matrix_fixtures_match_native_vs_legacy_oracle(): void
    {
        if (!$this->legacyEngineIsAvailable()) {
            $this->markTestSkipped('Legacy TriCalc oracle unavailable (tc-web MySQL or calculate-json.php not reachable).');
        }

        $matrixDir = LegacyParitySupport::fixtureRoot() . '/matrix';
        if (!is_dir($matrixDir)) {
            $this->markTestSkipped('Matrix fixtures not found at ' . $matrixDir);
        }

        $files = glob($matrixDir . '/matrix-*.json') ?: [];
        self::assertNotEmpty($files, 'Expected matrix regression fixtures.');

        $legacyEngine = new LegacyCliTriCalcEngine(
            LegacyParitySupport::calculateJsonScript(),
            PHP_BINARY,
        );
        $legacyService = new CalculateCableService($legacyEngine);
        $nativeService = new CalculateCableService($this->nativeEngine());

        $passed = 0;
        $failed = [];
        $blocked = 0;

        foreach ($files as $file) {
            $fixture = json_decode((string) file_get_contents($file), true);
            if (!is_array($fixture)) {
                $blocked++;
                continue;
            }

            $id = (string) ($fixture['meta']['id'] ?? basename($file, '.json'));
            $legacyInput = $fixture['input'] ?? null;
            if (!is_array($legacyInput)) {
                $blocked++;
                continue;
            }

            if (($legacyInput['Erh'] ?? null) === '0' || ($legacyInput['Erh'] ?? null) === 0) {
                $blocked++;
                continue;
            }

            $request = LegacyParitySupport::requestFromLegacyInput($legacyInput);

            try {
                $legacyResult = $legacyService->calculate($request, false);
                $nativeResult = $nativeService->calculate($request, false);
            } catch (\Throwable $e) {
                $failed[$id] = ['calculation error: ' . $e->getMessage()];
                continue;
            }

            $mismatches = [];
            foreach (['90', '110'] as $bandLabel) {
                $legacyBand = $this->bandByTemperature($legacyResult->bands, $bandLabel);
                $nativeBand = $this->bandByTemperature($nativeResult->bands, $bandLabel);

                if ($legacyBand === null && $nativeBand === null) {
                    continue;
                }
                if ($legacyBand === null) {
                    $mismatches[] = "missing legacy {$bandLabel} band";
                    continue;
                }
                if ($nativeBand === null) {
                    $mismatches[] = "missing native {$bandLabel} band";
                    continue;
                }

                $mismatches = array_merge(
                    $mismatches,
                    self::compareBands($bandLabel, $legacyBand, $nativeBand),
                );
            }

            if ($mismatches !== []) {
                $failed[$id] = $mismatches;
            } else {
                $passed++;
            }
        }

        if ($failed !== []) {
            $summary = '';
            foreach ($failed as $id => $items) {
                $summary .= $id . ': ' . implode('; ', array_slice($items, 0, 3)) . "\n";
            }
            self::fail(
                sprintf(
                    'Native vs legacy oracle parity failures: %d passed, %d failed, %d blocked. First failures: %s',
                    $passed,
                    count($failed),
                    $blocked,
                    $summary,
                ),
            );
        }

        self::assertSame(108, $passed, 'All 108 matrix fixtures must pass native vs legacy oracle parity.');
    }

    /** @param list<CableCalculationBand> $bands */
    private function bandByTemperature(array $bands, string $label): ?CableCalculationBand
    {
        foreach ($bands as $band) {
            if ($band->temperatureBand === $label) {
                return $band;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function compareBands(string $bandLabel, CableCalculationBand $legacy, CableCalculationBand $native): array
    {
        $legacyPayload = [
            'style' => $legacy->style,
            'xa' => $legacy->activeSizeMm2,
            'earth_size' => $legacy->earthSizeMm2,
            'xae' => $legacy->earthScreenSizeMm2,
            'runs' => $legacy->runs,
            'cr' => $legacy->currentRatingA,
            'ot' => $legacy->operatingTemperatureC,
            'vd' => $legacy->voltageDropPercent,
            'fault-loop' => $legacy->faultLoop,
            'fr-active' => $legacy->faultRatingActive,
            'fr-earth' => $legacy->faultRatingEarth,
            'space' => $legacy->spaceRequirement,
            'weight' => $legacy->weightKg,
            'qty' => $legacy->quantityM,
            'multiple' => $legacy->multiple,
            'productCodeActive' => $legacy->productCodeActive,
            'productCodeEarth' => $legacy->productCodeEarth,
            'nc' => $legacy->nc,
            'ne' => $legacy->ne,
            'earth_ne' => $legacy->earthNe,
        ];

        $nativePayload = [
            'style' => $native->style,
            'xa' => $native->activeSizeMm2,
            'earth_size' => $native->earthSizeMm2,
            'xae' => $native->earthScreenSizeMm2,
            'runs' => $native->runs,
            'cr' => $native->currentRatingA,
            'ot' => $native->operatingTemperatureC,
            'vd' => $native->voltageDropPercent,
            'fault-loop' => $native->faultLoop,
            'fr-active' => $native->faultRatingActive,
            'fr-earth' => $native->faultRatingEarth,
            'space' => $native->spaceRequirement,
            'weight' => $native->weightKg,
            'qty' => $native->quantityM,
            'multiple' => $native->multiple,
            'productCodeActive' => $native->productCodeActive,
            'productCodeEarth' => $native->productCodeEarth,
            'nc' => $native->nc,
            'ne' => $native->ne,
            'earth_ne' => $native->earthNe,
        ];

        return LegacyParitySupport::parityMismatches($bandLabel, $legacyPayload, $native);
    }

    private function nativeEngine(): NativeCableCalculatorEngine
    {
        if (self::$nativeEngine === null) {
            $dataset = dirname(__DIR__, 2) . '/resources/cable-calculator/v1';
            self::$nativeEngine = new NativeCableCalculatorEngine($dataset);
        }

        return self::$nativeEngine;
    }

    private function legacyEngineIsAvailable(): bool
    {
        if (self::$legacyChecked) {
            return self::$legacyAvailable;
        }

        self::$legacyChecked = true;
        self::$legacyAvailable = LegacyParitySupport::legacyEngineAvailable();

        return self::$legacyAvailable;
    }
}
