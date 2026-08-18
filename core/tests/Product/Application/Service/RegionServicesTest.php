<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Command\RegionStatusCommand;
use TriCab\Core\Product\Application\Command\UpsertRegionCommand;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\RegionListCriteria;
use TriCab\Core\Product\Application\DTO\RegionListResult;
use TriCab\Core\Product\Application\DTO\RegionRecord;
use TriCab\Core\Product\Application\DTO\RegionUsage;
use TriCab\Core\Product\Application\DTO\RegionWriteData;
use TriCab\Core\Product\Application\Exception\DuplicateRegionCode;
use TriCab\Core\Product\Application\Exception\InvalidRegion;
use TriCab\Core\Product\Application\Exception\RegionInUse;
use TriCab\Core\Product\Application\Service\GetRegionsService;
use TriCab\Core\Product\Application\Service\ManageRegionService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Contract\RegionRepository;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class RegionServicesTest extends TestCase
{
    private InMemoryRegionRepository $repository;
    private ManageRegionService $manage;
    private GetRegionsService $get;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->repository = new InMemoryRegionRepository();
        $store = new InMemoryProductStore();
        $this->manage = new ManageRegionService(
            $this->repository,
            new PassthroughApplicationTransaction(),
            new ProductAuditRecorder($store->audit()),
        );
        $this->get = new GetRegionsService($this->repository);
        $this->actor = new ActorContext(1, 'actor-1', 'admin@example.test');
    }

    public function test_create_normalizes_and_exposes_future_fields(): void
    {
        $region = $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'au',
            name: ' Australia ',
            displayName: 'Australia',
            flag: '🇦🇺',
            themeColour: '#0057B8',
            displayOrder: 10,
            isDefault: true,
            defaultWebsiteRegion: true,
            countryCodes: ['au', 'AU'],
            currencyCode: 'aud',
            timezoneId: 'Australia/Sydney',
            languageCode: 'en-AU',
            office: 'Melbourne',
            phone: '+61 3 0000 0000',
            email: 'sales@example.test',
            salesContact: 'Regional Sales',
        ));

        self::assertSame('AU', $region->code);
        self::assertSame('Australia', $region->name);
        self::assertSame(['AU'], $region->countryCodes);
        self::assertSame('AUD', $region->currencyCode);
        self::assertTrue($region->isDefault);
        self::assertTrue($region->defaultWebsiteRegion);
    }

    public function test_rejects_invalid_code_and_invalid_default_state(): void
    {
        try {
            $this->manage->create($this->actor, new UpsertRegionCommand(code: 'A', name: 'Short'));
            self::fail('Expected invalid code.');
        } catch (InvalidRegion) {
            self::assertCount(0, $this->repository->records);
        }

        $this->expectException(InvalidRegion::class);
        $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'NZ',
            name: 'New Zealand',
            isDefault: true,
            isActive: false,
        ));
    }

    public function test_rejects_duplicate_code_and_second_defaults(): void
    {
        $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'AU',
            name: 'Australia',
            isDefault: true,
            defaultWebsiteRegion: true,
        ));

        try {
            $this->manage->create($this->actor, new UpsertRegionCommand(code: 'au', name: 'Duplicate'));
            self::fail('Expected duplicate code.');
        } catch (DuplicateRegionCode) {
            self::assertCount(1, $this->repository->records);
        }

        $this->expectException(InvalidRegion::class);
        $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'NZ',
            name: 'New Zealand',
            isDefault: true,
        ));
    }

    public function test_delete_is_blocked_by_each_usage_category(): void
    {
        $region = $this->manage->create(
            $this->actor,
            new UpsertRegionCommand(code: 'US', name: 'United States'),
        );
        $this->repository->usages[$region->publicId] = new RegionUsage(2, 1, 3, 4);

        try {
            $this->manage->delete($this->actor, new RegionStatusCommand($region->publicId, $region->rowVersion));
            self::fail('Expected in-use failure.');
        } catch (RegionInUse $exception) {
            self::assertSame(10, $exception->usage->total());
        }

        $this->repository->usages[$region->publicId] = new RegionUsage();
        $deleted = $this->manage->delete(
            $this->actor,
            new RegionStatusCommand($region->publicId, $region->rowVersion),
        );
        self::assertTrue($deleted->isDeleted);
    }

    public function test_default_cannot_be_deactivated_or_deleted(): void
    {
        $region = $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'AU',
            name: 'Australia',
            isDefault: true,
        ));

        $this->expectException(InvalidRegion::class);
        $this->manage->deactivate(
            $this->actor,
            new RegionStatusCommand($region->publicId, $region->rowVersion),
        );
    }

    public function test_public_and_admin_queries_apply_contract_shape(): void
    {
        $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'NZ',
            name: 'New Zealand',
            displayOrder: 20,
        ));
        $hidden = $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'APAC',
            name: 'Asia Pacific',
            displayOrder: 30,
            websiteEnabled: false,
            showInRegionPicker: false,
        ));
        $au = $this->manage->create($this->actor, new UpsertRegionCommand(
            code: 'AU',
            name: 'Australia',
            displayOrder: 10,
        ));

        self::assertSame(['AU', 'NZ'], array_map(
            static fn (RegionRecord $region): string => $region->code,
            $this->get->publicActive(),
        ));

        $page = $this->get->adminList(new RegionListCriteria(
            page: 1,
            pageSize: 1,
            search: 'a',
            sortBy: 'code',
            sortDirection: 'desc',
        ));
        self::assertSame(1, count($page->items));
        self::assertSame(3, $page->pagination->totalItems);
        self::assertSame($hidden->publicId, $this->get->get($hidden->publicId)->publicId);
        self::assertSame($au->publicId, $this->get->get($au->publicId)->publicId);
    }
}

final class InMemoryRegionRepository implements RegionRepository
{
    /** @var array<string, RegionRecord> */
    public array $records = [];

    /** @var array<string, RegionUsage> */
    public array $usages = [];

    private int $sequence = 0;

    public function listPublicActive(): array
    {
        $items = array_values(array_filter(
            $this->records,
            static fn (RegionRecord $region): bool => !$region->isDeleted
                && $region->isActive
                && $region->websiteEnabled
                && $region->showInRegionPicker,
        ));
        usort($items, static fn (RegionRecord $a, RegionRecord $b): int => [$a->displayOrder, $a->name] <=> [$b->displayOrder, $b->name]);

        return $items;
    }

    public function listAdmin(RegionListCriteria $criteria): RegionListResult
    {
        $search = strtolower((string) $criteria->normalizedSearch());
        $items = array_values(array_filter(
            $this->records,
            static function (RegionRecord $region) use ($criteria, $search): bool {
                if (!$criteria->includeDeleted && $region->isDeleted) {
                    return false;
                }
                if ($criteria->isActive !== null && $region->isActive !== $criteria->isActive) {
                    return false;
                }
                if ($criteria->isDefault !== null && $region->isDefault !== $criteria->isDefault) {
                    return false;
                }

                return $search === ''
                    || str_contains(strtolower($region->code), $search)
                    || str_contains(strtolower($region->name), $search)
                    || str_contains(strtolower($region->displayName), $search);
            },
        ));
        $property = $criteria->sortBy === 'updatedAt' ? 'updatedAtUtc' : $criteria->sortBy;
        usort($items, static function (RegionRecord $a, RegionRecord $b) use ($criteria, $property): int {
            $comparison = $a->{$property} <=> $b->{$property};

            return $criteria->normalizedSortDirection() === 'desc' ? -$comparison : $comparison;
        });
        $total = count($items);
        $items = array_slice($items, ($criteria->page - 1) * $criteria->pageSize, $criteria->pageSize);

        return new RegionListResult(
            $items,
            Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function findByPublicId(string $publicId, bool $includeDeleted = false): ?RegionRecord
    {
        $region = $this->records[$publicId] ?? null;
        if ($region !== null && $region->isDeleted && !$includeDeleted) {
            return null;
        }

        return $region;
    }

    public function codeExists(string $code, ?string $excludePublicId = null): bool
    {
        foreach ($this->records as $region) {
            if (!$region->isDeleted && $region->publicId !== $excludePublicId && $region->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function anotherDefaultExists(?string $excludePublicId = null): bool
    {
        return $this->hasDefault($excludePublicId, false);
    }

    public function anotherDefaultWebsiteRegionExists(?string $excludePublicId = null): bool
    {
        return $this->hasDefault($excludePublicId, true);
    }

    public function create(RegionWriteData $data, UserId $actorUserId): RegionRecord
    {
        ++$this->sequence;
        $record = $this->record('region-'.$this->sequence, $data, 'v1');
        $this->records[$record->publicId] = $record;

        return $record;
    }

    public function update(
        string $publicId,
        RegionWriteData $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): RegionRecord {
        $record = $this->record($publicId, $data, $this->nextVersion($expectedRowVersion));
        $this->records[$publicId] = $record;

        return $record;
    }

    public function setActive(
        string $publicId,
        bool $isActive,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): RegionRecord {
        $old = $this->records[$publicId];
        $data = $this->data($old, $isActive);

        return $this->update($publicId, $data, $expectedRowVersion, $actorUserId);
    }

    public function softDelete(string $publicId, string $expectedRowVersion, UserId $actorUserId): RegionRecord
    {
        $old = $this->records[$publicId];
        $record = $this->record($publicId, $this->data($old), $this->nextVersion($expectedRowVersion), true);
        $this->records[$publicId] = $record;

        return $record;
    }

    public function usage(string $publicId): RegionUsage
    {
        return $this->usages[$publicId] ?? new RegionUsage();
    }

    private function hasDefault(?string $excludePublicId, bool $website): bool
    {
        foreach ($this->records as $region) {
            $isDefault = $website ? $region->defaultWebsiteRegion : $region->isDefault;
            if (!$region->isDeleted && $region->publicId !== $excludePublicId && $isDefault) {
                return true;
            }
        }

        return false;
    }

    private function record(string $publicId, RegionWriteData $data, string $version, bool $deleted = false): RegionRecord
    {
        return new RegionRecord(
            publicId: $publicId,
            code: $data->code,
            name: $data->name,
            displayName: $data->displayName,
            flag: $data->flag,
            themeColour: $data->themeColour,
            displayOrder: $data->displayOrder,
            isDefault: $data->isDefault,
            isActive: $data->isActive,
            websiteEnabled: $data->websiteEnabled,
            showInRegionPicker: $data->showInRegionPicker,
            defaultWebsiteRegion: $data->defaultWebsiteRegion,
            countryCodes: $data->countryCodes,
            countryCount: count($data->countryCodes),
            productUsageCount: ($this->usages[$publicId] ?? new RegionUsage())->productRegionCount,
            updatedAtUtc: '2026-08-17T00:00:00Z',
            rowVersion: $version,
            isDeleted: $deleted,
            currencyCode: $data->currencyCode,
            timezoneId: $data->timezoneId,
            languageCode: $data->languageCode,
            office: $data->office,
            phone: $data->phone,
            email: $data->email,
            salesContact: $data->salesContact,
        );
    }

    private function data(RegionRecord $record, ?bool $active = null): RegionWriteData
    {
        return new RegionWriteData(
            $record->code,
            $record->name,
            $record->displayName,
            $record->flag,
            $record->themeColour,
            $record->displayOrder,
            $record->isDefault,
            $active ?? $record->isActive,
            $record->websiteEnabled,
            $record->showInRegionPicker,
            $record->defaultWebsiteRegion,
            $record->countryCodes,
            $record->currencyCode,
            $record->timezoneId,
            $record->languageCode,
            $record->office,
            $record->phone,
            $record->email,
            $record->salesContact,
        );
    }

    private function nextVersion(string $expected): string
    {
        return 'v'.((int) substr($expected, 1) + 1);
    }
}
