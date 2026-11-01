<?php

declare(strict_types=1);

namespace TriCab\Api\CableDrawing;

/**
 * A cable product code as CableProductCodeGenerator writes it:
 * STYLE-BLOCK/{count}{C|P|T}{size}{colour}, e.g. CA-PAXN/5C16BK.
 *
 * The count includes one for the earth when the cable has earths (except OD),
 * a block ending in A marks aluminium conductors, and the suffix is the sheath
 * colour (EA for an earth cable). The calculator offers aluminium for any row,
 * so matching ignores the row's conductor; the caller prefers a row that agrees.
 */
final readonly class CableProductCode
{
    /** Size is mm² ("16", ".5") or an AWG gauge ("1/0"). */
    private const PATTERN = '~^([A-Z0-9]+)-([A-Z0-9]{4})/(\d+)([CPT])(\d+/0|\d*\.?\d+)([A-Z]{2})?$~';

    private function __construct(
        public string $code,
        public string $style,
        public string $block,
        public int $count,
        public string $config,
        public string $size,
        public ?string $colour,
    ) {
    }

    public static function parse(string $code): ?self
    {
        $code = strtoupper(trim($code));
        if (preg_match(self::PATTERN, $code, $m) !== 1) {
            return null;
        }

        return new self($code, $m[1], $m[2], (int) $m[3], $m[4], $m[5], ($m[6] ?? '') !== '' ? $m[6] : null);
    }

    /** AWG gauges ("1/0") come with diameters in inches, which the drawings don't take. */
    public function isAwg(): bool
    {
        return !is_numeric($this->size);
    }

    public function isAluminium(): bool
    {
        return str_ends_with($this->block, 'A');
    }

    /**
     * Whether a cable_specs row is the cable this code names, by the generator's own rules.
     *
     * @param array<string, string> $row
     */
    public function matches(array $row): bool
    {
        // AWG rows have sizes like "1/0", which PHP would read as 1.
        if ($this->isAwg() || !is_numeric($row['xa']) || (float) $row['xa'] !== (float) $this->size || strtoupper($row['cc']) !== $this->config) {
            return false;
        }
        // Earth-only styles, and KL as an earth, are always written as 1C.
        if ($this->count === 1 && (in_array($this->style, ['LP', 'LS', 'UP'], true) || ($this->style === 'KL' && $this->colour === 'EA'))) {
            return true;
        }
        $earths = (int) $row['ne'];

        return (int) $row['nc'] + ($earths > 0 && $this->style !== 'OD' ? 1 : 0) === $this->count;
    }
}
