<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Public product 3D block.
 *
 * @param list<ProductPublic3DModel> $models
 */
final readonly class ProductPublic3DPayload
{
    /**
     * @param list<ProductPublic3DModel> $models
     */
    public function __construct(
        public bool $available,
        public ?ProductPublic3DModel $primary,
        public array $models = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(available: false, primary: null, models: []);
    }
}
