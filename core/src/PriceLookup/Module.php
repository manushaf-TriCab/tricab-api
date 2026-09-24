<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup;

/**
 * Staff-only internal cost/sell calculator (NOT customer pricing, Price Lists, or Price-As).
 */
final class Module
{
    public const NAME = 'PriceLookup';

    public const PERMISSION_READ = 'PRICE_LOOKUP.READ';

    /** Additional gate for mgmt full-list search (`q=*`). */
    public const PERMISSION_FULL_LIST = 'PRODUCT.PRICING.MANAGE';

    public const SEARCH_LIMIT = 50;

    public const FULL_LIST_LIMIT = 10;

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_READ,
    ];

    /** @var list<string> */
    public const COMPANIES = ['TCA', 'TCUS', 'TCASIA'];

    /** @var list<string> */
    public const CURRENCIES = ['AUD', 'USD', 'BHT', 'NZD', 'SGD', 'MYR'];

    /** @var list<string> */
    public const METRICS = ['metre', 'foot'];
}
