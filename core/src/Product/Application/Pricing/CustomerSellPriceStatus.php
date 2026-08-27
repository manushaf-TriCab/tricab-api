<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Customer-facing sell-price display / resolution statuses.
 * Never return $0.00 for unresolved prices.
 */
final class CustomerSellPriceStatus
{
    public const RESOLVED = 'RESOLVED';
    public const TBC = 'TBC';
    public const LOGIN_REQUIRED = 'LOGIN_REQUIRED';
    public const CUSTOMER_REQUIRED = 'CUSTOMER_REQUIRED';
    public const PRICE_NOT_AVAILABLE = 'PRICE_NOT_AVAILABLE';
    public const FX_NOT_AVAILABLE = 'FX_NOT_AVAILABLE';
    public const REVIEW = 'REVIEW';
    public const TEMPORARILY_UNAVAILABLE = 'TEMPORARILY_UNAVAILABLE';
    /** MAKE item needs a positive length before Cost API can price. */
    public const LENGTH_REQUIRED = 'LENGTH_REQUIRED';
    /** Cost API failed for MAKE (or unusable) — map to TEMPORARILY_UNAVAILABLE in customer DTO when preferred. */
    public const COST_SOURCE_UNAVAILABLE = 'COST_SOURCE_UNAVAILABLE';

    public const AVAILABILITY_STOCKED = 'STOCKED';
    public const AVAILABILITY_MAKE = 'MAKE';
    public const AVAILABILITY_UNKNOWN = 'UNKNOWN';

    /** Customer display unit sell price decimal places (catalogue metre prices). */
    public const DISPLAY_SCALE = 3;
}
