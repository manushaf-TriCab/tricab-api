<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class RegionWriteData
{
    /** @param list<string> $countryCodes */
    public function __construct(
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
        public ?string $currencyCode,
        public ?string $timezoneId,
        public ?string $languageCode,
        public ?string $office,
        public ?string $phone,
        public ?string $email,
        public ?string $salesContact,
    ) {
    }
}
