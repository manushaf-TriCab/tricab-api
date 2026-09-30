<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Service;

use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;

/**
 * Price row for a cable with no price of its own: its mask, then its base style's mask for
 * jacket variants. Exact matches only, so KL-XXXN/1C10 never picks up KL-XXXN/1C100.
 * Shared by search and Get Price so the two never disagree about whether a code is priceable.
 * A colourless code only borrows its mask's price when it is a real product: KL-PBXD/1C120
 * isn't in tpro, so it is an unfinished code, not a cable.
 */
final class PriceLookupMaskFallback
{
    public function __construct(
        private readonly PriceLookupPriceRepository $prices,
        private readonly PriceLookupColourMask $colourMask = new PriceLookupColourMask(),
    ) {
    }

    /**
     * @return array{
     *     mask: string,
     *     row: array{
     *         productCode: string,
     *         cost: string,
     *         setupLength: string|null,
     *         lastUpdated: string|null,
     *         warehouseStatus: mixed
     *     }
     * }|null
     */
    public function find(string $dbco, string $productCode, string $company): ?array
    {
        if ($this->colourMask->isColourless($productCode) && !$this->prices->isProduct($productCode)) {
            throw PriceLookupValidationException::incompleteProductCode($productCode);
        }

        foreach ($this->colourMask->masksForLookup($productCode) as $mask) {
            $row = $this->prices->findByCode($dbco, $mask);
            if ($row !== null) {
                error_log('[price-lookup] mask_fallback productCode=' . $productCode . ' mask=' . $mask . ' company=' . $company);

                return ['mask' => $mask, 'row' => $row];
            }
        }

        return null;
    }
}
