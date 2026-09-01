<?php

declare(strict_types=1);

/**
 * Dual-engine cable calculator parity runner (native vs legacy-cli oracle).
 *
 * Usage:
 *   php bin/cable-calculator-parity.php
 *   php bin/cable-calculator-parity.php --fixture=matrix-001-vary-phase-3
 */

use TriCab\Api\CableCalculator\LegacyCliTriCalcEngine;
use TriCab\Api\Tests\CableCalculator\LegacyParitySupport;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Engine\NativeCableCalculatorEngine;

require __DIR__ . '/../tests/bootstrap.php';

$fixtureFilter = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--fixture=')) {
        $fixtureFilter = substr($arg, 10);
    }
}

$projectsRoot = dirname(__DIR__, 3);
$matrixDir = LegacyParitySupport::fixtureRoot() . '/matrix';
$datasetDir = __DIR__ . '/../resources/cable-calculator/v1';

if (!LegacyParitySupport::legacyEngineAvailable()) {
    fwrite(STDERR, "Legacy oracle unavailable.\n");
    exit(2);
}

$legacyService = new CalculateCableService(new LegacyCliTriCalcEngine(
    LegacyParitySupport::calculateJsonScript(),
    PHP_BINARY,
));
$nativeService = new CalculateCableService(new NativeCableCalculatorEngine($datasetDir));

$files = glob($matrixDir . '/matrix-*.json') ?: [];
sort($files);

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
    if ($fixtureFilter !== null && $id !== $fixtureFilter && !str_contains($id, $fixtureFilter)) {
        continue;
    }

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
    } catch (Throwable $e) {
        $failed[$id] = ['error' => $e->getMessage()];
        continue;
    }

    $mismatches = [];
    foreach (['90', '110'] as $bandLabel) {
        $legacyBand = bandByTemperature($legacyResult->bands, $bandLabel);
        $nativeBand = bandByTemperature($nativeResult->bands, $bandLabel);
        if ($legacyBand === null && $nativeBand === null) {
            continue;
        }
        if ($legacyBand === null || $nativeBand === null) {
            $mismatches[] = 'band presence mismatch for ' . $bandLabel;
            continue;
        }

        $legacyPayload = bandToPayload($legacyBand);
        $nativePayload = bandToPayload($nativeBand);
        $mismatches = array_merge(
            $mismatches,
            LegacyParitySupport::parityMismatches($bandLabel, $legacyPayload, $nativeBand),
        );
    }

    if ($mismatches !== []) {
        $failed[$id] = $mismatches;
    } else {
        $passed++;
        echo "PASS {$id}\n";
    }
}

echo "\nSummary: {$passed} passed, " . count($failed) . " failed, {$blocked} blocked\n";

if ($failed !== []) {
    foreach ($failed as $id => $items) {
        echo "FAIL {$id}: " . implode('; ', array_slice($items, 0, 5)) . "\n";
    }
    exit(1);
}

exit(0);

/** @param list<\TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand> $bands */
function bandByTemperature(array $bands, string $label): ?\TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand
{
    foreach ($bands as $band) {
        if ($band->temperatureBand === $label) {
            return $band;
        }
    }

    return null;
}

/** @return array<string, mixed> */
function bandToPayload(\TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand $band): array
{
    return [
        'style' => $band->style,
        'xa' => $band->activeSizeMm2,
        'earth_size' => $band->earthSizeMm2,
        'xae' => $band->earthScreenSizeMm2,
        'runs' => $band->runs,
        'cr' => $band->currentRatingA,
        'ot' => $band->operatingTemperatureC,
        'vd' => $band->voltageDropPercent,
        'fault-loop' => $band->faultLoop,
        'fr-active' => $band->faultRatingActive,
        'fr-earth' => $band->faultRatingEarth,
        'space' => $band->spaceRequirement,
        'weight' => $band->weightKg,
        'qty' => $band->quantityM,
        'multiple' => $band->multiple,
        'productCodeActive' => $band->productCodeActive,
        'productCodeEarth' => $band->productCodeEarth,
        'nc' => $band->nc,
        'ne' => $band->ne,
        'earth_ne' => $band->earthNe,
    ];
}
