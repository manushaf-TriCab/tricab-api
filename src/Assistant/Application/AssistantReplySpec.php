<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

use TriCab\Core\ProductFinder\Application\DTO\ProductFinderQuery;

/**
 * The "spec so far" a reply carries: the requirement the agent last put to the
 * product finder, in a fixed shape with a key for everything and null where it
 * is not known. The web chat shows it as a running panel.
 *
 * Install and voltage labels mirror the Cable Calculator's own option lists
 * (`INSTALLATION_OPTIONS` and `VOLTAGE_OPTIONS` in tricab-web's
 * `cableCalculatorModel.ts`), the only register of those codes; the PHP side
 * has none. Colour labels are the two-character sheath segment codes the
 * `search_product_codes` tool documents. A colour word the table cannot place
 * keeps a null label rather than a guess: the finder's own alias table
 * (`canonical_colour`) is the single authority and is not copied here.
 */
final class AssistantReplySpec
{
    /** @var array<int, string> */
    private const INSTALL_LABELS = [
        1 => 'Unenclosed spaced',
        2 => 'Unenclosed touching',
        3 => 'Buried in spaced conduit',
        4 => 'Buried spaced',
        5 => 'Conduit in air',
        6 => 'Exposed to sun',
        7 => 'Buried in touching conduit',
        8 => 'IEC',
        9 => 'Buried touching',
    ];

    /** @var array<int, string> */
    private const VOLTAGE_LABELS = [
        1000 => '1000 V',
        400 => '400 / 415 V',
        240 => '220 / 240 V',
        48 => '48 V',
        24 => '24 V',
    ];

    /** @var array<int, string> The finder's and the calculator's phase codes, not a count of phases. */
    private const PHASE_LABELS = [
        3 => 'Three phase',
        4 => 'Single phase',
        5 => 'DC',
    ];

    /** @var array<string, string> */
    private const COLOUR_LABELS = [
        'BK' => 'Black',
        'OR' => 'Orange',
        'RE' => 'Red',
        'GY' => 'Grey',
        'BL' => 'Blue',
        'WH' => 'White',
        'EA' => 'Green/Yellow',
        'YE' => 'Yellow',
        'BR' => 'Brown',
        'GR' => 'Green',
        'DB' => 'Dark Blue',
        'LB' => 'Light Blue',
        'PU' => 'Purple',
        'PI' => 'Pink',
        'CL' => 'Clear',
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromFinderQuery(ProductFinderQuery $query): array
    {
        return self::build(
            coreCount: $query->coreCount,
            crossSectionMm2: $query->crossSectionMm2,
            colour: $query->colour,
            style: $query->style,
            voltage: $query->voltage,
            phase: $query->phase,
            install: $query->install,
            ambient: $query->ambient,
            loadAmps: $query->loadAmps,
            lengthM: $query->lengthM,
        );
    }

    /**
     * From a product-code search: only the facets it shares with the finder.
     * Null when it stated none of them, so a text-only lookup does not blank the
     * panel.
     *
     * @return array<string, mixed>|null
     */
    public static function fromCodeSearch(
        ?int $coreCount,
        ?float $crossSectionMinMm2,
        ?float $crossSectionMaxMm2,
        ?string $colour,
        string|null $style,
    ): ?array {
        $crossSection = $crossSectionMinMm2 !== null && $crossSectionMinMm2 === $crossSectionMaxMm2
            ? $crossSectionMinMm2
            : null;
        if ($coreCount === null && $crossSection === null && $colour === null && $style === null) {
            return null;
        }

        return self::build($coreCount, $crossSection, $colour, $style);
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(
        ?int $coreCount = null,
        ?float $crossSectionMm2 = null,
        ?string $colour = null,
        ?string $style = null,
        ?int $voltage = null,
        ?int $phase = null,
        ?int $install = null,
        ?int $ambient = null,
        ?float $loadAmps = null,
        ?float $lengthM = null,
    ): array {
        $colour = self::clean($colour);
        $style = self::clean($style);

        return [
            'coreCount' => $coreCount,
            'crossSectionMm2' => $crossSectionMm2,
            'colour' => $colour,
            'colourLabel' => self::colourLabel($colour),
            'style' => $style,
            'voltage' => $voltage,
            'voltageLabel' => $voltage === null ? null : (self::VOLTAGE_LABELS[$voltage] ?? $voltage . ' V'),
            'phase' => $phase,
            'phaseLabel' => $phase === null ? null : (self::PHASE_LABELS[$phase] ?? 'Phase ' . $phase),
            'installMethod' => $install === null ? null : [
                'code' => $install,
                'label' => self::INSTALL_LABELS[$install] ?? 'Method ' . $install,
            ],
            'ambientC' => $ambient,
            'loadAmps' => $loadAmps,
            'lengthM' => $lengthM,
        ];
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function colourLabel(?string $colour): ?string
    {
        if ($colour === null) {
            return null;
        }
        $code = strtoupper($colour);
        if (isset(self::COLOUR_LABELS[$code])) {
            return self::COLOUR_LABELS[$code];
        }
        foreach (self::COLOUR_LABELS as $label) {
            if (strcasecmp($label, $colour) === 0) {
                return $label;
            }
        }

        return match (strtolower($colour)) {
            'gray' => 'Grey',
            'green/yellow', 'green yellow', 'green-yellow', 'earth' => 'Green/Yellow',
            default => null,
        };
    }
}
