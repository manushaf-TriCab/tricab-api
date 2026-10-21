<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * The core counts and conductor sizes a style is actually made in, for the
 * size picker the agent can put under its reply.
 *
 * Read from the product-code index, so the picker only offers combinations a
 * real code exists for. Metric (`C`) codes only: a pair or AWG code counts and
 * sizes in different units, and a picker that mixed them would read as one
 * scale when it is not.
 */
interface AssistantStyleSizes
{
    /**
     * @return list<array{coreCount: int, sizesMm2: list<float>}> ordered by core count, sizes ascending;
     *     empty when the style has no metric codes
     */
    public function forStyle(string $styleCode): array;
}
