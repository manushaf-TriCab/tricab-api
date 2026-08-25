<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * @phpstan-type SpecValue array{
 *   definitionPublicId: string,
 *   valueText: ?string,
 *   valueLongText: ?string,
 *   valueInteger: ?int,
 *   valueDecimal: ?string,
 *   valueBoolean: ?bool,
 *   valueDate: ?string,
 *   valueUrl: ?string,
 *   valueMinDecimal: ?string,
 *   valueMaxDecimal: ?string,
 *   optionPublicId: ?string,
 *   optionPublicIds: list<string>
 * }
 */
final readonly class EffectiveSpecificationValue
{
    /**
     * @param SpecValue $value
     */
    public function __construct(
        public string $definitionPublicId,
        public string $definitionCode,
        public string $definitionName,
        public ?string $displayValue,
        public array $value,
        public EffectiveResourceProvenance $provenance,
    ) {
    }
}
