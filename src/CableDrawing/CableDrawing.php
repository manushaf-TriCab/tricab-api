<?php

declare(strict_types=1);

namespace TriCab\Api\CableDrawing;

/** A drawn cable: the SVG, the spec row it came from, and where data and geometry disagreed. */
final readonly class CableDrawing
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public string $svg,
        public string $specName,
        public array $warnings,
    ) {
    }
}
