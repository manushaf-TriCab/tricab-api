<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Service;

use TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;
use TriCab\Core\PriceLookup\Module;

/**
 * Product codes for Price Lookup's type-ahead, loaded once when the page opens so typing
 * doesn't query tc. Codes come from the product master, so cables priced from a mask are
 * suggested too; masks themselves never are, nor XX codes of styles that can't have one.
 */
final class ListPriceLookupCodesService
{
    public function __construct(
        private readonly PriceLookupPriceRepository $prices,
        private readonly PriceLookupColourMask $colourMask = new PriceLookupColourMask(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function execute(): array
    {
        $codes = $this->prices->listProductCodes(Module::CODE_LIST_LIMIT);
        if (count($codes) >= Module::CODE_LIST_LIMIT) {
            error_log('[price-lookup] code_list_truncated limit=' . Module::CODE_LIST_LIMIT);
        }

        return array_values(array_filter($codes, fn (string $code): bool => !$this->colourMask->isMask($code) && !$this->colourMask->isXxCodeOfOtherStyle($code)));
    }
}
