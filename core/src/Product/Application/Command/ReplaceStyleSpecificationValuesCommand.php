<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ReplaceStyleSpecificationValuesCommand
{
    /**
     * @param list<array<string, mixed>> $values
     */
    public function __construct(
        public string $stylePublicId,
        public array $values,
        public string $expectedRowVersion,
    ) {
    }
}
