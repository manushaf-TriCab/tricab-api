<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

/**
 * @phpstan-type SpecValueInput array{
 *   definitionPublicId: string,
 *   valueText?: ?string,
 *   valueLongText?: ?string,
 *   valueInteger?: ?int,
 *   valueDecimal?: ?string,
 *   valueBoolean?: ?bool,
 *   valueDate?: ?string,
 *   valueUrl?: ?string,
 *   valueMinDecimal?: ?string,
 *   valueMaxDecimal?: ?string,
 *   optionPublicId?: ?string,
 *   optionPublicIds?: list<string>,
 *   optionStatuses?: array<string, string>
 * }
 */
final readonly class AssignProductSpecificationsCommand
{
    /**
     * @param list<SpecValueInput> $values
     */
    public function __construct(
        public string $productPublicId,
        public array $values,
    ) {
    }
}
