<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class UnresolvedLegacyStyleProduct
{
    public function __construct(
        public string $sourceKey,
        public ?string $rawStyleCode,
        public ?string $title,
        public ?string $kind,
        public string $reason,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sourceKey' => $this->sourceKey,
            'rawStyleCode' => $this->rawStyleCode,
            'title' => $this->title,
            'kind' => $this->kind,
            'reason' => $this->reason,
        ];
    }
}
