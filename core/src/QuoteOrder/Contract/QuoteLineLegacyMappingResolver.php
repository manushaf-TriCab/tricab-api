<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

/**
 * Maps Azure variant → legacy tqoti product code + category type.
 * Must not invent catdt when unknown — return null and surface a blocker.
 */
interface QuoteLineLegacyMappingResolver
{
    /**
     * @return array{productCode: string, categoryType: int, colourId: ?int}|null
     */
    public function resolve(string $variantPublicId, ?string $variantCode, ?string $length): ?array;
}
