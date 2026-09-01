<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Http;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\TestAppFactory;

final class CableCalculatorCalculateEndpointTest extends TestCase
{
    public function test_calculate_rejects_zero_earth_resistance(): void
    {
        $body = json_encode([
            'loadAmps' => 10,
            'runLengthM' => 10,
            'powerFactor' => 0.85,
            'voltDropPercent' => 5,
            'voltage' => 400,
            'phaseClass' => 3,
            'ambientC' => 40,
            'maxOperatingTempC' => 90,
            'activeConductor' => 'copper',
            'earthConductor' => 'copper',
            'selectedCable' => 1,
            'installation' => 1,
            'cableRuns' => '2',
            'earthResistanceMode' => '0',
            'mftime' => 1,
        ], JSON_THROW_ON_ERROR);

        $app = TestAppFactory::create();
        $request = TestAppFactory::request('POST', '/api/v1/cable-calculator/calculate')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('CABLE_CALCULATION_VALIDATION', $payload['error']['code'] ?? null);
    }

    public function test_calculate_returns_structured_result_when_engine_available(): void
    {
        if (!\TriCab\Api\Tests\CableCalculator\LegacyParitySupport::legacyEngineAvailable()) {
            $this->markTestSkipped('Legacy TriCalc engine unavailable.');
        }

        $body = json_encode([
            'loadAmps' => 1,
            'runLengthM' => 50,
            'powerFactor' => 0.85,
            'voltDropPercent' => 5,
            'voltage' => 400,
            'phaseClass' => 3,
            'ambientC' => 40,
            'maxOperatingTempC' => 90,
            'activeConductor' => 'copper',
            'earthConductor' => 'copper',
            'selectedCable' => 1,
            'installation' => 1,
            'cableRuns' => '1',
            'cableSize' => 'N/A',
            'earthResistanceMode' => 'auto',
            'mftime' => 1,
        ], JSON_THROW_ON_ERROR);

        $app = TestAppFactory::create();
        $request = TestAppFactory::request('POST', '/api/v1/cable-calculator/calculate')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        $data = $payload['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('bands', $data);
        self::assertNotEmpty($data['bands']);
        self::assertSame('CALCULATION COMPLETE', $data['statusLabel']);
        self::assertSame('COMPLETE', $data['calculationStatus'] ?? null);
        self::assertArrayHasKey('productResolution', $data);
        self::assertArrayHasKey('display', $data);
        self::assertArrayHasKey('engineVersion', $data);
        self::assertArrayHasKey('datasetVersion', $data);

        $band90 = null;
        foreach ($data['bands'] as $band) {
            if (($band['temperatureBand'] ?? '') === '90') {
                $band90 = $band;
                break;
            }
        }

        self::assertNotNull($band90);
        self::assertSame(6.0, (float) $band90['activeSizeMm2']);
        self::assertSame(49.0, (float) $band90['currentRatingA']);
        self::assertSame(0.07, (float) $band90['voltageDropPercent']);
        self::assertSame('XL-PAXN/1C6OR', $band90['productCodeActive']);
    }

    public function test_calculate_honors_max_operating_temp_c_from_ui_field(): void
    {
        if (!\TriCab\Api\Tests\CableCalculator\LegacyParitySupport::legacyEngineAvailable()) {
            $this->markTestSkipped('Legacy TriCalc engine unavailable.');
        }

        $body = json_encode([
            'loadAmps' => 200,
            'runLengthM' => 10,
            'powerFactor' => 0.85,
            'voltDropPercent' => 5,
            'voltage' => 400,
            'phaseClass' => 3,
            'ambientC' => 40,
            'maxOperatingTempC' => 110,
            'activeConductor' => 'copper',
            'earthConductor' => 'copper',
            'selectedCable' => 1,
            'installation' => 1,
            'cableRuns' => '2',
            'cableSize' => 'N/A',
            'earthResistanceMode' => 'auto',
            'mftime' => 1,
        ], JSON_THROW_ON_ERROR);

        $app = TestAppFactory::create();
        $request = TestAppFactory::request('POST', '/api/v1/cable-calculator/calculate')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write($body);
        $request->getBody()->rewind();

        $response = $app->handle($request);
        $payload = TestAppFactory::json($response);

        self::assertSame(200, $response->getStatusCode());
        $band110 = null;
        foreach ($payload['data']['bands'] as $band) {
            if (($band['temperatureBand'] ?? '') === '110') {
                $band110 = $band;
                break;
            }
        }
        self::assertNotNull($band110);
        self::assertSame(50.0, (float) $band110['activeSizeMm2']);
        self::assertSame('KL-PAXN/1C50OR', $band110['productCodeActive']);
    }
}
