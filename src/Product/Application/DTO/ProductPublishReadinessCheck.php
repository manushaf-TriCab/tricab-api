<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Result of a single named readiness check, regardless of outcome.
 */
final readonly class ProductPublishReadinessCheck
{
    public function __construct(
        public string $code,
        public bool $passed,
    ) {
    }
}
