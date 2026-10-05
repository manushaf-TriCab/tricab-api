<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Fixtures;

use TriCab\Core\Rfq\Application\DTO\AccessoryMatch;
use TriCab\Core\Rfq\Contract\RfqAccessoryEngine;
use TriCab\Core\Rfq\Domain\Exception\RfqAccessoryEngineFailure;

/**
 * An accessory engine that answers from a table and records every ask. Never
 * reaches tc-web, WordPress or any database.
 */
final class RecordingAccessoryEngine implements RfqAccessoryEngine
{
    /** @var array<string, list<AccessoryMatch>> matches by the cable's product code */
    public array $matches = [];

    /** @var list<string> cable codes the engine fails for */
    public array $failFor = [];

    /** @var list<array{code: string, length: float, runs: int, customerId: int, region: string, currency: string}> */
    public array $asked = [];

    public bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function unavailableNote(): string
    {
        return 'Test engine switched off.';
    }

    public function match(string $productCode, float $totalLength, int $runs, int $customerId, string $region, string $currency): array
    {
        $this->asked[] = ['code' => $productCode, 'length' => $totalLength, 'runs' => $runs, 'customerId' => $customerId, 'region' => $region, 'currency' => $currency];
        if (in_array($productCode, $this->failFor, true)) {
            throw new RfqAccessoryEngineFailure('engine failed for ' . $productCode);
        }

        return $this->matches[$productCode] ?? [];
    }
}
