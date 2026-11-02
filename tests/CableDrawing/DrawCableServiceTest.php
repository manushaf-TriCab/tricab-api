<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableDrawing;

use PHPUnit\Framework\TestCase;
use TriCab\Api\CableDrawing\CableDrawingException;
use TriCab\Api\CableDrawing\CableDrawingSpecs;
use TriCab\Api\CableDrawing\DrawCableService;

/** Runs against the bundled cable_specs snapshot. */
final class DrawCableServiceTest extends TestCase
{
    private static function service(): DrawCableService
    {
        return new DrawCableService(new CableDrawingSpecs(dirname(__DIR__, 2) . '/resources/cable-drawing/v1/cableSpecs.json'));
    }

    public function testDrawsTheSpecRowTheCodeNames(): void
    {
        $drawing = self::service()->draw('CA-PAXN/5C16BK');

        self::assertSame('CA 4C16+1E6', $drawing->specName);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="1080" height="1080"', $drawing->svg);
        self::assertStringContainsString('<title>CA 4C16+1E6</title>', $drawing->svg);
    }

    public function testDrawsAnAluminiumCodeFromTheCopperRowWhenThereIsNoAluminiumOne(): void
    {
        $drawing = self::service()->draw('B1-PAXA/4C120BK');

        self::assertSame('B1 3C120+3E25', $drawing->specName);
        self::assertStringContainsString('fill="#AAB1B8"', $drawing->svg);
    }

    public function testTakesTheSheathColourFromTheCode(): void
    {
        $red = self::service()->draw('HT-PAXN/3C120RE')->svg;

        self::assertStringContainsString('fill="#C0392F"', $red);
    }

    public function testDrawsSmallerSizesOfAStyleSmaller(): void
    {
        $service = self::service();

        self::assertLessThan(
            self::sheathRadius($service->draw('CA-PAXN/5C70BK')->svg),
            self::sheathRadius($service->draw('CA-PAXN/5C16BK')->svg),
        );
    }

    public function testSaysWhyACodeCannotBeDrawn(): void
    {
        foreach ([
            'not a code' => [CableDrawingException::INVALID_CODE, 400],
            'CA-PAXN/9C16BK' => [CableDrawingException::SPEC_NOT_FOUND, 404],
            'ZZ-PAXN/4C16BK' => [CableDrawingException::SPEC_NOT_FOUND, 404],
            'LS-XXXD/1A1/0OR' => [CableDrawingException::UNAVAILABLE, 422],
            'BB-XXXN/1C10OR' => [CableDrawingException::INVALID_CODE, 400],
            'LS-XXXN/1A18BL' => [CableDrawingException::UNAVAILABLE, 422],
            'CA-PAXN/5C16G1' => [CableDrawingException::UNAVAILABLE, 422],
            'LS-XXXN/1C16G/Y' => [CableDrawingException::UNAVAILABLE, 422],
        ] as $code => [$error, $status]) {
            try {
                self::service()->draw($code);
                self::fail("Drew $code");
            } catch (CableDrawingException $e) {
                self::assertSame($error, $e->errorCode, $code);
                self::assertSame($status, $e->httpStatus(), $code);
            }
        }
    }

    public function testEarthWiresHaveNoBlackSheathOrDistortion(): void
    {
        foreach (['LS', 'LP', 'UP'] as $style) {
            $drawing = self::service()->draw($style . '-XXXN/1C16EA');
            self::assertSame([], $drawing->warnings, $style);
            self::assertStringNotContainsString('fill="#2B2B2C"', $drawing->svg, $style);
            self::assertStringContainsString('fill="#5BAE3C"', $drawing->svg, $style);
        }
    }

    public function testPanelWireColourAppliesToInsulation(): void
    {
        $drawing = self::service()->draw('UP-XXXN/1C16RE');
        self::assertStringContainsString('fill="#C0392F"', $drawing->svg);
        self::assertStringNotContainsString('fill="#2B2B2C"', $drawing->svg);
    }

    public function testDoesNotRequireTheCalculatorBlockForAValidSpec(): void
    {
        $drawing = self::service()->draw('LP-PAXD/1C16EA');
        self::assertStringStartsWith('<svg', $drawing->svg);
    }

    public function testApproximationWarningsAreEmbeddedInTheImage(): void
    {
        $drawing = self::service()->draw('CA-PAXN/5C16BK');
        self::assertNotEmpty($drawing->warnings);
        self::assertStringContainsString('<desc>', $drawing->svg);
        self::assertStringContainsString('estimated', $drawing->svg);
    }

    public function testDrawsAmbiguousRowsWithAnExplicitWarning(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'drawing-specs-');
        self::assertNotFalse($path);
        $columns = ['cable_style', 'nc', 'cc', 'xa', 'ne', 'ct', 'mt', 'cdia', 'od'];
        file_put_contents($path, json_encode(['columns' => $columns, 'rows' => [
            ['TEST', '2', 'p', '1', '0', 'p', '1301', '1.2', '12'],
            ['TEST', '2', 'P', '1', '0', 'p', '1301', '1.2', '12'],
        ]], JSON_THROW_ON_ERROR));
        try {
            $drawing = (new DrawCableService(new CableDrawingSpecs($path)))->draw('TEST-PAXN/2P1BK');
            self::assertStringContainsString('Multiple constructions', implode(' ', $drawing->warnings));
            self::assertStringContainsString('Multiple constructions', $drawing->svg);
        } finally {
            unlink($path);
        }
    }

    public function testDrawsSupportedCatalogueBlocksWithoutCalculatorRoundTrip(): void
    {
        $drawing = self::service()->draw('R1-DEXN/2P2.5BK');
        self::assertStringStartsWith('<svg', $drawing->svg);
    }

    public function testDrawsHeavilyAdjustedRealSpecWithWarnings(): void
    {
        $drawing = self::service()->draw('C2-PAXN/21C1.5BK');
        self::assertStringStartsWith('<svg', $drawing->svg);
        self::assertStringContainsString('Cores drawn at', implode(' ', $drawing->warnings));
    }

    public function testSdiEarthHasWhiteInsulationUnderAGreenYellowSheath(): void
    {
        $svg = self::service()->draw('KL-PAXA/1C95EA')->svg;
        self::assertStringContainsString('fill="#E8C22A"', $svg);
        self::assertStringContainsString('fill="#5BAE3C"', $svg);
        self::assertStringContainsString('fill="#E9E5E1"', $svg);
        self::assertStringNotContainsString('fill="#2B2B2C"', $svg);
    }

    public function testSdiInsulationIsWhiteUnderABlackSheath(): void
    {
        $svg = self::service()->draw('KL-PAXN/1C95BK')->svg;
        self::assertStringContainsString('fill="#E9E5E1"', $svg);
        self::assertSame(1, substr_count($svg, 'fill="#2B2B2C"'));
    }

    private static function sheathRadius(string $svg): float
    {
        // The sheath is the first circle after the background, outside <defs>.
        $body = substr($svg, (int) strpos($svg, '</defs>'));
        if (preg_match('/<circle cx="540" cy="540" r="([\d.]+)"/', $body, $m) !== 1) {
            self::fail('No sheath drawn.');
        }

        return (float) $m[1];
    }
}
