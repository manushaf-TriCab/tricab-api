<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use RuntimeException;

/** The model could not give a usable answer (timeout, throttling, invalid output). Safe to show: no secrets or mail text. */
final class ClassifierFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $model = '', public readonly string $version = '', public readonly int $latencyMs = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
