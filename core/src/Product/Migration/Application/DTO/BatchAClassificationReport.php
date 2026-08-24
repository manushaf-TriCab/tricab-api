<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final class BatchAClassificationReport
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $mode,
        public readonly bool $applied,
        public readonly int $legacyWrites,
        public readonly int $productWrites,
        public readonly int $variantWrites,
        public readonly int $catalogueWrites,
        public readonly array $payload,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'applied' => $this->applied,
            'legacyWrites' => $this->legacyWrites,
            'productWrites' => $this->productWrites,
            'variantWrites' => $this->variantWrites,
            'catalogueWrites' => $this->catalogueWrites,
            'payload' => $this->payload,
        ];
    }
}
