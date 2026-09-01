<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CableCalculator\LegacyCliTriCalcEngine;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;

final class LegacyBoundaryParityTest extends TestCase
{
    public function test_load_xa_transition_boundaries_match_legacy(): void
    {
        if (!LegacyParitySupport::legacyEngineAvailable()) {
            $this->markTestSkipped('Legacy TriCalc engine unavailable.');
        }

        $fixturePath = LegacyParitySupport::fixtureRoot() . '/boundaries/load-xa-transitions-sdi-xl.json';
        if (!is_readable($fixturePath)) {
            $this->markTestSkipped('Boundary fixture missing.');
        }

        $fixture = json_decode((string) file_get_contents($fixturePath), true);
        self::assertIsArray($fixture);

        $baseInput = $fixture['input'] ?? null;
        $transitions = $fixture['transitions'] ?? [];
        self::assertIsArray($baseInput);
        self::assertIsArray($transitions);

        $engine = new LegacyCliTriCalcEngine(
            LegacyParitySupport::calculateJsonScript(),
            PHP_BINARY,
        );
        $service = new CalculateCableService($engine);

        foreach ($transitions as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            $input = $baseInput;
            $input['load'] = $transition['load'] ?? $baseInput['load'];
            $label = 'load=' . ($transition['load'] ?? '?');

            $result = $service->calculate(LegacyParitySupport::requestFromLegacyInput($input), false);
            $band = null;
            foreach ($result->bands as $candidate) {
                if ($candidate->temperatureBand === '90') {
                    $band = $candidate;
                    break;
                }
            }

            self::assertNotNull($band, $label . ': missing 90°C band');
            self::assertSame((float) ($transition['xa'] ?? 0), $band->activeSizeMm2, $label . ': xa');
            if (isset($transition['runs'])) {
                self::assertSame((int) $transition['runs'], $band->runs, $label . ': runs');
            }
        }
    }

    public function test_parallel_runs_transition_boundary_matches_legacy(): void
    {
        if (!LegacyParitySupport::legacyEngineAvailable()) {
            $this->markTestSkipped('Legacy TriCalc engine unavailable.');
        }

        $fixturePath = LegacyParitySupport::fixtureRoot() . '/boundaries/runs-transition-long-run.json';
        $baseFixturePath = LegacyParitySupport::fixtureRoot() . '/boundaries/load-xa-transitions-sdi-xl.json';
        if (!is_readable($fixturePath) || !is_readable($baseFixturePath)) {
            $this->markTestSkipped('Runs transition boundary fixture missing.');
        }

        $fixture = json_decode((string) file_get_contents($fixturePath), true);
        $baseFixture = json_decode((string) file_get_contents($baseFixturePath), true);
        self::assertIsArray($fixture);
        self::assertIsArray($baseFixture);

        $baseInput = $baseFixture['input'] ?? null;
        $transitions = $fixture['transitions'] ?? [];
        self::assertIsArray($baseInput);
        self::assertIsArray($transitions);

        $engine = new LegacyCliTriCalcEngine(
            LegacyParitySupport::calculateJsonScript(),
            PHP_BINARY,
        );
        $service = new CalculateCableService($engine);

        foreach ($transitions as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            $input = $baseInput;
            $input['load'] = $transition['load'] ?? $baseInput['load'];
            $input['length'] = 200;
            $label = 'load=' . ($transition['load'] ?? '?');

            $result = $service->calculate(LegacyParitySupport::requestFromLegacyInput($input), false);
            $band = null;
            foreach ($result->bands as $candidate) {
                if ($candidate->temperatureBand === '90') {
                    $band = $candidate;
                    break;
                }
            }

            self::assertNotNull($band, $label . ': missing 90°C band');
            if (isset($transition['xa'])) {
                self::assertSame((float) $transition['xa'], $band->activeSizeMm2, $label . ': xa');
            }
            if (isset($transition['runs'])) {
                self::assertSame((int) $transition['runs'], $band->runs, $label . ': runs');
            }
        }
    }
}
