<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Application;

/**
 * Finds the catalogue product behind a product code, so the assistant's code
 * card can show the cable's picture and link to its page.
 *
 * Most of the ~32,000 codes have no variant of their own. A code linked to a
 * variant uses that variant's product; otherwise the product assigned to the
 * code's style stands in, since every code of a style is the same construction
 * family and shares its cross-section picture.
 */
interface AssistantCodeProductLookup
{
    /**
     * @param string $codeValue The full product code, e.g. `XL-PAXN/4C16OR`.
     * @param string|null $styleCode The style segment as the code index records it.
     * @param int|null $coreCount Breaks a tie when a style has a product per core count (M3P-1C, M3P-3C).
     */
    public function forCode(string $codeValue, ?string $styleCode, ?int $coreCount): ?AssistantCodeProduct;
}
