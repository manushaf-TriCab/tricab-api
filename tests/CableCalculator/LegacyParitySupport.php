<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationBand;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationRequest;

final class LegacyParitySupport
{
    public static function fixtureRoot(): string
    {
        $configured = getenv('TRICAB_TC_WEB_FIXTURES');
        if ($configured !== false && $configured !== '') {
            return rtrim($configured, '/');
        }

        $default = dirname(__DIR__, 4) . '/tc-web/tests/fixtures/cable-calculator';
        return realpath($default) ?: $default;
    }

    public static function calculateJsonScript(): string
    {
        $configured = getenv('TRICAB_TC_WEB_CALCULATE_JSON');
        if ($configured !== false && $configured !== '') {
            return $configured;
        }

        $default = dirname(__DIR__, 4) . '/tc-web/tools/legacy-cable-calculator/calculate-json.php';
        return realpath($default) ?: $default;
    }

    public static function legacyEngineAvailable(): bool
    {
        $script = self::calculateJsonScript();
        if (!is_readable($script)) {
            return false;
        }

        $input = json_encode([
            'load' => 1,
            'length' => 10,
            'pf' => 0.85,
            'maxvd' => 5,
            'voltage' => 400,
            'phase' => 3,
            'ambient' => 40,
            'max_op_temp' => 90,
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
        ], JSON_THROW_ON_ERROR);

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, $script],
            $descriptor,
            $pipes,
            dirname($script),
        );

        if (!is_resource($process)) {
            return false;
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $decoded = self::decodeLegacyJsonPayload($stdout ?: '');

        return is_array($decoded) && !empty($decoded['ok']);
    }

    /** @return array<string, mixed>|null */
    private static function decodeLegacyJsonPayload(string $stdout): ?array
    {
        $trimmed = trim($stdout);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($stdout, '{"ok"');
        if ($start === false) {
            $start = strpos($stdout, '{');
        }
        if ($start === false) {
            return null;
        }

        $decoded = json_decode(substr($stdout, $start), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $legacyInput */
    public static function requestFromLegacyInput(array $legacyInput): CableCalculationRequest
    {
        $fixed = $legacyInput['acablesize'] ?? 'N/A';
        if ($fixed === 'N/A' || $fixed === '' || $fixed === null) {
            $fixed = null;
        } else {
            $fixed = (string) $fixed;
        }

        $active = strtolower((string) ($legacyInput['activeConductor'] ?? 'Cu'));
        $earth = strtolower((string) ($legacyInput['earthConductor'] ?? 'Cu'));

        return new CableCalculationRequest(
            reference: (string) ($legacyInput['reference'] ?? ''),
            loadAmps: (float) ($legacyInput['load'] ?? 0),
            runLengthM: (float) ($legacyInput['length'] ?? 0),
            powerFactor: (float) ($legacyInput['pf'] ?? 0.85),
            maximumVoltageDropPercent: (float) ($legacyInput['maxvd'] ?? 5),
            voltage: (int) ($legacyInput['voltage'] ?? 400),
            phaseClass: (int) ($legacyInput['phase'] ?? 3),
            ambientTemperatureC: (int) ($legacyInput['ambient'] ?? 40),
            maxOperatingTemperatureC: (int) ($legacyInput['max_op_temp'] ?? 90),
            activeConductorMaterial: $active === 'al' ? 'aluminium' : 'copper',
            earthConductorMaterial: $earth === 'al' ? 'aluminium' : 'copper',
            cableType: (int) ($legacyInput['cabletype'] ?? $legacyInput['type'] ?? 1),
            installationMethod: (int) ($legacyInput['installation'] ?? 1),
            cableRunsMode: (int) ($legacyInput['minruns'] ?? 2),
            fixedCableSize: $fixed,
            earthResistanceMode: (string) ($legacyInput['Erh'] ?? 'auto'),
            maximumFaultTimeSeconds: (float) ($legacyInput['mftime'] ?? 1),
            compare: (string) ($legacyInput['compare'] ?? '0'),
            compareWith: (string) ($legacyInput['compare_with'] ?? '0'),
        );
    }

    /**
     * @return list<string>
     * @param array<string, mixed> $legacyBand
     */
    public static function parityMismatches(string $bandLabel, array $legacyBand, CableCalculationBand $actual): array
    {
        $mismatches = [];

        self::assertField($mismatches, $bandLabel, 'style', (string) ($legacyBand['style'] ?? ''), $actual->style);
        self::assertField($mismatches, $bandLabel, 'xa', (float) ($legacyBand['xa'] ?? 0), $actual->activeSizeMm2);
        self::assertField($mismatches, $bandLabel, 'earth_size', self::nullableFloat($legacyBand['earth_size']), $actual->earthSizeMm2);
        self::assertField($mismatches, $bandLabel, 'xae', self::nullableFloat($legacyBand['xae']), $actual->earthScreenSizeMm2);
        self::assertField($mismatches, $bandLabel, 'runs', (int) ($legacyBand['runs'] ?? 0), $actual->runs);
        self::assertField($mismatches, $bandLabel, 'cr', (float) ($legacyBand['cr'] ?? 0), $actual->currentRatingA);
        self::assertField($mismatches, $bandLabel, 'ot', (int) ($legacyBand['ot'] ?? 0), $actual->operatingTemperatureC);
        self::assertField($mismatches, $bandLabel, 'vd', (float) ($legacyBand['vd'] ?? 0), $actual->voltageDropPercent);
        self::assertField($mismatches, $bandLabel, 'fault-loop', self::nullableFloat($legacyBand['fault-loop']), $actual->faultLoop);
        self::assertField($mismatches, $bandLabel, 'fr-active', self::nullableFloat($legacyBand['fr-active']), $actual->faultRatingActive);
        self::assertField($mismatches, $bandLabel, 'fr-earth', self::nullableFloat($legacyBand['fr-earth']), $actual->faultRatingEarth);
        self::assertField($mismatches, $bandLabel, 'space', self::nullableString($legacyBand['space']), $actual->spaceRequirement);
        self::assertField($mismatches, $bandLabel, 'weight', self::nullableFloat($legacyBand['weight']), $actual->weightKg);
        self::assertField($mismatches, $bandLabel, 'qty', self::nullableFloat($legacyBand['qty']), $actual->quantityM);
        self::assertField($mismatches, $bandLabel, 'multiple', self::nullableInt($legacyBand['multiple']), $actual->multiple);
        self::assertField($mismatches, $bandLabel, 'productCodeActive', (string) ($legacyBand['productCodeActive'] ?? ''), $actual->productCodeActive);
        self::assertField($mismatches, $bandLabel, 'productCodeEarth', self::nullableString($legacyBand['productCodeEarth']), $actual->productCodeEarth);
        self::assertField($mismatches, $bandLabel, 'nc', self::nullableInt($legacyBand['nc']), $actual->nc);
        self::assertField($mismatches, $bandLabel, 'ne', self::nullableInt($legacyBand['ne']), $actual->ne);
        self::assertField($mismatches, $bandLabel, 'earth_ne', self::nullableInt($legacyBand['earth_ne']), $actual->earthNe);

        return $mismatches;
    }

    /** @param list<string> $mismatches */
    private static function assertField(
        array &$mismatches,
        string $bandLabel,
        string $field,
        mixed $expected,
        mixed $actual,
    ): void {
        if ($expected === null && $actual === null) {
            return;
        }

        if (is_float($expected) || is_int($expected)) {
            if (abs((float) $expected - (float) $actual) > 0.0001) {
                $mismatches[] = "{$bandLabel}.{$field}: expected {$expected}, got {$actual}";
            }

            return;
        }

        if ($expected !== $actual) {
            $mismatches[] = "{$bandLabel}.{$field}: expected {$expected}, got {$actual}";
        }
    }

  /** @param mixed $value */
    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /** @param mixed $value */
    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** @param mixed $value */
    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
