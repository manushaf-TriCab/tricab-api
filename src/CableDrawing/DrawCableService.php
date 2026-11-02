<?php

declare(strict_types=1);

namespace TriCab\Api\CableDrawing;

use TriCab\CableGen\CableGen;
use TriCab\CableGen\Exception\CableGenException;
use TriCab\CableGen\Model\Colour;
use TriCab\CableGen\Rendering\RenderOptions;
use TriCab\CableGen\Rendering\SvgRenderer;
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
        if ($code->colour !== null && Colour::parseList($code->colour) === []) {
            throw CableDrawingException::unavailable($code->code, 'The drawing renderer does not support this colour code yet.');
        }
        $rows = $this->specs->forStyle($code->style);
        $matches = array_values(array_filter($rows, $code->matches(...)));
        if ($matches === []) {
            throw CableDrawingException::specNotFound($code->code);
        }
        usort($matches, static fn (array $a, array $b): int => self::rank($a, $code) <=> self::rank($b, $code));

        $bestRank = self::rank($matches[0], $code);
        $best = array_values(array_filter($matches, static fn (array $row): bool => self::rank($row, $code) === $bestRank));
        $constructions = array_unique(array_map(static fn (array $row): string => json_encode(array_intersect_key($row, array_flip(['nc', 'cc', 'ne', 'xae', 'cdia', 'ins', 'od_ins', 'ode_ins', 'od_bed', 'od_arm', 'od_brd', 'od'])), JSON_THROW_ON_ERROR), $best));
        $warnings = count($constructions) > 1
            ? ['Multiple constructions match this code; drawing uses the preferred available specification as a schematic.']
            : [];

        try {
            $cable = $this->mapper->map($matches[0], $this->styleOptions($code, $matches[0], $warnings));
            $section = $this->cableGen->build($cable);
            $svg = (new SvgRenderer())->render($section, new RenderOptions(referenceDiameterMm: $this->referenceDiameter($code->style, $rows)), $cable->name);
        } catch (CableGenException $e) {
            throw CableDrawingException::unavailable($code->code, $e->getMessage());
        }

        return new CableDrawing($svg, $cable->name, $section->warnings);
    }

    /**
     * Prefer a row whose conductor agrees with the code and the standard material row.
     * Screening differences at the same rank are ambiguous, never a preference.
     *
     * @param array<string, string> $row
     *
     * @return list<bool>
     */
    private static function rank(array $row, CableProductCode $code): array
    {
        return [
            ($row['ct'] === 'a') !== $code->isAluminium(),
            $row['mt'] !== '1301',
        ];
    }

    /**
     * @param array<string, string> $row
     * @param list<string> $warnings
     */
    private function styleOptions(CableProductCode $code, array $row, array $warnings = []): StyleOptions
    {
        $colour = $code->colour;
        $known = $colour !== null && Colour::parseList($colour) !== [];
        $bareWire = in_array($code->style, ['LS', 'LP', 'UP'], true);

        // A bare wire's colour is its insulation; a sheathed single core (SDI) has white insulation under a coloured sheath.
        return new StyleOptions(
            coreColours: match (true) {
                $bareWire => $known ? [$colour] : [],
                (int) $row['nc'] === 1 && (int) $row['ne'] === 0 => ['WH'],
                default => [],
            },
            sheathColour: $known ? $colour : 'BK',
            conductorMaterial: $code->isAluminium() ? Colour::ALUMINIUM : Colour::COPPER,
            warnings: array_merge($warnings, ($row['ct'] === 'a') !== $code->isAluminium()
                ? ['Dimensions come from a different conductor material; matching material dimensions are unavailable.']
                : []),
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
                    $candidate = $this->mapper->map($row);
                    $this->cableGen->build($candidate);
                    $largest = max($largest, $candidate->overallDiameterMm);
                } catch (CableGenException) {
                    continue;
                }
            }
            $this->referenceDiameters[$style] = $largest;
        }

        return $this->referenceDiameters[$style];
    }
}
