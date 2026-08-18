<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertRegionCommand
{
    /** @param list<string> $countryCodes */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $displayName = null,
        public ?string $flag = null,
        public ?string $themeColour = null,
        public int $displayOrder = 0,
        public bool $isDefault = false,
        public bool $isActive = true,
        public bool $websiteEnabled = true,
        public bool $showInRegionPicker = true,
        public bool $defaultWebsiteRegion = false,
        public array $countryCodes = [],
        public ?string $currencyCode = null,
        public ?string $timezoneId = null,
        public ?string $languageCode = null,
        public ?string $office = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $salesContact = null,
        public ?string $publicId = null,
        public ?string $expectedRowVersion = null,
    ) {
    }
}
