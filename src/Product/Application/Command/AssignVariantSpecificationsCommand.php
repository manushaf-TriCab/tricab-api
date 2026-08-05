<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

/**
 * @phpstan-import-type SpecValueInput from AssignProductSpecificationsCommand
 */
final readonly class AssignVariantSpecificationsCommand
{
    /**
     * @param list<SpecValueInput> $values
     */
    public function __construct(
        public string $variantPublicId,
        public array $values,
    ) {
    }
}
