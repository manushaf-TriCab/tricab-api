<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Classification;

final class LegacyTermSemanticType
{
    public const PRODUCT_CATEGORY = 'PRODUCT_CATEGORY';
    public const APPLICATION = 'APPLICATION';
    public const INDUSTRY = 'INDUSTRY';
    public const VOLTAGE = 'VOLTAGE';
    public const REGION = 'REGION';
    public const MARKETING_GROUP = 'MARKETING_GROUP';
    public const PRODUCT_ATTRIBUTE = 'PRODUCT_ATTRIBUTE';
    public const STANDARD = 'STANDARD';
    public const APPROVAL = 'APPROVAL';
    public const CATALOGUE_NAVIGATION = 'CATALOGUE_NAVIGATION';
    public const LEGACY_METADATA = 'LEGACY_METADATA';
    public const OBSOLETE = 'OBSOLETE';
    public const UNKNOWN = 'UNKNOWN';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::PRODUCT_CATEGORY,
            self::APPLICATION,
            self::INDUSTRY,
            self::VOLTAGE,
            self::REGION,
            self::MARKETING_GROUP,
            self::PRODUCT_ATTRIBUTE,
            self::STANDARD,
            self::APPROVAL,
            self::CATALOGUE_NAVIGATION,
            self::LEGACY_METADATA,
            self::OBSOLETE,
            self::UNKNOWN,
        ];
    }
}
