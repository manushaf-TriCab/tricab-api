<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Application\DTO;

final class F12LiveSkuPilotDryRunReport
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $mode,
        public readonly bool $applied,
        public readonly int $wordpressWrites,
        public readonly int $tcWrites,
        public readonly int $forbiddenWriteAttempts,
        public readonly int $targetWrites,
        public readonly int $blobWrites,
        public readonly int $pricingWrites,
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
            'wordpressWrites' => $this->wordpressWrites,
            'tcWrites' => $this->tcWrites,
            'forbiddenWriteAttempts' => $this->forbiddenWriteAttempts,
            'targetWrites' => $this->targetWrites,
            'blobWrites' => $this->blobWrites,
            'pricingWrites' => $this->pricingWrites,
            'payload' => $this->payload,
        ];
    }
}
