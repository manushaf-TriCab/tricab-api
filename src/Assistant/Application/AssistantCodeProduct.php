<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

final readonly class AssistantCodeProduct
{
    /**
     * @param string|null $variantPublicId Only when the code itself is linked to a variant;
     *     a style's stand-in product never supplies one, because adding it to an RFQ would
     *     quote a different code.
     */
    public function __construct(
        public ?string $imageUrl,
        public ?string $imageBackgroundColor,
        public ?string $productSlug,
        public ?string $variantPublicId,
    ) {
    }

    /** @return array{url: string, backgroundColor: ?string}|null */
    public function image(): ?array
    {
        return $this->imageUrl === null
            ? null
            : ['url' => $this->imageUrl, 'backgroundColor' => $this->imageBackgroundColor];
    }
}
