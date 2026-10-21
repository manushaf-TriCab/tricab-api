<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * Whether a product code is held in stock or made to order, from the live
 * source rather than the code index's partial snapshot, so the card and the
 * agent's reply say the same thing about it.
 */
interface AssistantAvailability
{
    public const STOCK = 'STOCK';
    public const MAKE = 'MAKE';

    /**
     * @param list<string> $codes
     * @return array<string, self::STOCK|self::MAKE> keyed by the code as given; a code the source
     *     cannot answer for is left out, and is shown with no availability at all
     */
    public function forCodes(array $codes, ?string $regionCode): array;
}
