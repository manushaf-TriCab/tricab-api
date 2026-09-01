<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CableCalculator\LegacyCliTriCalcEngine;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;

final class LegacyParityMatrixTest extends TestCase
{
    private static bool $engineChecked = false;
    private static bool $engineAvailable = false;

    public function test_matrix_fixtures_match_legacy_output(): void
    {
        if (!$this->legacyEngineIsAvailable()) {
            $this->markTestSkipped('Legacy TriCalc engine unavailable (tc-web MySQL or calculate-json.php not reachable).');
        }

        $matrixDir = LegacyParitySupport::fixtureRoot() . '/matrix';
        if (!is_dir($matrixDir)) {
            $this->markTestSkipped('Matrix fixtures not found at ' . $matrixDir);
        }

        $files = glob($matrixDir . '/matrix-*.json') ?: [];
        self::assertNotEmpty($files, 'Expected matrix regression fixtures.');

        $engine = new LegacyCliTriCalcEngine(
            LegacyParitySupport::calculateJsonScript(),
            PHP_BINARY,
        );
        $service = new CalculateCableService($engine);

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
            $legacyOutput = $fixture['legacyOutput'] ?? null;
            if (!is_array($legacyInput) || !is_array($legacyOutput)) {
                $blocked++;
                continue;
            }

            $request = LegacyParitySupport::requestFromLegacyInput($legacyInput);
            $result = $service->calculate($request, false);

            $mismatches = [];
            if (!empty($legacyOutput['result90'])) {
                $band = $this->bandByTemperature($result->bands, '90');
                if ($band === null) {
                    $mismatches[] = 'missing result90 band';
                } else {
                    $mismatches = array_merge(
                        $mismatches,
                        LegacyParitySupport::parityMismatches('90', $legacyOutput['result90'], $band),
                    );
                }
            }

            if (!empty($legacyOutput['result110'])) {
                $band = $this->bandByTemperature($result->bands, '110');
                if ($band === null) {
                    $mismatches[] = 'missing result110 band';
                } else {
                    $mismatches = array_merge(
                        $mismatches,
                        LegacyParitySupport::parityMismatches('110', $legacyOutput['result110'], $band),
                    );
                }
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
                    'Legacy parity failures: %d passed, %d failed, %d blocked. First failures: %s',
                    $passed,
                    count($failed),
                    $blocked,
                    $summary,
                ),
            );
        }

        self::assertSame(count($files), $passed, 'All matrix fixtures must pass legacy parity.');
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

    private function legacyEngineIsAvailable(): bool
    {
        if (self::$engineChecked) {
            return self::$engineAvailable;
        }

        self::$engineChecked = true;
        self::$engineAvailable = LegacyParitySupport::legacyEngineAvailable();

        return self::$engineAvailable;
    }
}
