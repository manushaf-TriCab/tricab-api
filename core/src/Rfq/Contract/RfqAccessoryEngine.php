<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\AccessoryMatch;
use TriCab\Core\Rfq\Domain\Exception\RfqAccessoryEngineFailure;

/**
 * The engine that says which accessories fit a cable (parity plan E7.2).
 *
 * In the source this is tc-web's `ProductConfigurator::getMatchAccAllCalc`
 * over the legacy `tc` database (its `rules_dev`, `product_rules_dev`,
 * `accessory_specs` and `cable_specs` tables), reached through the WordPress
 * bridge. The platform has no port of it yet, so the production binding is
 * {@see \TriCab\Core\Rfq\Infrastructure\UnavailableRfqAccessoryEngine}, which
 * answers exactly as the source does when the engine is missing:
 * `engine_available: false` and a note, never a guess.
 */
interface RfqAccessoryEngine
{
    /** False when no engine is connected; the editor says so and offers nothing. */
    public function isAvailable(): bool;

    /** Why the engine is not available, in words for the rep. */
    public function unavailableNote(): string;

    /**
     * The accessories for one cable line, in the engine's order.
     *
     * @param float $totalLength metres on the line (1 when the line states none)
     * @param int   $runs        the line's number of lengths, at least 1
     *
     * @return list<AccessoryMatch>
     *
     * @throws RfqAccessoryEngineFailure when the engine fails for this cable; the caller skips that line
     */
    public function match(string $productCode, float $totalLength, int $runs, int $customerId, string $region, string $currency): array;
}
