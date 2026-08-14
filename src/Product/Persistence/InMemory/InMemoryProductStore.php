<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * In-memory product bounded-context persistence for USER_REPOSITORY=memory mode.
 * Backing store for all Product supporting repository contracts.
 */
final class InMemoryProductStore
{
    private readonly UuidV4Generator $uuids;

    /** @var list<array<string, mixed>> */
    private array $families = [];

    /** @var list<array<string, mixed>> */
    private array $categories = [];

    /** @var list<array<string, mixed>> */
    private array $series = [];

    /** @var list<array<string, mixed>> */
    private array $specDefinitions = [];

    /** @var list<array<string, mixed>> */
    private array $productSpecValues = [];

    /** @var list<array<string, mixed>> */
    private array $variantSpecValues = [];

    /** @var list<array<string, mixed>> */
    private array $media = [];

    /** @var list<array<string, mixed>> */
    private array $documents = [];

    /** @var array<string, array<string, mixed>> */
    private array $sets360 = [];

    /** @var array<string, array<string, mixed>> */
    private array $frames360 = [];

    /** @var array<string, array<string, mixed>> */
    private array $arModels = [];

    /** @var array<string, array<string, mixed>> */
    private array $threeDModels = [];

    /** @var array<string, array<string, mixed>> */
    private array $threeDAssets = [];

    /** @var array<string, array<string, mixed>> */
    private array $threeDAssetJobs = [];

    /** @var list<array<string, mixed>> */
    private array $regions = [];

    /** @var list<array<string, mixed>> */
    private array $markets = [];

    /** @var list<array<string, mixed>> */
    private array $industries = [];

    /** @var list<array<string, mixed>> */
    private array $applications = [];

    /** @var array<string, list<string>> */
    private array $productRegions = [];

    /** @var array<string, list<string>> */
    private array $productMarkets = [];

    /** @var array<string, list<string>> */
    private array $productIndustries = [];

    /** @var array<string, list<string>> */
    private array $productApplications = [];

    /** @var array<string, array<string, list<array<string, mixed>>>> */
    private array $relationships = [];

    /** @var array<string, array<string, mixed>> */
    private array $slugs = [];

    /** @var array<string, array<string, mixed>> */
    private array $seo = [];

    /** @var list<array<string, mixed>> */
    private array $redirects = [];

    /** @var list<array<string, mixed>> */
    private array $ruleSets = [];

    /** @var list<array<string, mixed>> */
    private array $productCodes = [];

    /** @var list<array<string, mixed>> */
    private array $prices = [];

    /** @var list<array<string, mixed>> */
    private array $auditEvents = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $integrationStatuses = [];

    /** @var array<string, array<string, mixed>> */
    private array $legacyMappings = [];

    /** @var list<array<string, mixed>> */
    private array $statuses = [];

    /** @var list<array<string, mixed>> */
    private array $lifecycleStatuses = [];

    /** @var list<array<string, mixed>> */
    private array $mediaTypes = [];

    /** @var list<array<string, mixed>> */
    private array $documentTypes = [];

    /** @var list<array<string, mixed>> */
    private array $currencies = [];

    /**
     * @var array<int, array{
     *   product: Product,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }>
     */
    private array $products = [];

    /**
     * @var array<int, array{
     *   variant: ProductVariant,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }>
     */
    private array $variants = [];

    private int $nextProductId = 1;

    private int $nextVariantId = 1;

    private int $nextClassificationId = 1;

    private int $rowVersionSequence = 1;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->uuids = new UuidV4Generator();
    }

    public function newPublicId(): string
    {
        return $this->uuids->generate()->toString();
    }

    public function nowUtc(): string
    {
        return $this->clock->now()->format(DATE_ATOM);
    }

    /** @return list<array<string, mixed>> */
    public function families(): array
    {
        return $this->families;
    }

    /** @param array<string, mixed> $row */
    public function addFamily(array $row): void
    {
        $this->families[] = $this->ensureClassificationId($row);
    }

    /** @return list<array<string, mixed>> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @param array<string, mixed> $row */
    public function addCategory(array $row): void
    {
        $this->categories[] = $this->ensureClassificationId($row);
    }

    /** @return list<array<string, mixed>> */
    public function series(): array
    {
        return $this->series;
    }

    /** @param array<string, mixed> $row */
    public function addSeries(array $row): void
    {
        $this->series[] = $this->ensureClassificationId($row);
    }


    /** @param array<string, mixed> $row */
    public function replaceFamily(string $publicId, array $row): void
    {
        foreach ($this->families as $index => $existing) {
            if (($existing['publicId'] ?? null) === $publicId) {
                $this->families[$index] = $this->ensureClassificationId($row);
                return;
            }
        }
        $this->families[] = $this->ensureClassificationId($row);
    }

    /** @param array<string, mixed> $row */
    public function replaceCategory(string $publicId, array $row): void
    {
        foreach ($this->categories as $index => $existing) {
            if (($existing['publicId'] ?? null) === $publicId) {
                $this->categories[$index] = $this->ensureClassificationId($row);
                return;
            }
        }
        $this->categories[] = $this->ensureClassificationId($row);
    }

    /** @param array<string, mixed> $row */
    public function replaceSeries(string $publicId, array $row): void
    {
        foreach ($this->series as $index => $existing) {
            if (($existing['publicId'] ?? null) === $publicId) {
                $this->series[$index] = $this->ensureClassificationId($row);
                return;
            }
        }
        $this->series[] = $this->ensureClassificationId($row);
    }


    /** @return list<array<string, mixed>> */
    public function specDefinitions(): array
    {
        return $this->specDefinitions;
    }

    /** @param array<string, mixed> $row */
    public function addSpecDefinition(array $row): void
    {
        $this->specDefinitions[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function productSpecValues(): array
    {
        return $this->productSpecValues;
    }

    /** @param list<array<string, mixed>> $values */
    public function setProductSpecValues(string $productPublicId, array $values): void
    {
        $this->productSpecValues = array_values(array_filter(
            $this->productSpecValues,
            static fn (array $row): bool => ($row['productPublicId'] ?? '') !== $productPublicId,
        ));
        foreach ($values as $value) {
            $this->productSpecValues[] = ['productPublicId' => $productPublicId, ...$value];
        }
    }

    /** @return list<array<string, mixed>> */
    public function variantSpecValues(): array
    {
        return $this->variantSpecValues;
    }

    /** @param list<array<string, mixed>> $values */
    public function setVariantSpecValues(string $variantPublicId, array $values): void
    {
        $this->variantSpecValues = array_values(array_filter(
            $this->variantSpecValues,
            static fn (array $row): bool => ($row['variantPublicId'] ?? '') !== $variantPublicId,
        ));
        foreach ($values as $value) {
            $this->variantSpecValues[] = ['variantPublicId' => $variantPublicId, ...$value];
        }
    }

    /** @return list<array<string, mixed>> */
    public function media(): array
    {
        return $this->media;
    }

    /** @param array<string, mixed> $row */
    public function addMedia(array $row): void
    {
        $publicId = (string) ($row['publicId'] ?? '');
        if ($publicId !== '') {
            foreach ($this->media as $index => $existing) {
                if (($existing['publicId'] ?? '') === $publicId) {
                    $this->media[$index] = $row;

                    return;
                }
            }
        }
        $this->media[] = $row;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    public function updateMedia(string $productPublicId, callable $mutator): void
    {
        foreach ($this->media as $index => $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId) {
                $this->media[$index] = $mutator($row);
            }
        }
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    public function updateMediaByPublicId(string $mediaPublicId, callable $mutator): bool
    {
        foreach ($this->media as $index => $row) {
            if (($row['publicId'] ?? '') === $mediaPublicId) {
                $this->media[$index] = $mutator($row);

                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    public function documents(): array
    {
        return $this->documents;
    }

    /** @param array<string, mixed> $row */
    public function addDocument(array $row): void
    {
        $publicId = (string) ($row['publicId'] ?? '');
        if ($publicId !== '') {
            foreach ($this->documents as $index => $existing) {
                if (($existing['publicId'] ?? '') === $publicId) {
                    $this->documents[$index] = $row;

                    return;
                }
            }
        }
        $this->documents[] = $row;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    public function updateDocumentByPublicId(string $documentPublicId, callable $mutator): bool
    {
        foreach ($this->documents as $index => $row) {
            if (($row['publicId'] ?? '') === $documentPublicId) {
                $this->documents[$index] = $mutator($row);

                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public function sets360Map(): array
    {
        return $this->sets360;
    }

    /** @param array<string, array<string, mixed>> $map */
    public function setSets360Map(array $map): void
    {
        $this->sets360 = $map;
    }

    /** @return array<string, array<string, mixed>> */
    public function frames360Map(): array
    {
        return $this->frames360;
    }

    /** @param array<string, array<string, mixed>> $map */
    public function setFrames360Map(array $map): void
    {
        $this->frames360 = $map;
    }

    /** @return array<string, array<string, mixed>> */
    public function arModelsMap(): array
    {
        return $this->arModels;
    }

    /** @param array<string, array<string, mixed>> $map */
    public function setArModelsMap(array $map): void
    {
        $this->arModels = $map;
    }

    /** @return array<string, array<string, mixed>> */
    public function threeDModelsMap(): array
    {
        return $this->threeDModels;
    }

    /** @param array<string, array<string, mixed>> $map */
    public function setThreeDModelsMap(array $map): void
    {
        $this->threeDModels = $map;
    }

    /** @return array<string, array<string, mixed>> */
    public function threeDAssetsMap(): array
    {
        return $this->threeDAssets;
    }

    /** @param array<string, array<string, mixed>> $map */
    public function setThreeDAssetsMap(array $map): void
    {
        $this->threeDAssets = $map;
    }

    /** @return array<string, array<string, mixed>> */
    public function threeDAssetJobsMap(): array
    {
        return $this->threeDAssetJobs;
    }

    /** @param array<string, array<string, mixed>> $map */
    public function setThreeDAssetJobsMap(array $map): void
    {
        $this->threeDAssetJobs = $map;
    }


    /** @return list<array<string, mixed>> */
    public function regions(): array
    {
        return $this->regions;
    }

    /** @param array<string, mixed> $row */
    public function addRegion(array $row): void
    {
        $this->regions[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function markets(): array
    {
        return $this->markets;
    }

    /** @param array<string, mixed> $row */
    public function addMarket(array $row): void
    {
        $this->markets[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function industries(): array
    {
        return $this->industries;
    }

    /** @param array<string, mixed> $row */
    public function addIndustry(array $row): void
    {
        $this->industries[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function applications(): array
    {
        return $this->applications;
    }

    /** @param array<string, mixed> $row */
    public function addApplication(array $row): void
    {
        $this->applications[] = $row;
    }

    /** @return list<string> */
    public function productRegions(string $productPublicId): array
    {
        return $this->productRegions[$productPublicId] ?? [];
    }

    /** @param list<string> $regionPublicIds */
    public function setProductRegions(string $productPublicId, array $regionPublicIds): void
    {
        $this->productRegions[$productPublicId] = $regionPublicIds;
    }

    /** @return list<string> */
    public function productMarkets(string $productPublicId): array
    {
        return $this->productMarkets[$productPublicId] ?? [];
    }

    /** @param list<string> $marketPublicIds */
    public function setProductMarkets(string $productPublicId, array $marketPublicIds): void
    {
        $this->productMarkets[$productPublicId] = $marketPublicIds;
    }

    /** @return list<string> */
    public function productIndustries(string $productPublicId): array
    {
        return $this->productIndustries[$productPublicId] ?? [];
    }

    /** @param list<string> $industryPublicIds */
    public function setProductIndustries(string $productPublicId, array $industryPublicIds): void
    {
        $this->productIndustries[$productPublicId] = $industryPublicIds;
    }

    /** @return list<string> */
    public function productApplications(string $productPublicId): array
    {
        return $this->productApplications[$productPublicId] ?? [];
    }

    /** @param list<string> $applicationPublicIds */
    public function setProductApplications(string $productPublicId, array $applicationPublicIds): void
    {
        $this->productApplications[$productPublicId] = $applicationPublicIds;
    }

    /** @return list<array<string, mixed>> */
    public function relationships(string $productPublicId, string $type): array
    {
        return $this->relationships[$productPublicId][$type] ?? [];
    }

    /** @param list<array<string, mixed>> $items */
    public function setRelationships(string $productPublicId, string $type, array $items): void
    {
        $this->relationships[$productPublicId][$type] = $items;
    }

    public function slugKey(string $productPublicId, ?string $regionPublicId): string
    {
        return $productPublicId . '|' . ($regionPublicId ?? '');
    }

    /** @return array<string, array<string, mixed>> */
    public function slugs(): array
    {
        return $this->slugs;
    }

    /** @param array<string, mixed> $row */
    public function setSlug(string $key, array $row): void
    {
        $this->slugs[$key] = $row;
    }

    /** @return array<string, array<string, mixed>> */
    public function seoEntries(): array
    {
        return $this->seo;
    }

    /** @param array<string, mixed> $row */
    public function setSeo(string $key, array $row): void
    {
        $this->seo[$key] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function redirects(): array
    {
        return $this->redirects;
    }

    /** @param array<string, mixed> $row */
    public function addRedirect(array $row): void
    {
        $this->redirects[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function ruleSets(): array
    {
        return $this->ruleSets;
    }

    /** @param array<string, mixed> $row */
    public function addRuleSet(array $row): void
    {
        $this->ruleSets[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function productCodes(): array
    {
        return $this->productCodes;
    }

    /** @param array<string, mixed> $row */
    public function addProductCode(array $row): void
    {
        $this->productCodes[] = $row;
    }

    public function clearPrimaryCodes(string $productPublicId): void
    {
        foreach ($this->productCodes as $index => $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId) {
                $this->productCodes[$index]['isPrimary'] = false;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function prices(): array
    {
        return $this->prices;
    }

    /** @param array<string, mixed> $row */
    public function upsertPrice(array $row): void
    {
        foreach ($this->prices as $index => $existing) {
            if (($existing['matchKey'] ?? '') === ($row['matchKey'] ?? '')) {
                $this->prices[$index] = [...$existing, ...$row];

                return;
            }
        }
        $this->prices[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function auditEvents(): array
    {
        return $this->auditEvents;
    }

    /** @param array<string, mixed> $row */
    public function addAuditEvent(array $row): void
    {
        $this->auditEvents[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function integrationStatuses(string $productPublicId): array
    {
        return $this->integrationStatuses[$productPublicId] ?? [];
    }

    /** @param list<array<string, mixed>> $statuses */
    public function setIntegrationStatuses(string $productPublicId, array $statuses): void
    {
        $this->integrationStatuses[$productPublicId] = $statuses;
    }

    public function legacyMappingKey(string $sourceSystemCode, string $sourceProductKey): string
    {
        return strtolower($sourceSystemCode) . '|' . $sourceProductKey;
    }

    /** @return array<string, array<string, mixed>> */
    public function legacyMappings(): array
    {
        return $this->legacyMappings;
    }

    /** @param array<string, mixed> $row */
    public function setLegacyMapping(string $key, array $row): void
    {
        $this->legacyMappings[$key] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function statuses(): array
    {
        return $this->statuses;
    }

    /** @param array<string, mixed> $row */
    public function addStatus(array $row): void
    {
        $this->statuses[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function lifecycleStatuses(): array
    {
        return $this->lifecycleStatuses;
    }

    /** @param array<string, mixed> $row */
    public function addLifecycleStatus(array $row): void
    {
        $this->lifecycleStatuses[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function mediaTypes(): array
    {
        return $this->mediaTypes;
    }

    /** @param array<string, mixed> $row */
    public function addMediaType(array $row): void
    {
        $this->mediaTypes[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function documentTypes(): array
    {
        return $this->documentTypes;
    }

    /** @param array<string, mixed> $row */
    public function addDocumentType(array $row): void
    {
        $this->documentTypes[] = $row;
    }

    /** @return list<array<string, mixed>> */
    public function currencies(): array
    {
        return $this->currencies;
    }

    /** @param array<string, mixed> $row */
    public function addCurrency(array $row): void
    {
        $this->currencies[] = $row;
    }

    public function nextRowVersion(): RowVersionToken
    {
        $binary = pack('J', $this->rowVersionSequence++);

        return RowVersionToken::fromBinary($binary);
    }

    public function allocateProductId(): ProductId
    {
        return ProductId::fromInt($this->nextProductId++);
    }

    public function allocateVariantId(): ProductVariantId
    {
        return ProductVariantId::fromInt($this->nextVariantId++);
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    /**
     * @return array<int, array{
     *   product: Product,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }>
     */
    public function productRecords(): array
    {
        return $this->products;
    }

    /**
     * @return array{
     *   product: Product,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }|null
     */
    public function productRecordByPublicId(ProductPublicId $publicId): ?array
    {
        foreach ($this->products as $record) {
            if ($record['product']->publicId()->equals($publicId)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   product: Product,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }|null
     */
    public function productRecordById(ProductId $productId): ?array
    {
        return $this->products[$productId->toInt()] ?? null;
    }

    /**
     * @param array{
     *   product: Product,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * } $record
     */
    public function saveProductRecord(ProductId $productId, array $record): void
    {
        $this->products[$productId->toInt()] = $record;
    }

    /**
     * @return array<int, array{
     *   variant: ProductVariant,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }>
     */
    public function variantRecords(): array
    {
        return $this->variants;
    }

    /**
     * @return array{
     *   variant: ProductVariant,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }|null
     */
    public function variantRecordByPublicId(ProductVariantPublicId $publicId): ?array
    {
        foreach ($this->variants as $record) {
            if ($record['variant']->publicId()->equals($publicId)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   variant: ProductVariant,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * }|null
     */
    public function variantRecordById(ProductVariantId $variantId): ?array
    {
        return $this->variants[$variantId->toInt()] ?? null;
    }

    /**
     * @param array{
     *   variant: ProductVariant,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * } $record
     */
    public function saveVariantRecord(ProductVariantId $variantId, array $record): void
    {
        $this->variants[$variantId->toInt()] = $record;
    }

    public function familyNameForId(?ProductFamilyId $familyId): ?string
    {
        if ($familyId === null) {
            return null;
        }

        foreach ($this->families as $row) {
            if (($row['id'] ?? 0) === $familyId->toInt()) {
                $name = $row['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        }

        return null;
    }

    public function familyPublicIdForId(?ProductFamilyId $familyId): ?string
    {
        if ($familyId === null) {
            return null;
        }

        foreach ($this->families as $row) {
            if (($row['id'] ?? 0) === $familyId->toInt()) {
                $publicId = $row['publicId'] ?? null;

                return is_string($publicId) ? $publicId : null;
            }
        }

        return null;
    }

    public function categoryNameForId(?ProductCategoryId $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        foreach ($this->categories as $row) {
            if (($row['id'] ?? 0) === $categoryId->toInt()) {
                $name = $row['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        }

        return null;
    }

    public function categoryPublicIdForId(?ProductCategoryId $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        foreach ($this->categories as $row) {
            if (($row['id'] ?? 0) === $categoryId->toInt()) {
                $publicId = $row['publicId'] ?? null;

                return is_string($publicId) ? $publicId : null;
            }
        }

        return null;
    }

    public function seriesNameForId(?ProductSeriesId $seriesId): ?string
    {
        if ($seriesId === null) {
            return null;
        }

        foreach ($this->series as $row) {
            if (($row['id'] ?? 0) === $seriesId->toInt()) {
                $name = $row['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        }

        return null;
    }

    public function seriesPublicIdForId(?ProductSeriesId $seriesId): ?string
    {
        if ($seriesId === null) {
            return null;
        }

        foreach ($this->series as $row) {
            if (($row['id'] ?? 0) === $seriesId->toInt()) {
                $publicId = $row['publicId'] ?? null;

                return is_string($publicId) ? $publicId : null;
            }
        }

        return null;
    }

    public function regionCodeForPublicId(string $regionPublicId): ?string
    {
        foreach ($this->regions as $row) {
            if (($row['publicId'] ?? '') === $regionPublicId) {
                $code = $row['code'] ?? null;

                return is_string($code) ? $code : null;
            }
        }

        return null;
    }

    public function industryNameForPublicId(string $industryPublicId): ?string
    {
        foreach ($this->industries as $row) {
            if (($row['publicId'] ?? '') === $industryPublicId) {
                $name = $row['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        }

        return null;
    }

    public function applicationNameForPublicId(string $applicationPublicId): ?string
    {
        foreach ($this->applications as $row) {
            if (($row['publicId'] ?? '') === $applicationPublicId) {
                $name = $row['name'] ?? null;

                return is_string($name) ? $name : null;
            }
        }

        return null;
    }

    public function familyPublicIdMatches(?ProductFamilyId $familyId, string $familyPublicId): bool
    {
        return $this->familyPublicIdForId($familyId) === $familyPublicId;
    }

    public function categoryPublicIdMatches(?ProductCategoryId $categoryId, string $categoryPublicId): bool
    {
        return $this->categoryPublicIdForId($categoryId) === $categoryPublicId;
    }

    public function seriesPublicIdMatches(?ProductSeriesId $seriesId, string $seriesPublicId): bool
    {
        return $this->seriesPublicIdForId($seriesId) === $seriesPublicId;
    }

    public function findProductPublicIdForMedia(string $mediaPublicId): ?string
    {
        foreach ($this->media as $row) {
            if (($row['publicId'] ?? '') !== $mediaPublicId || ($row['archived'] ?? false) === true) {
                continue;
            }

            $productPublicId = $row['productPublicId'] ?? null;

            return is_string($productPublicId) ? $productPublicId : null;
        }

        return null;
    }

    public function findProductPublicIdForDocument(string $documentPublicId): ?string
    {
        foreach ($this->documents as $row) {
            if (($row['publicId'] ?? '') !== $documentPublicId || ($row['archived'] ?? false) === true) {
                continue;
            }

            $productPublicId = $row['productPublicId'] ?? null;

            return is_string($productPublicId) ? $productPublicId : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function ensureClassificationId(array $row): array
    {
        if (!isset($row['id'])) {
            $row['id'] = $this->nextClassificationId++;
        }

        return $row;
    }
}
