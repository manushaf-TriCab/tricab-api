<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Service;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * Approved, explicit dimension-code declarations for accessory 3D generation.
 *
 * A geometry template names the set it needs (query parameter `dimensionSet`); the accessory
 * specification-source endpoint then returns only those codes (plus MATERIALS). A request with
 * no set keeps the original behaviour: the `terminal` set, which is the list the A06/A07/A09
 * generators were built against. Adding a code to a set, or adding a set, is a reviewed code change.
 * Specification values and definitions are never altered by any of this.
 */
final class Accessory3DDimensionSets
{
    public const DEFAULT = 'terminal';

    /**
     * @var array<string, list<string>>
     */
    private const SETS = [
        // Original list (A06 ring, A07 fork, A09 pin). Do not shrink: existing clients rely on it.
        'terminal' => [
            'OVERALL-LENGTH',
            'WIDTH',
            'THICKNESS',
            'HEIGHT',
            'BARREL-LENGTH',
            'BARREL-OD',
            'STUD-OD',
            // A09/A29: the catalogue value (12 / 14 mm) is the pin LENGTH; the definition name is misleading.
            'PIN-DIAMETER',
            'INNER-DIAMETER',
            'OUTER-DIAMETER',
        ],
        // Rectangular-palm cable lugs (B37). Dimensions only; no electrical attributes.
        'cable-lug' => [
            'OVERALL-LENGTH',
            'WIDTH',
            'THICKNESS',
            'STUD-OD',
            'BARREL-LENGTH',
            'INNER-DIAMETER',
            'OUTER-DIAMETER',
        ],
        // Declared for the next waves (catalogue audit 2026-10-22); no generator consumes them yet.
        'gland' => ['THREAD-OD', 'THREAD-LENGTH', 'MAX-CABLE-DIAMETER', 'MIN-CABLE-DIAMETER', 'CONDUIT-OD', 'NUT-THICKNESS'],
        'tube' => ['INNER-DIAMETER', 'OUTER-DIAMETER', 'OVERALL-LENGTH', 'DEPTH'],
        'duct' => ['WIDTH', 'HEIGHT', 'CABLE-LENGTH'],
        'box' => ['HEIGHT', 'WIDTH', 'DEPTH'],
        'tape' => ['WIDTH', 'THICKNESS', 'CABLE-LENGTH'],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::SETS);
    }

    public static function isSupported(string $name): bool
    {
        return isset(self::SETS[strtolower(trim($name))]);
    }

    /**
     * @return list<string> approved codes for the set; the default set when $name is null or blank
     */
    public static function codes(?string $name): array
    {
        $key = $name === null || trim($name) === '' ? self::DEFAULT : strtolower(trim($name));
        if (!isset(self::SETS[$key])) {
            throw InvalidProductValue::for('dimensionSet', $key);
        }

        return self::SETS[$key];
    }
}
