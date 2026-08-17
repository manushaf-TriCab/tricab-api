<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Core\Product\Application\Command\UpsertRegionCommand;
use TriCab\Core\Product\Application\DTO\RegionListCriteria;

final class RegionRequestParser
{
    /** @param array<string, mixed> $query */
    public static function criteria(array $query): RegionListCriteria
    {
        $status = strtolower(QueryParams::string($query, 'status', ''));
        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            '', 'all' => QueryParams::boolOrNull($query, 'isActive'),
            default => throw HttpRequestValidationFailed::invalid('status must be active, inactive, or all.'),
        };
        return new RegionListCriteria(
            page: QueryParams::int($query, 'page', 1),
            pageSize: QueryParams::int($query, 'pageSize', 25),
            search: QueryParams::stringOrNull($query, 'search'),
            isActive: $isActive,
            isDefault: QueryParams::boolOrNull($query, 'isDefault'),
            websiteEnabled: QueryParams::boolOrNull($query, 'websiteEnabled'),
            showInRegionPicker: QueryParams::boolOrNull($query, 'showInRegionPicker'),
            includeDeleted: QueryParams::boolOrNull($query, 'includeDeleted') ?? false,
            sortBy: QueryParams::string($query, 'sort', 'displayOrder'),
            sortDirection: QueryParams::string($query, 'direction', 'asc'),
        );
    }

    /** @param array<string, mixed> $body */
    public static function upsert(array $body, ?string $publicId = null): UpsertRegionCommand
    {
        $code = self::requiredString($body, 'code');
        $name = self::requiredString($body, 'name');
        $countries = $body['countryCodes'] ?? [];
        if (!is_array($countries) || !array_is_list($countries)) {
            throw HttpRequestValidationFailed::invalid('countryCodes must be an array.');
        }
        $countryCodes = [];
        foreach ($countries as $country) {
            if (!is_string($country)) {
                throw HttpRequestValidationFailed::invalid('countryCodes must contain strings.');
            }
            $countryCodes[] = $country;
        }
        return new UpsertRegionCommand(
            code: $code,
            name: $name,
            displayName: self::nullableString($body, 'displayName'),
            flag: self::nullableString($body, 'flag'),
            themeColour: self::nullableString($body, 'themeColour'),
            displayOrder: self::integer($body, 'displayOrder', 0),
            isDefault: self::boolean($body, 'isDefault', false),
            isActive: self::boolean($body, 'isActive', true),
            websiteEnabled: self::boolean($body, 'websiteEnabled', true),
            showInRegionPicker: self::boolean($body, 'showInRegionPicker', true),
            defaultWebsiteRegion: self::boolean($body, 'defaultWebsiteRegion', false),
            countryCodes: $countryCodes,
            currencyCode: self::nullableString($body, 'currencyCode'),
            timezoneId: self::nullableString($body, 'timezoneId'),
            languageCode: self::nullableString($body, 'languageCode'),
            office: self::nullableString($body, 'office'),
            phone: self::nullableString($body, 'phone'),
            email: self::nullableString($body, 'email'),
            salesContact: self::nullableString($body, 'salesContact'),
            publicId: $publicId,
            expectedRowVersion: self::nullableString($body, 'expectedRowVersion'),
        );
    }

    /** @param array<string, mixed> $body */
    public static function expectedRowVersion(array $body): string
    {
        return self::requiredString($body, 'expectedRowVersion');
    }

    /** @param array<string, mixed> $body */
    private static function requiredString(array $body, string $key): string
    {
        $value = self::nullableString($body, $key);
        if ($value === null) {
            throw HttpRequestValidationFailed::invalid($key . ' is required.');
        }
        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw HttpRequestValidationFailed::invalid($key . ' must be a string.');
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $body */
    private static function boolean(array $body, string $key, bool $default): bool
    {
        $value = $body[$key] ?? $default;
        if (!is_bool($value)) {
            throw HttpRequestValidationFailed::invalid($key . ' must be a boolean.');
        }
        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function integer(array $body, string $key, int $default): int
    {
        $value = $body[$key] ?? $default;
        if (!is_int($value)) {
            throw HttpRequestValidationFailed::invalid($key . ' must be an integer.');
        }
        return $value;
    }
}
