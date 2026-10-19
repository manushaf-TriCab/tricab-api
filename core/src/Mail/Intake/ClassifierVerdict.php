<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

/** A validated classifier answer. */
final readonly class ClassifierVerdict
{
    public function __construct(
        public string $label,
        public float $confidence,
        public string $reason,
        public bool $injectionSuspected,
        public string $model,
        public string $version,
        public int $latencyMs,
    ) {
    }
}
