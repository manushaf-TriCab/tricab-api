<?php

declare(strict_types=1);

namespace TriCab\Api\CableDrawing;

use TriCab\CableGen\CableGen;
use TriCab\CableGen\Exception\CableGenException;
use TriCab\CableGen\Model\Colour;
use TriCab\CableGen\Rendering\RenderOptions;
use TriCab\CableGen\Spec\LegacySpecMapper;
use TriCab\CableGen\Spec\StyleOptions;

/**
 * Draws the cross-section a cable product code names, with tricab/cablegen.
 *
 * Every size of a style is drawn at one scale: the style's largest cable
 * fills the canvas, so a smaller size looks smaller.
 */
final class DrawCableService
{
    /** @var array<string, float> */
    private array $referenceDiameters = [];

    public function __construct(
        private readonly CableDrawingSpecs $specs,
        private readonly CableGen $cableGen = new CableGen(),
        private readonly LegacySpecMapper $mapper = new LegacySpecMapper(),
    ) {
    }

    /**
     * @throws CableDrawingException
     */
    public function draw(string $productCode): CableDrawing
    {
        $code = CableProductCode::parse($productCode) ?? throw CableDrawingException::invalidCode($productCode);
        if ($code->isAwg()) {
            throw CableDrawingException::unavailable($code->code, 'AWG sizes have their diameters in inches, which aren\'t drawn yet.');
        }
        $rows = $this->specs->forStyle($code->style);
        $matches = array_values(array_filter($rows, $code->matches(...)));
        if ($matches === []) {
            throw CableDrawingException::specNotFound($code->code);
        }
        usort($matches, static fn (array $a, array $b): int => self::rank($a, $code) <=> self::rank($b, $code));

        try {
            $cable = $this->mapper->map($matches[0], $this->styleOptions($code));
            $section = $this->cableGen->build($cable);
            $svg = $this->cableGen->render($cable, new RenderOptions(referenceDiameterMm: $this->referenceDiameter($code->style, $rows)));
        } catch (CableGenException $e) {
            throw CableDrawingException::unavailable($code->code, $e->getMessage());
        }

        return new CableDrawing($svg, $cable->name, $section->warnings);
    }

    /**
     * Prefer a row whose conductor agrees with the code, then the plain (unscreened)
     * row, since an upper-case cc reads the same in a code.
     *
     * @param array<string, string> $row
     *
     * @return list<bool>
     */
    private static function rank(array $row, CableProductCode $code): array
    {
        return [
            ($row['ct'] === 'a') !== $code->isAluminium(),
            $row['cc'] !== strtolower($row['cc']),
            $row['mt'] !== '1301',
        ];
    }

    private function styleOptions(CableProductCode $code): StyleOptions
    {
        $colour = $code->colour;
        $known = $colour !== null && Colour::parseList($colour) !== [];

        return new StyleOptions(
            coreColours: $colour === Colour::EARTH ? [Colour::EARTH] : [],
            sheathColour: $known && $colour !== Colour::EARTH ? $colour : 'BK',
            conductorMaterial: $code->isAluminium() ? Colour::ALUMINIUM : Colour::COPPER,
        );
    }

    /**
     * The largest overall diameter among the style's drawable rows.
     *
     * @param list<array<string, string>> $rows
     */
    private function referenceDiameter(string $style, array $rows): float
    {
        if (!isset($this->referenceDiameters[$style])) {
            $largest = 0.0;
            foreach ($rows as $row) {
                try {
                    $largest = max($largest, $this->mapper->map($row)->overallDiameterMm);
                } catch (CableGenException) {
                    continue;
                }
            }
            $this->referenceDiameters[$style] = $largest;
        }

        return $this->referenceDiameters[$style];
    }
}
