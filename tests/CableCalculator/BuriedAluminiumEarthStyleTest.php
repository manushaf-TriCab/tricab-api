<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableCalculator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Engine\CableProductCodeGenerator;
use TriCab\Core\CableCalculator\Engine\CableStyleResolver;
use TriCab\Core\CableCalculator\Engine\NativeCableCalculatorEngine;

/**
 * TSD rule: an aluminium earth on a buried SDI run is KL-PAXA instead of LP-XXXA.
 * Same rule as legacy tc-web live 03d41dec / web_production a899f8c4.
 */
final class BuriedAluminiumEarthStyleTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function buriedInstallations(): iterable
    {
        yield 'buried in spaced conduit' => [3];
        yield 'buried spaced' => [4];
        yield 'buried in touching conduit' => [7];
        yield 'buried touching' => [9];
    }

    #[DataProvider('buriedInstallations')]
    public function test_aluminium_earth_on_buried_sdi_run_is_kl(int $installation): void
    {
        self::assertSame('KL', CableStyleResolver::buriedAluminiumEarthStyle('LP', 1, true, $installation));

        $result = $this->calculate(['installation' => $installation, 'earthConductor' => 'Al']);

        self::assertNotEmpty($result->bands);
        foreach ($result->bands as $band) {
            self::assertMatchesRegularExpression('#^KL-PAXA/1C[0-9.]+EA$#', (string) $band->productCodeEarth, $band->temperatureBand . ' band');
        }
    }

    public function test_aluminium_earth_on_unburied_run_stays_lp(): void
    {
        foreach ([1, 2, 5, 6, 8] as $installation) {
            self::assertSame('LP', CableStyleResolver::buriedAluminiumEarthStyle('LP', 1, true, $installation));
        }

        $result = $this->calculate(['installation' => 1, 'earthConductor' => 'Al']);

        foreach ($result->bands as $band) {
            self::assertStringStartsWith('LP-XXXA/1C', (string) $band->productCodeEarth);
        }
    }

    public function test_copper_earth_on_buried_run_stays_lp(): void
    {
        self::assertSame('LP', CableStyleResolver::buriedAluminiumEarthStyle('LP', 1, false, 4));

        $result = $this->calculate(['installation' => 4, 'earthConductor' => 'Cu']);

        foreach ($result->bands as $band) {
            self::assertStringStartsWith('LP-XXX', (string) $band->productCodeEarth);
        }
    }

    public function test_rule_is_sdi_only(): void
    {
        self::assertSame('LP', CableStyleResolver::buriedAluminiumEarthStyle('LP', 0, true, 4), 'switchboard');
        self::assertSame('LP', CableStyleResolver::buriedAluminiumEarthStyle('LP', 4, true, 4), 'multicore');
        self::assertSame('LS', CableStyleResolver::buriedAluminiumEarthStyle('LS', 2, true, 4), 'LSZH earth is not LP');
        self::assertSame('UP', CableStyleResolver::buriedAluminiumEarthStyle('UP', 10, true, 4), 'shipboard earth is not LP');
    }

    public function test_kl_earth_code_stays_single_core_with_several_earths(): void
    {
        self::assertSame('KL-PAXA/1C25EA', CableProductCodeGenerator::generate('KL', 25, false, 2, 0, 0, 1, 'EA', 0));
        self::assertSame('LP-XXXA/1C25EA', CableProductCodeGenerator::generate('LP', 25, false, 2, 0, 0, 1, 'EA', 0));
        // KL as a multicore active is unaffected.
        self::assertSame('KL-PAXN/4C25OR', CableProductCodeGenerator::generate('KL', 25, false, 4, 0, 0, 0, 'OR', 0));
    }

    /** @param array<string, mixed> $overrides */
    private function calculate(array $overrides): CableCalculationResult
    {
        $dir = realpath(dirname(__DIR__, 2) . '/resources/cable-calculator/v1');
        self::assertNotFalse($dir);

        $request = LegacyParitySupport::requestFromLegacyInput(array_merge([
            'load' => 200,
            'length' => 50,
            'pf' => 0.85,
            'maxvd' => 5,
            'voltage' => 400,
            'phase' => 3,
            'ambient' => 25,
            'max_op_temp' => 110,
            'cabletype' => 1,
            'installation' => 4,
            'minruns' => 2,
            'activeConductor' => 'Cu',
            'earthConductor' => 'Al',
            'Erh' => 'auto',
            'mftime' => 1,
            'acablesize' => 'N/A',
        ], $overrides));

        return (new CalculateCableService(new NativeCableCalculatorEngine($dir)))->calculate($request);
    }
}
