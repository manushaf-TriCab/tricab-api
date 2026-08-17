<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use TriCab\Core\Product\Application\DTO\RegionRecord;

final class RegionDtoSerializer
{
    /** @return array<string, mixed> */
    public static function record(RegionRecord $region): array
    {
        return [
            'publicId' => $region->publicId,
            'code' => $region->code,
            'name' => $region->name,
            'displayName' => $region->displayName,
            'flag' => $region->flag,
            'themeColour' => $region->themeColour,
            'displayOrder' => $region->displayOrder,
            'isDefault' => $region->isDefault,
            'isActive' => $region->isActive,
            'websiteEnabled' => $region->websiteEnabled,
            'showInRegionPicker' => $region->showInRegionPicker,
            'defaultWebsiteRegion' => $region->defaultWebsiteRegion,
            'countryCodes' => $region->countryCodes,
            'countryCount' => $region->countryCount,
            'productUsageCount' => $region->productUsageCount,
            'productCount' => $region->productUsageCount,
            'currencyCode' => $region->currencyCode,
            'timezoneId' => $region->timezoneId,
            'languageCode' => $region->languageCode,
            'office' => $region->office,
            'phone' => $region->phone,
            'email' => $region->email,
            'salesContact' => $region->salesContact,
            'updatedAtUtc' => $region->updatedAtUtc,
            'rowVersion' => $region->rowVersion,
            'isDeleted' => $region->isDeleted,
        ];
    }

    /** @return array<string, mixed> */
    public static function publicRecord(RegionRecord $region): array
    {
        $record = self::record($region);
        unset($record['rowVersion'], $record['isDeleted'], $record['productUsageCount']);
        return $record;
    }
}
