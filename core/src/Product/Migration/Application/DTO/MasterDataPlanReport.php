<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final readonly class MasterDataPlanReport
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $generatedAtUtc,
        public string $mode,
        public bool $applied,
        public array $payload,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'phase' => '12.7',
            'generatedAtUtc' => $this->generatedAtUtc,
            'mode' => $this->mode,
            'applied' => $this->applied,
            'legacyWrites' => 0,
            'productWrites' => 0,
            'variantWrites' => 0,
            'priceWrites' => 0,
            'fileWrites' => 0,
            'payload' => $this->payload,
        ];
    }
}
