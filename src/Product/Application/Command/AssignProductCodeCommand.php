<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AssignProductCodeCommand
{
    /**
     * @param array<string, string> $segmentValues keyed by segmentCode
     */
    public function __construct(
        public string $productPublicId,
        public string $ruleSetPublicId,
        public array $segmentValues,
    ) {
    }
}
