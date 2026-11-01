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
            'BB-XXXD/1C1/0OR' => [CableDrawingException::UNAVAILABLE, 422],
            'BB-XXXN/1C10OR' => [CableDrawingException::UNAVAILABLE, 422],
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
