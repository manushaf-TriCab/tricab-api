<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\RegionListCriteria;
use TriCab\Core\Product\Application\DTO\RegionListResult;
use TriCab\Core\Product\Application\DTO\RegionRecord;
use TriCab\Core\Product\Application\DTO\RegionUsage;
use TriCab\Core\Product\Application\DTO\RegionWriteData;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\RegionNotFound;
use TriCab\Core\Product\Contract\RegionRepository;

/**
 * @phpstan-type RegionRow array{
 *   publicId: string, code: string, name: string, displayName: string,
 *   flag: ?string, themeColour: ?string, displayOrder: int, isDefault: bool,
 *   isActive: bool, websiteEnabled: bool, showInRegionPicker: bool,
 *   defaultWebsiteRegion: bool, countryCodes: list<string>, currencyCode: ?string,
 *   timezoneId: ?string, languageCode: ?string, office: ?string, phone: ?string,
 *   email: ?string, salesContact: ?string, isDeleted: bool, updatedAtUtc: string,
 *   rowVersion: string
 * }
 */
final class InMemoryRegionRepository implements RegionRepository
{
    /** @var array<string, RegionRow> */
    private array $rows = [];

    private int $version = 1;

    public function __construct(
        private readonly InMemoryProductStore $products,
    ) {
        $this->seed();
    }

    public function listPublicActive(): array
    {
        $items = array_values(array_filter(
            $this->records(),
            static fn (RegionRecord $r): bool => !$r->isDeleted
                && $r->isActive
                && $r->websiteEnabled
                && $r->showInRegionPicker,
        ));
        usort($items, static fn (RegionRecord $a, RegionRecord $b): int =>
            $a->displayOrder <=> $b->displayOrder ?: strcmp($a->displayName, $b->displayName));

        return $items;
    }

    public function listAdmin(RegionListCriteria $criteria): RegionListResult
    {
        $items = array_values(array_filter($this->records(), static function (RegionRecord $r) use ($criteria): bool {
            if (!$criteria->includeDeleted && $r->isDeleted) {
                return false;
            }
            if ($criteria->isActive !== null && $r->isActive !== $criteria->isActive) {
                return false;
            }
            if ($criteria->isDefault !== null && $r->isDefault !== $criteria->isDefault) {
                return false;
            }
            if ($criteria->websiteEnabled !== null && $r->websiteEnabled !== $criteria->websiteEnabled) {
                return false;
            }
            if ($criteria->showInRegionPicker !== null && $r->showInRegionPicker !== $criteria->showInRegionPicker) {
                return false;
            }
            $search = $criteria->normalizedSearch();
            return $search === null || str_contains(
                strtolower($r->code . ' ' . $r->name . ' ' . $r->displayName),
                strtolower($search),
            );
        }));
        $sort = $criteria->sortBy;
        usort($items, static function (RegionRecord $a, RegionRecord $b) use ($sort, $criteria): int {
            $left = match ($sort) {
                'code' => $a->code,
                'name' => $a->name,
                'displayName' => $a->displayName,
                'isDefault' => $a->isDefault,
                'isActive' => $a->isActive,
                'updatedAt' => $a->updatedAtUtc ?? '',
                default => $a->displayOrder,
            };
            $right = match ($sort) {
                'code' => $b->code,
                'name' => $b->name,
                'displayName' => $b->displayName,
                'isDefault' => $b->isDefault,
                'isActive' => $b->isActive,
                'updatedAt' => $b->updatedAtUtc ?? '',
                default => $b->displayOrder,
            };
            $cmp = $left <=> $right;
            return $criteria->normalizedSortDirection() === 'desc' ? -$cmp : $cmp;
        });
        $total = count($items);
        $items = array_slice($items, ($criteria->page - 1) * $criteria->pageSize, $criteria->pageSize);

        return new RegionListResult($items, Pagination::fromTotals($criteria->page, $criteria->pageSize, $total));
    }

    public function findByPublicId(string $publicId, bool $includeDeleted = false): ?RegionRecord
    {
        $row = $this->rows[$publicId] ?? null;
        if ($row === null || (!$includeDeleted && $row['isDeleted'])) {
            return null;
        }

        return $this->record($row);
    }

    public function codeExists(string $code, ?string $excludePublicId = null): bool
    {
        foreach ($this->rows as $id => $row) {
            if ($id !== $excludePublicId && strcasecmp($row['code'], $code) === 0) {
                return true;
            }
        }
        return false;
    }

    public function anotherDefaultExists(?string $excludePublicId = null): bool
    {
        return $this->hasFlag('isDefault', $excludePublicId);
    }

    public function anotherDefaultWebsiteRegionExists(?string $excludePublicId = null): bool
    {
        return $this->hasFlag('defaultWebsiteRegion', $excludePublicId);
    }

    public function create(RegionWriteData $data, UserId $actorUserId): RegionRecord
    {
        unset($actorUserId);
        $id = (new UuidV4Generator())->generate()->toString();
        $this->rows[$id] = $this->row($id, $data);
        $this->syncProductLookup($this->rows[$id]);
        return $this->record($this->rows[$id]);
    }

    public function update(string $publicId, RegionWriteData $data, string $expectedRowVersion, UserId $actorUserId): RegionRecord
    {
        unset($actorUserId);
        $this->assertVersion($publicId, $expectedRowVersion);
        $this->rows[$publicId] = $this->row($publicId, $data);
        $this->syncProductLookup($this->rows[$publicId]);
        return $this->record($this->rows[$publicId]);
    }

    public function setActive(
        string $publicId,
        bool $isActive,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): RegionRecord {
        unset($actorUserId);
        $this->assertVersion($publicId, $expectedRowVersion);
        $row = $this->rows[$publicId];
        $row['isActive'] = $isActive;
        $this->rows[$publicId] = $row;
        $this->touch($publicId);
        $this->syncProductLookup($this->rows[$publicId]);
        return $this->record($this->rows[$publicId]);
    }

    public function softDelete(string $publicId, string $expectedRowVersion, UserId $actorUserId): RegionRecord
    {
        unset($actorUserId);
        $this->assertVersion($publicId, $expectedRowVersion);
        $row = $this->rows[$publicId];
        $row['isDeleted'] = true;
        $row['isActive'] = false;
        $this->rows[$publicId] = $row;
        $this->touch($publicId);
        $this->syncProductLookup($this->rows[$publicId]);
        return $this->record($this->rows[$publicId]);
    }

    public function usage(string $publicId): RegionUsage
    {
        $productCount = 0;
        foreach ($this->products->productPublicIds() as $productPublicId) {
            if (in_array($publicId, $this->products->productRegions($productPublicId), true)) {
                ++$productCount;
            }
        }
        $documentCount = 0;
        foreach ($this->products->documents() as $document) {
            if (($document['regionPublicId'] ?? null) === $publicId && !($document['archived'] ?? false)) {
                ++$documentCount;
            }
        }
        return new RegionUsage($productCount, 0, $documentCount, 0);
    }

    /** @return list<RegionRecord> */
    private function records(): array
    {
        return array_map(fn (array $row): RegionRecord => $this->record($row), array_values($this->rows));
    }

    /** @param RegionRow $row */
    private function record(array $row): RegionRecord
    {
        $countries = $row['countryCodes'];
        return new RegionRecord(
            publicId: $row['publicId'],
            code: $row['code'],
            name: $row['name'],
            displayName: $row['displayName'],
            flag: $row['flag'],
            themeColour: $row['themeColour'],
            displayOrder: $row['displayOrder'],
            isDefault: $row['isDefault'],
            isActive: $row['isActive'],
            websiteEnabled: $row['websiteEnabled'],
            showInRegionPicker: $row['showInRegionPicker'],
            defaultWebsiteRegion: $row['defaultWebsiteRegion'],
            countryCodes: $countries,
            countryCount: count($countries),
            productUsageCount: $this->usage($row['publicId'])->productRegionCount,
            updatedAtUtc: $row['updatedAtUtc'],
            rowVersion: $row['rowVersion'],
            isDeleted: $row['isDeleted'],
            currencyCode: $row['currencyCode'],
            timezoneId: $row['timezoneId'],
            languageCode: $row['languageCode'],
            office: $row['office'],
            phone: $row['phone'],
            email: $row['email'],
            salesContact: $row['salesContact'],
        );
    }

    /** @return RegionRow */
    private function row(string $id, RegionWriteData $data): array
    {
        return [
            'publicId' => $id, 'code' => $data->code, 'name' => $data->name,
            'displayName' => $data->displayName, 'flag' => $data->flag, 'themeColour' => $data->themeColour,
            'displayOrder' => $data->displayOrder, 'isDefault' => $data->isDefault, 'isActive' => $data->isActive,
            'websiteEnabled' => $data->websiteEnabled, 'showInRegionPicker' => $data->showInRegionPicker,
            'defaultWebsiteRegion' => $data->defaultWebsiteRegion, 'countryCodes' => $data->countryCodes,
            'currencyCode' => $data->currencyCode, 'timezoneId' => $data->timezoneId,
            'languageCode' => $data->languageCode, 'office' => $data->office, 'phone' => $data->phone,
            'email' => $data->email, 'salesContact' => $data->salesContact, 'isDeleted' => false,
            'updatedAtUtc' => gmdate(DATE_ATOM), 'rowVersion' => $this->nextVersion(),
        ];
    }

    private function assertVersion(string $id, string $expected): void
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['isDeleted']) {
            throw RegionNotFound::forPublicId($id);
        }
        if (!hash_equals($this->rows[$id]['rowVersion'], $expected)) {
            throw ProductConcurrencyConflict::forPublicId($id);
        }
    }

    private function touch(string $id): void
    {
        $row = $this->rows[$id];
        $row['updatedAtUtc'] = gmdate(DATE_ATOM);
        $row['rowVersion'] = $this->nextVersion();
        $this->rows[$id] = $row;
    }

    private function nextVersion(): string
    {
        return base64_encode(pack('J', $this->version++));
    }

    private function hasFlag(string $flag, ?string $exclude): bool
    {
        foreach ($this->rows as $id => $row) {
            $enabled = $flag === 'isDefault' ? $row['isDefault'] : $row['defaultWebsiteRegion'];
            if ($id !== $exclude && !$row['isDeleted'] && $enabled) {
                return true;
            }
        }
        return false;
    }

    private function seed(): void
    {
        foreach ([
            ['a0000000-0000-4000-8000-000000000001', 'AU', 'Australia', '🇦🇺', 'AUD', 'Australia/Sydney', 'en-AU'],
            ['b0000000-0000-4000-8000-000000000002', 'NZ', 'New Zealand', '🇳🇿', 'NZD', 'Pacific/Auckland', 'en-NZ'],
            ['c0000000-0000-4000-8000-000000000003', 'US', 'United States', '🇺🇸', 'USD', 'America/New_York', 'en-US'],
            ['d0000000-0000-4000-8000-000000000004', 'AS', 'American Samoa', '🇦🇸', 'USD', 'Pacific/Pago_Pago', 'en-AS'],
        ] as $index => [$id, $code, $name, $flag, $currency, $timezone, $language]) {
            $data = new RegionWriteData(
                $code, $name, $name, $flag, null, ($index + 1) * 10,
                $index === 0, true, true, true, $index === 0, [$code],
                $currency, $timezone, $language, null, null, null, null,
            );
            $this->rows[$id] = $this->row($id, $data);
            $this->syncProductLookup($this->rows[$id]);
        }
    }

    /** @param RegionRow $row */
    private function syncProductLookup(array $row): void
    {
        $this->products->upsertRegion([
            'publicId' => $row['publicId'],
            'code' => $row['code'],
            'name' => $row['name'],
            'displayOrder' => $row['displayOrder'],
            'isActive' => $row['isActive'] && !$row['isDeleted'],
        ]);
    }
}
