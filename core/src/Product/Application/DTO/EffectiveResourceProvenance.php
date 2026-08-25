<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Provenance for an effective inherited value or asset.
 *
 * sourceType: STYLE | PRODUCT | VARIANT
 */
final readonly class EffectiveResourceProvenance
{
    public function __construct(
        public string $sourceType,
        public ?string $sourceStylePublicId,
        public ?string $sourceStyleCode,
        public int $sourceLevel,
        public bool $inherited,
        public string $label,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceType' => $this->sourceType,
            'sourceStylePublicId' => $this->sourceStylePublicId,
            'sourceStyleCode' => $this->sourceStyleCode,
            'sourceLevel' => $this->sourceLevel,
            'inherited' => $this->inherited,
            'label' => $this->label,
        ];
    }
}
