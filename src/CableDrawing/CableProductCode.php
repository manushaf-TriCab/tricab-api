<?php

declare(strict_types=1);

namespace TriCab\Api\CableDrawing;

use TriCab\Core\CableCalculator\Engine\CableProductCodeGenerator;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;

/**
 * A cable code using Price Lookup syntax and mask/style restrictions.
 * Drawing-spec matching remains separate from code validation.
 *
 * The count includes one for the earth when the cable has earths (except OD),
 * a block ending in A marks aluminium conductors, and the suffix is the sheath
 * colour (EA for an earth cable). The calculator offers aluminium for any row,
 * so matching ignores the row's conductor; the caller prefers a row that agrees.
 */
final readonly class CableProductCode
{
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
        $rules = new PriceLookupColourMask();
        $parts = $rules->parseCode($code);
        if ($parts === null || $rules->isMask($code) || $rules->isXxCodeOfOtherStyle($code)) {
            return null;
        }

        // Syntax and style restrictions belong to Price Lookup, not to the renderer.
        // The shared parser already validated coreSize, including Q/A and AWG aughts.
        if (preg_match('/^(\d+)([CPTQA])(.+)$/', $parts['coreSize'], $core) !== 1) {
            return null;
        }

        return new self($code, $parts['style'], $parts['block'], (int) $core[1], $core[2], $core[3], $parts['colour']);
    }

    /** AWG gauges ("1/0") come with diameters in inches, which the drawings don't take. */
    public function isAwg(): bool
    {
        return $this->config === 'A' || !is_numeric($this->size);
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
        $generated = CableProductCodeGenerator::generate(
            $this->style,
            $row['xa'],
            core: $row['nc'] . $row['cc'],
            earth: (int) $row['ne'],
            ctAlumin: $this->isAluminium(),
            colour: $this->colour ?? '',
            earthSize: $row['xae'] ?? '',
        );
        $generatedBlock = explode('/', explode('-', $generated, 2)[1], 2)[0];
        if ($generatedBlock !== $this->block) {
            return false;
        }
        // Earth-only styles, and KL as an earth, are always written as 1C.
        if ($this->count === 1 && (in_array($this->style, ['LP', 'LS', 'UP'], true) || ($this->style === 'KL' && $this->colour === 'EA'))) {
            return (int) $row['nc'] === 1 && (int) $row['ne'] === 0;
        }
        $earths = (int) $row['ne'];

        return (int) $row['nc'] + ($earths > 0 && $this->style !== 'OD' ? 1 : 0) === $this->count;
    }
}
