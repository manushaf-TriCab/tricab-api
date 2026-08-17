<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Region;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Product\Application\DTO\RegionRecord;
use TriCab\Core\Product\Application\Exception\InvalidRegion;
use TriCab\Core\Product\Contract\RegionRepository;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;

final class RegionPreferenceResolver
{
    public const COOKIE_NAME = 'tricab_region';

    public function __construct(
        private readonly RegionRepository $regions,
        private readonly ReferenceDataCache $cache,
    ) {
    }

    public function resolveCode(ServerRequestInterface $request, ?string $explicit = null): string
    {
        return $this->resolve($request, $explicit)['region']->code;
    }

    /**
     * @return array{region: RegionRecord, source: 'explicit'|'cookie'|'default'}
     */
    public function resolve(ServerRequestInterface $request, ?string $explicit = null): array
    {
        $query = $request->getQueryParams();
        $requested = $this->normalize($explicit)
            ?? $this->queryValue($query, 'regionCode')
            ?? $this->queryValue($query, 'rgn')
            ?? $this->queryValue($query, 'region');
        if ($requested !== null) {
            return ['region' => $this->requireSelectable($requested), 'source' => 'explicit'];
        }

        $cookie = $this->normalize($request->getCookieParams()[self::COOKIE_NAME] ?? null);
        if ($cookie !== null) {
            $matched = $this->findSelectable($cookie);
            if ($matched !== null) {
                return ['region' => $matched, 'source' => 'cookie'];
            }
        }

        $regions = $this->publicRegions();
        foreach ($regions as $region) {
            if ($region->defaultWebsiteRegion) {
                return ['region' => $region, 'source' => 'default'];
            }
        }
        foreach ($regions as $region) {
            if ($region->isDefault) {
                return ['region' => $region, 'source' => 'default'];
            }
        }
        if ($regions !== []) {
            return ['region' => $regions[0], 'source' => 'default'];
        }

        throw InvalidRegion::field('region', 'no active website region is configured.');
    }

    public function requireSelectable(string $code): RegionRecord
    {
        $region = $this->findSelectable($code);
        if ($region === null) {
            throw InvalidRegion::field('region', 'must identify an active website region shown in the picker.');
        }
        return $region;
    }

    private function findSelectable(string $code): ?RegionRecord
    {
        foreach ($this->publicRegions() as $region) {
            if (strcasecmp($region->code, $code) === 0) {
                return $region;
            }
        }
        return null;
    }

    /** @return list<RegionRecord> */
    public function publicRegions(): array
    {
        $cached = $this->cache->getRegions();
        if (is_array($cached)) {
            $records = [];
            foreach ($cached as $item) {
                $record = $this->fromCache($item);
                if ($record === null) {
                    $records = [];
                    break;
                }
                $records[] = $record;
            }
            if ($records !== [] || $cached === []) {
                return $records;
            }
        }
        $regions = $this->regions->listPublicActive();
        $this->cache->setRegions(array_map($this->toCache(...), $regions));
        return $regions;
    }

    /** @return array<string, mixed> */
    private function toCache(RegionRecord $region): array
    {
        return [
            'publicId' => $region->publicId, 'code' => $region->code, 'name' => $region->name,
            'displayName' => $region->displayName, 'flag' => $region->flag, 'themeColour' => $region->themeColour,
            'displayOrder' => $region->displayOrder, 'isDefault' => $region->isDefault,
            'isActive' => $region->isActive, 'websiteEnabled' => $region->websiteEnabled,
            'showInRegionPicker' => $region->showInRegionPicker,
            'defaultWebsiteRegion' => $region->defaultWebsiteRegion, 'countryCodes' => $region->countryCodes,
            'currencyCode' => $region->currencyCode, 'timezoneId' => $region->timezoneId,
            'languageCode' => $region->languageCode, 'office' => $region->office, 'phone' => $region->phone,
            'email' => $region->email, 'salesContact' => $region->salesContact,
            'updatedAtUtc' => $region->updatedAtUtc, 'countryCount' => $region->countryCount,
            'productCount' => $region->productUsageCount,
        ];
    }

    private function fromCache(mixed $item): ?RegionRecord
    {
        if (!is_array($item)) {
            return null;
        }
        foreach (['publicId', 'code', 'name', 'displayName'] as $field) {
            if (!is_string($item[$field] ?? null)) {
                return null;
            }
        }
        $countries = $item['countryCodes'] ?? null;
        if (!is_array($countries)) {
            return null;
        }
        $countryCodes = [];
        foreach ($countries as $country) {
            if (!is_string($country)) {
                return null;
            }
            $countryCodes[] = $country;
        }
        return new RegionRecord(
            publicId: $item['publicId'],
            code: $item['code'],
            name: $item['name'],
            displayName: $item['displayName'],
            flag: is_string($item['flag'] ?? null) ? $item['flag'] : null,
            themeColour: is_string($item['themeColour'] ?? null) ? $item['themeColour'] : null,
            displayOrder: is_int($item['displayOrder'] ?? null) ? $item['displayOrder'] : 0,
            isDefault: ($item['isDefault'] ?? false) === true,
            isActive: ($item['isActive'] ?? false) === true,
            websiteEnabled: ($item['websiteEnabled'] ?? false) === true,
            showInRegionPicker: ($item['showInRegionPicker'] ?? false) === true,
            defaultWebsiteRegion: ($item['defaultWebsiteRegion'] ?? false) === true,
            countryCodes: $countryCodes,
            countryCount: count($countryCodes),
            productUsageCount: is_int($item['productCount'] ?? null) ? $item['productCount'] : 0,
            updatedAtUtc: is_string($item['updatedAtUtc'] ?? null) ? $item['updatedAtUtc'] : null,
            rowVersion: '',
            currencyCode: is_string($item['currencyCode'] ?? null) ? $item['currencyCode'] : null,
            timezoneId: is_string($item['timezoneId'] ?? null) ? $item['timezoneId'] : null,
            languageCode: is_string($item['languageCode'] ?? null) ? $item['languageCode'] : null,
            office: is_string($item['office'] ?? null) ? $item['office'] : null,
            phone: is_string($item['phone'] ?? null) ? $item['phone'] : null,
            email: is_string($item['email'] ?? null) ? $item['email'] : null,
            salesContact: is_string($item['salesContact'] ?? null) ? $item['salesContact'] : null,
        );
    }

    /** @param array<array-key, mixed> $query */
    private function queryValue(array $query, string $key): ?string
    {
        return $this->normalize($query[$key] ?? null);
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtoupper(trim($value));
        return $value === '' ? null : $value;
    }
}
