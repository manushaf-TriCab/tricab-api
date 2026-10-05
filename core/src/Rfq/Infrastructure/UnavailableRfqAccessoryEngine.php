<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqAccessoryEngine;
use TriCab\Core\Rfq\Domain\Exception\RfqAccessoryEngineFailure;

/**
 * No accessory engine is connected. The source answers the same way when the
 * WordPress ProductConfigurator is absent: `engine_available: false`, with a
 * note, and no accessories.
 */
final class UnavailableRfqAccessoryEngine implements RfqAccessoryEngine
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function unavailableNote(): string
    {
        return 'The accessory engine is not connected to this platform yet, so accessories cannot be matched here.';
    }

    public function match(string $productCode, float $totalLength, int $runs, int $customerId, string $region, string $currency): array
    {
        throw new RfqAccessoryEngineFailure($this->unavailableNote());
    }
}
