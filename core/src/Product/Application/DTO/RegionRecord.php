<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class RegionRecord
{
    /**
     * @param list<string> $countryCodes
     */
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public string $displayName,
        public ?string $flag,
        public ?string $themeColour,
        public int $displayOrder,
        public bool $isDefault,
        public bool $isActive,
        public bool $websiteEnabled,
        public bool $showInRegionPicker,
        public bool $defaultWebsiteRegion,
        public array $countryCodes,
        public int $countryCount,
        public int $productUsageCount,
        public ?string $updatedAtUtc,
        public string $rowVersion,
        public bool $isDeleted = false,
        public ?string $currencyCode = null,
        public ?string $timezoneId = null,
        public ?string $languageCode = null,
        public ?string $office = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $salesContact = null,
    ) {
    }
}
