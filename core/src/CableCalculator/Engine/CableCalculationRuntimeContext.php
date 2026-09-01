<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Engine;

/**
 * Replaces legacy $_POST fields used inside math/db cable-calc paths.
 */
final readonly class CableCalculationRuntimeContext
{
    public function __construct(
        public string $earthResistanceMode,
        public float $maximumFaultTimeSeconds,
        public bool $earthAluminium,
    ) {
    }

    public static function fromRequest(
        string $earthResistanceMode,
        float $maximumFaultTimeSeconds,
        bool $earthAluminium,
    ): self {
        return new self($earthResistanceMode, $maximumFaultTimeSeconds, $earthAluminium);
    }

    public function earthResistanceCount(): string|int
    {
        return $this->earthResistanceMode === 'auto' ? 'auto' : $this->earthResistanceMode;
    }
}
