<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\ProductAuditEntry;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Application\Exception\ProductClassificationNotFound;
use TriCab\Core\Product\Application\Exception\ProductClassificationConcurrencyConflict;
use TriCab\Core\Product\Application\DTO\ProductClassificationUsage;
use TriCab\Core\Product\Application\DTO\ProductClassificationRecord;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Application\Exception\DuplicateVariantSku;
use TriCab\Core\Product\Application\Exception\InvalidProductClassification;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductAuditRepository;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Contract\ProductCodeRuleRepository;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Contract\ProductRelationshipRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Contract\ProductSeoRepository;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\CurrencyCode;
use TriCab\Core\Product\Domain\ValueObject\Money;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\Sku;

/**
 * @phpstan-import-type SpecDefinition from ProductSpecificationRepository
 * @phpstan-import-type SpecValue from ProductSpecificationRepository
 * @phpstan-import-type RelationshipItem from ProductRelationshipRepository
 * @phpstan-import-type SeoPayload from ProductSeoRepository
 *
 * Hand-written in-memory facade + nested repository adapters for Product
 * Application service tests. Mirrors the tricab-api InMemory* pattern but
 * lives in tricab-core so core tests do not depend on the API package.
 */
final class InMemoryProductStore
{
    /** @var array<int, Product> */
    private array $products = [];

    /** @var array<string, int> publicId => internal id */
    private array $productPublicIdToId = [];

    /** @var array<string, string> productPublicId => createdAtUtc */
    private array $productCreatedAt = [];

    /** @var array<string, string> normalized code => productPublicId */
    private array $productCodes = [];

    private int $nextProductId = 1;

    /** @var array<int, ProductVariant> */
    private array $variants = [];

    /** @var array<string, int> */
    private array $variantPublicIdToId = [];

    private int $nextVariantId = 1;

    private int $rowVersionCounter = 0;

    /** @var list<ProductAuditEntry> */
    private array $auditEntries = [];

    /** @var list<array{id:int, publicId:string, code:string, name:string, isActive:bool}> */
    private array $families = [];

    /** @var list<array{id:int, publicId:string, code:string, name:string, familyPublicId:?string, isActive:bool}> */
    private array $categories = [];

    /** @var list<array{id:int, publicId:string, code:string, name:string, categoryPublicId:?string, isActive:bool}> */
    private array $series = [];

    private int $nextClassificationId = 1;

    /** @var array<string, list<array<string, mixed>>> productPublicId => media rows */
    private array $media = [];

    /** @var array<string, list<array<string, mixed>>> productPublicId => document rows */
    private array $documents = [];

    /** @var array<string, array<string, mixed>> mediaPublicId => row */
    private array $mediaByPublicId = [];

    /** @var array<string, array<string, mixed>> documentPublicId => row */
    private array $documentsByPublicId = [];

    /** @var array<string, array<string, mixed>> setPublicId => 360 set row */
    private array $sets360 = [];

    /** @var array<string, array<string, mixed>> framePublicId => frame row */
    private array $frames360 = [];

    /** @var array<string, array<string, mixed>> arPublicId => ar row */
    private array $arModels = [];

    /** @var list<ProductLookupItem> */
    private array $regions = [];

    /** @var list<ProductLookupItem> */
    private array $markets = [];

    /** @var list<ProductLookupItem> */
    private array $industries = [];

    /** @var list<ProductLookupItem> */
    private array $applications = [];

    /** @var array<string, list<string>> productPublicId => region public ids */
    private array $productRegions = [];

    /** @var array<string, list<string>> */
    private array $productMarkets = [];

    /** @var array<string, list<string>> */
    private array $productIndustries = [];

    /** @var array<string, list<string>> */
    private array $productApplications = [];

    /** @var array<string, list<array<string, mixed>>> "productPublicId|type" => items */
    private array $relationships = [];

    /** @var array<string, array{slug: string, isPrimary: bool}> "productPublicId|region" */
    private array $slugs = [];

    /** @var array<string, array<string, mixed>> "productPublicId|region" */
    private array $seo = [];

    /** @var list<array<string, mixed>> */
    private array $redirects = [];

    /** @var array<string, list<ProductPricingSummary>> */
    private array $pricing = [];

    /** @var list<array<string, mixed>> */
    private array $specDefinitions = [];
    private ?InMemorySpecificationRepository $specificationRepository = null;

    /** @var array<string, list<array<string, mixed>>> */
    private array $productSpecValues = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $variantSpecValues = [];

    public function products(): ProductRepository
    {
        return new InMemoryFakeProductRepository($this);
    }

    public function variants(): ProductVariantRepository
    {
        return new InMemoryFakeProductVariantRepository($this);
    }

    public function audit(): ProductAuditRepository
    {
        return new InMemoryFakeProductAuditRepository($this);
    }

    public function classification(): ProductClassificationRepository
    {
        return new InMemoryFakeProductClassificationRepository($this);
    }

    public function adminQuery(): ProductAdminQueryRepository
    {
        return new InMemoryFakeProductAdminQueryRepository($this);
    }

    public function media(): ProductMediaRepository
    {
        return new InMemoryFakeProductMediaRepository($this);
    }

    public function documents(): ProductDocumentRepository
    {
        return new InMemoryFakeProductDocumentRepository($this);
    }

    public function sets360(): Product360Repository
    {
        return new InMemoryFakeProduct360Repository($this);
    }

    public function arModels(): ProductArModelRepository
    {
        return new InMemoryFakeProductArModelRepository($this);
    }

    public function markets(): ProductMarketRepository
    {
        return new InMemoryFakeProductMarketRepository($this);
    }

    public function relationships(): ProductRelationshipRepository
    {
        return new InMemoryFakeProductRelationshipRepository($this);
    }

    public function seo(): ProductSeoRepository
    {
        return new InMemoryFakeProductSeoRepository($this);
    }

    public function pricing(): ProductPricingRepository
    {
        return new InMemoryFakeProductPricingRepository($this);
    }

    public function codeRules(): ProductCodeRuleRepository
    {
        return new InMemoryFakeProductCodeRuleRepository($this);
    }

    public function specifications(): ProductSpecificationRepository
    {
        return $this->specificationRepository ??= new InMemorySpecificationRepository();
    }

    // --- Test setup helpers -------------------------------------------------

    /** @return array{id: int, publicId: string} */
    public function seedFamily(string $publicId, string $code = 'FAM', string $name = 'Family', bool $isActive = true): array
    {
        $id = $this->nextClassificationId++;
        $this->families[] = ['id' => $id, 'publicId' => $publicId, 'code' => $code, 'name' => $name, 'isActive' => $isActive];

        return ['id' => $id, 'publicId' => $publicId];
    }

    public function seedRegion(string $publicId, string $code, string $name, bool $isActive = true): void
    {
        $this->regions[] = new ProductLookupItem($publicId, $code, $name, null, 0, $isActive);
    }

    public function assignRegion(string $productPublicId, string $regionPublicId): void
    {
        $this->productRegions[$productPublicId][] = $regionPublicId;
    }

    public function seedSpecDefinition(
        string $publicId,
        string $definitionCode,
        string $definitionName,
        string $dataTypeCode,
        string $scopeCode,
        bool $isRequired = false,
        ?string $unit = null,
        int $displayOrder = 0,
    ): void {
        $repo = $this->specifications();
        assert($repo instanceof InMemorySpecificationRepository);
        $created = $repo->createDefinition([
            'publicId' => $publicId,
            'code' => $definitionCode,
            'name' => $definitionName,
            'dataTypeCode' => $dataTypeCode,
            'scopeCode' => $scopeCode,
            'unit' => $unit,
            'isRequired' => $isRequired,
            'displayOrder' => $displayOrder,
            'isActive' => true,
            'validationRulesJson' => null,
        ], \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1));
        $this->specDefinitions[] = [
            'publicId' => $created->publicId,
            'groupCode' => null,
            'definitionCode' => $definitionCode,
            'definitionName' => $definitionName,
            'dataTypeCode' => $dataTypeCode,
            'scopeCode' => $scopeCode,
            'unit' => $unit,
            'isRequired' => $isRequired,
            'displayOrder' => $displayOrder,
            'options' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function redirects(): array
    {
        return $this->redirects;
    }

    /** @return list<ProductAuditEntry> */
    public function auditEntries(): array
    {
        return $this->auditEntries;
    }

    // --- Internal accessors used by the nested repository adapters ---------

    /** @internal */
    public function nextRowVersion(): RowVersionToken
    {
        return RowVersionToken::fromBinary(pack('J', ++$this->rowVersionCounter));
    }

    /** @internal */
    public function allProducts(): array
    {
        return $this->products;
    }

    /** @internal */
    public function findProductById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    /** @internal */
    public function findProductIdByPublicId(string $publicId): ?int
    {
        return $this->productPublicIdToId[$publicId] ?? null;
    }

    /** @internal */
    public function storeProduct(int $id, Product $product): void
    {
        $this->products[$id] = $product;
        $this->productPublicIdToId[$product->publicId()->toString()] = $id;
        if (!isset($this->productCreatedAt[$product->publicId()->toString()])) {
            $this->productCreatedAt[$product->publicId()->toString()] = (new DateTimeImmutable())->format(DATE_ATOM);
        }
    }

    /** @internal */
    public function productCreatedAt(string $publicId): string
    {
        return $this->productCreatedAt[$publicId] ?? (new DateTimeImmutable())->format(DATE_ATOM);
    }

    /** @internal */
    public function nextProductId(): int
    {
        return $this->nextProductId++;
    }

    /** @internal */
    public function setProductCode(string $productPublicId, string $code): void
    {
        $this->productCodes[strtolower($code)] = $productPublicId;
    }

    /** @internal */
    public function productCodeExists(string $code, ?string $exceptPublicId): bool
    {
        $owner = $this->productCodes[strtolower($code)] ?? null;

        return $owner !== null && $owner !== $exceptPublicId;
    }

    /** @internal */
    public function allVariants(): array
    {
        return $this->variants;
    }

    /** @internal */
    public function findVariantById(int $id): ?ProductVariant
    {
        return $this->variants[$id] ?? null;
    }

    /** @internal */
    public function findVariantIdByPublicId(string $publicId): ?int
    {
        return $this->variantPublicIdToId[$publicId] ?? null;
    }

    /** @internal */
    public function storeVariant(int $id, ProductVariant $variant): void
    {
        $this->variants[$id] = $variant;
        $this->variantPublicIdToId[$variant->publicId()->toString()] = $id;
    }

    /** @internal */
    public function nextVariantId(): int
    {
        return $this->nextVariantId++;
    }

    /** @internal */
    public function recordAudit(ProductAuditEntry $entry): void
    {
        $this->auditEntries[] = $entry;
    }

    /** @internal @return list<array<string, mixed>> */
    public function families(): array
    {
        return $this->families;
    }

    /** @internal @return list<array<string, mixed>> */
    public function categories(): array
    {
        return $this->categories;
    }

    /** @internal @return list<array<string, mixed>> */
    public function series(): array
    {
        return $this->series;
    }

    /** @internal */
    public function addFamily(array $row): void
    {
        $this->families[] = $row;
    }

    /** @internal */
    public function addCategory(array $row): void
    {
        $this->categories[] = $row;
    }

    /** @internal */
    public function addSeries(array $row): void
    {
        $this->series[] = $row;
    }


    /** @internal @param list<array<string, mixed>> $rows */
    public function setFamilies(array $rows): void
    {
        $this->families = array_values($rows);
    }

    /** @internal @param list<array<string, mixed>> $rows */
    public function setCategories(array $rows): void
    {
        $this->categories = array_values($rows);
    }

    /** @internal @param list<array<string, mixed>> $rows */
    public function setSeries(array $rows): void
    {
        $this->series = array_values($rows);
    }


    /** @internal */
    public function nextClassificationId(): int
    {
        return $this->nextClassificationId++;
    }

    /** @internal @return array<string, array<string, mixed>> */
    public function mediaByPublicIdMap(): array
    {
        return $this->mediaByPublicId;
    }

    /** @internal */
    public function setMediaByPublicIdMap(array $map): void
    {
        $this->mediaByPublicId = $map;
    }

    /** @internal @return array<string, array<string, mixed>> */
    public function documentsByPublicIdMap(): array
    {
        return $this->documentsByPublicId;
    }

    /** @internal */
    public function setDocumentsByPublicIdMap(array $map): void
    {
        $this->documentsByPublicId = $map;
    }

    /** @internal @return array<string, array<string, mixed>> */
    public function sets360Map(): array
    {
        return $this->sets360;
    }

    /** @internal */
    public function setSets360Map(array $map): void
    {
        $this->sets360 = $map;
    }

    /** @internal @return array<string, array<string, mixed>> */
    public function frames360Map(): array
    {
        return $this->frames360;
    }

    /** @internal */
    public function setFrames360Map(array $map): void
    {
        $this->frames360 = $map;
    }

    /** @internal @return array<string, array<string, mixed>> */
    public function arModelsMap(): array
    {
        return $this->arModels;
    }

    /** @internal */
    public function setArModelsMap(array $map): void
    {
        $this->arModels = $map;
    }

    /** @internal @return list<array<string, mixed>> */
    public function mediaFor(string $productPublicId): array
    {
        return $this->media[$productPublicId] ?? [];
    }

    /** @internal */
    public function setMediaFor(string $productPublicId, array $rows): void
    {
        $this->media[$productPublicId] = $rows;
    }

    /** @internal @return list<array<string, mixed>> */
    public function documentsFor(string $productPublicId): array
    {
        return $this->documents[$productPublicId] ?? [];
    }

    /** @internal */
    public function setDocumentsFor(string $productPublicId, array $rows): void
    {
        $this->documents[$productPublicId] = $rows;
    }

    /** @internal @return list<ProductLookupItem> */
    public function regions(): array
    {
        return $this->regions;
    }

    /** @internal @return list<ProductLookupItem> */
    public function marketsLookup(): array
    {
        return $this->markets;
    }

    /** @internal @return list<ProductLookupItem> */
    public function industries(): array
    {
        return $this->industries;
    }

    /** @internal @return list<ProductLookupItem> */
    public function applicationsLookup(): array
    {
        return $this->applications;
    }

    /** @internal @return list<string> */
    public function productRegions(string $productPublicId): array
    {
        return $this->productRegions[$productPublicId] ?? [];
    }

    /** @internal */
    public function setProductRegions(string $productPublicId, array $regionPublicIds): void
    {
        $this->productRegions[$productPublicId] = $regionPublicIds;
    }

    /** @internal @return list<string> */
    public function productMarkets(string $productPublicId): array
    {
        return $this->productMarkets[$productPublicId] ?? [];
    }

    /** @internal */
    public function setProductMarkets(string $productPublicId, array $marketPublicIds): void
    {
        $this->productMarkets[$productPublicId] = $marketPublicIds;
    }

    /** @internal @return list<string> */
    public function productIndustries(string $productPublicId): array
    {
        return $this->productIndustries[$productPublicId] ?? [];
    }

    /** @internal */
    public function setProductIndustries(string $productPublicId, array $industryPublicIds): void
    {
        $this->productIndustries[$productPublicId] = $industryPublicIds;
    }

    /** @internal @return list<string> */
    public function productApplications(string $productPublicId): array
    {
        return $this->productApplications[$productPublicId] ?? [];
    }

    /** @internal */
    public function setProductApplications(string $productPublicId, array $applicationPublicIds): void
    {
        $this->productApplications[$productPublicId] = $applicationPublicIds;
    }

    /** @internal @return list<array<string, mixed>> */
    public function relationshipsFor(string $productPublicId, string $type): array
    {
        return $this->relationships[$productPublicId . '|' . $type] ?? [];
    }

    /** @internal */
    public function setRelationshipsFor(string $productPublicId, string $type, array $items): void
    {
        $this->relationships[$productPublicId . '|' . $type] = $items;
    }

    /** @internal */
    public function findSlug(string $productPublicId, ?string $regionPublicId): ?array
    {
        return $this->slugs[$productPublicId . '|' . ($regionPublicId ?? '')] ?? null;
    }

    /** @internal */
    public function setSlug(string $productPublicId, ?string $regionPublicId, string $slug, bool $isPrimary): void
    {
        $this->slugs[$productPublicId . '|' . ($regionPublicId ?? '')] = ['slug' => $slug, 'isPrimary' => $isPrimary];
    }

    /** @internal */
    public function findSeo(string $productPublicId, ?string $regionPublicId): ?array
    {
        return $this->seo[$productPublicId . '|' . ($regionPublicId ?? '')] ?? null;
    }

    /** @internal */
    public function setSeo(string $productPublicId, ?string $regionPublicId, array $data): void
    {
        $this->seo[$productPublicId . '|' . ($regionPublicId ?? '')] = $data;
    }

    /** @internal */
    public function addRedirect(array $row): void
    {
        $this->redirects[] = $row;
    }

    /** @internal @return list<ProductPricingSummary> */
    public function pricingFor(string $productPublicId): array
    {
        return $this->pricing[$productPublicId] ?? [];
    }

    /** @internal */
    public function setPricingFor(string $productPublicId, array $rows): void
    {
        $this->pricing[$productPublicId] = $rows;
    }

    /** @internal @return list<array<string, mixed>> */
    public function specDefinitions(): array
    {
        return $this->specDefinitions;
    }

    /** @internal @return list<array<string, mixed>> */
    public function productSpecValues(string $productPublicId): array
    {
        if ($this->specificationRepository !== null) {
            return $this->specificationRepository->listProductValues(\TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($productPublicId));
        }
        return $this->productSpecValues[$productPublicId] ?? [];
    }

    /** @internal */
    public function setProductSpecValues(string $productPublicId, array $values): void
    {
        $this->productSpecValues[$productPublicId] = $values;
        $this->specifications()->replaceProductValues(
            \TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($productPublicId),
            $values,
            \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1),
        );
    }

    /** @internal @return list<array<string, mixed>> */
    public function variantSpecValues(string $variantPublicId): array
    {
        if ($this->specificationRepository !== null) {
            return $this->specificationRepository->listVariantValues(\TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId::fromString($variantPublicId));
        }
        return $this->variantSpecValues[$variantPublicId] ?? [];
    }

    /** @internal */
    public function setVariantSpecValues(string $variantPublicId, array $values): void
    {
        $this->variantSpecValues[$variantPublicId] = $values;
        $this->specifications()->replaceVariantValues(
            \TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId::fromString($variantPublicId),
            $values,
            \TriCab\Core\Identity\Domain\ValueObject\UserId::fromInt(1),
        );
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductRepository implements ProductRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function findByPublicId(ProductPublicId $publicId): ?Product
    {
        $id = $this->store->findProductIdByPublicId($publicId->toString());

        return $id !== null ? $this->store->findProductById($id) : null;
    }

    public function findById(ProductId $productId): ?Product
    {
        return $this->store->findProductById($productId->toInt());
    }

    public function findIdByPublicId(ProductPublicId $publicId): ?ProductId
    {
        $id = $this->store->findProductIdByPublicId($publicId->toString());

        return $id !== null ? ProductId::fromInt($id) : null;
    }

    public function productCodeExists(ProductCodeValue $code, ?ProductPublicId $exceptPublicId = null): bool
    {
        return $this->store->productCodeExists($code->toString(), $exceptPublicId?->toString());
    }

    public function internalCodeExists(string $internalCode, ?ProductPublicId $exceptPublicId = null): bool
    {
        foreach ($this->store->allProducts() as $product) {
            if ($product->internalCode()?->toString() === $internalCode) {
                if ($exceptPublicId === null || !$product->publicId()->equals($exceptPublicId)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function slugExists(ProductSlug $slug, ?string $regionPublicId = null, ?ProductPublicId $exceptPublicId = null): bool
    {
        return false;
    }

    public function findRowVersion(ProductPublicId $publicId): ?RowVersionToken
    {
        return $this->findByPublicId($publicId)?->rowVersion();
    }

    public function save(Product $product, UserId $actorUserId, ?RowVersionToken $expectedRowVersion = null): Product
    {
        $existingId = $this->store->findProductIdByPublicId($product->publicId()->toString());

        if ($product->id() === null && $existingId === null) {
            $id = $this->store->nextProductId();
            $rowVersion = $this->store->nextRowVersion();
            $persisted = self::withIdentity($product, ProductId::fromInt($id), $rowVersion);
            $this->store->storeProduct($id, $persisted);
            if ($product->internalCode() === null) {
                // no-op; internalCode uniqueness checked by caller service if needed
            }

            return $persisted;
        }

        $id = $existingId ?? $product->id()?->toInt();
        if ($id === null) {
            $id = $this->store->nextProductId();
        }

        $current = $this->store->findProductById($id);
        if ($current !== null) {
            $currentRowVersion = $current->rowVersion();
            if (
                $currentRowVersion !== null
                && ($expectedRowVersion === null || !$expectedRowVersion->equals($currentRowVersion))
            ) {
                throw ProductConcurrencyException::forPublicId($product->publicId()->toString());
            }
        }

        $rowVersion = $this->store->nextRowVersion();
        $persisted = self::withIdentity($product, ProductId::fromInt($id), $rowVersion);
        $this->store->storeProduct($id, $persisted);

        return $persisted;
    }

    private static function withIdentity(Product $product, ProductId $id, RowVersionToken $rowVersion): Product
    {
        return new Product(
            id: $id,
            publicId: $product->publicId(),
            productName: $product->productName(),
            status: $product->status(),
            lifecycleStatus: $product->lifecycleStatus(),
            familyId: $product->familyId(),
            categoryId: $product->categoryId(),
            seriesId: $product->seriesId(),
            internalName: $product->internalName(),
            internalCode: $product->internalCode(),
            shortDescription: $product->shortDescription(),
            fullDescription: $product->fullDescription(),
            legacyProductId: $product->legacyProductId(),
            erpReference: $product->erpReference(),
            isFeatured: $product->isFeatured(),
            publishAtUtc: $product->publishAtUtc(),
            unpublishAtUtc: $product->unpublishAtUtc(),
            discontinueAtUtc: $product->discontinueAtUtc(),
            isActive: $product->isActive(),
            deletedAtUtc: $product->deletedAtUtc(),
            deletedByUserId: $product->deletedByUserId(),
            rowVersion: $rowVersion,
        );
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductVariantRepository implements ProductVariantRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function findByPublicId(ProductVariantPublicId $publicId): ?ProductVariant
    {
        $id = $this->store->findVariantIdByPublicId($publicId->toString());

        return $id !== null ? $this->store->findVariantById($id) : null;
    }

    public function findById(ProductVariantId $variantId): ?ProductVariant
    {
        return $this->store->findVariantById($variantId->toInt());
    }

    public function findIdByPublicId(ProductVariantPublicId $publicId): ?ProductVariantId
    {
        $id = $this->store->findVariantIdByPublicId($publicId->toString());

        return $id !== null ? ProductVariantId::fromInt($id) : null;
    }

    public function skuExists(Sku $sku, ?ProductVariantPublicId $exceptPublicId = null): bool
    {
        foreach ($this->store->allVariants() as $variant) {
            if ($variant->sku()?->equals($sku) === true) {
                if ($exceptPublicId === null || !$variant->publicId()->equals($exceptPublicId)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function findRowVersion(ProductVariantPublicId $publicId): ?RowVersionToken
    {
        return $this->findByPublicId($publicId)?->rowVersion();
    }

    public function save(ProductVariant $variant, UserId $actorUserId, ?RowVersionToken $expectedRowVersion = null): ProductVariant
    {
        if ($variant->sku() !== null && $this->skuExists($variant->sku(), $variant->publicId())) {
            throw DuplicateVariantSku::forSku($variant->sku()->toString());
        }

        $existingId = $this->store->findVariantIdByPublicId($variant->publicId()->toString());

        if ($variant->id() === null && $existingId === null) {
            $id = $this->store->nextVariantId();
            $rowVersion = $this->store->nextRowVersion();
            $persisted = self::withIdentity($variant, ProductVariantId::fromInt($id), $rowVersion);
            $this->store->storeVariant($id, $persisted);

            return $persisted;
        }

        $id = $existingId ?? $variant->id()?->toInt();
        if ($id === null) {
            $id = $this->store->nextVariantId();
        }

        $current = $this->store->findVariantById($id);
        if ($current !== null) {
            $currentRowVersion = $current->rowVersion();
            if (
                $currentRowVersion !== null
                && ($expectedRowVersion === null || !$expectedRowVersion->equals($currentRowVersion))
            ) {
                throw ProductConcurrencyException::forPublicId($variant->publicId()->toString());
            }
        }

        $rowVersion = $this->store->nextRowVersion();
        $persisted = self::withIdentity($variant, ProductVariantId::fromInt($id), $rowVersion);
        $this->store->storeVariant($id, $persisted);

        return $persisted;
    }

    private static function withIdentity(ProductVariant $variant, ProductVariantId $id, RowVersionToken $rowVersion): ProductVariant
    {
        return new ProductVariant(
            id: $id,
            publicId: $variant->publicId(),
            productId: $variant->productId(),
            variantName: $variant->variantName(),
            status: $variant->status(),
            displayOrder: $variant->displayOrder(),
            sku: $variant->sku(),
            variantCode: $variant->variantCode(),
            minimumOrderQuantity: $variant->minimumOrderQuantity(),
            weightKg: $variant->weightKg(),
            packagingDescription: $variant->packagingDescription(),
            erpReference: $variant->erpReference(),
            isActive: $variant->isActive(),
            deletedAtUtc: $variant->deletedAtUtc(),
            deletedByUserId: $variant->deletedByUserId(),
            rowVersion: $rowVersion,
        );
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductAuditRepository implements ProductAuditRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listByProduct(ProductPublicId $productPublicId, int $limit = 50): array
    {
        $matching = array_values(array_filter(
            $this->store->auditEntries(),
            static fn (ProductAuditEntry $entry): bool => $entry->productPublicId === $productPublicId->toString(),
        ));

        return array_slice(array_reverse($matching), 0, $limit);
    }

    public function record(
        ?ProductPublicId $productPublicId,
        string $entityTypeCode,
        ?int $entityId,
        string $actionCode,
        ?UserId $actorUserId,
        ?string $correlationId,
        ?string $sourceCode,
        ?array $previousValues,
        ?array $newValues,
    ): void {
        $this->store->recordAudit(new ProductAuditEntry(
            publicId: bin2hex(random_bytes(8)),
            productPublicId: $productPublicId?->toString(),
            entityTypeCode: $entityTypeCode,
            entityId: $entityId,
            actionCode: $actionCode,
            actorDisplayName: $actorUserId !== null ? 'user-' . $actorUserId->toInt() : null,
            correlationId: $correlationId,
            sourceCode: $sourceCode,
            previousValuesJson: $previousValues !== null ? json_encode($previousValues) : null,
            newValuesJson: $newValues !== null ? json_encode($newValues) : null,
            createdAtUtc: (new DateTimeImmutable())->format(DATE_ATOM),
        ));
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductClassificationRepository implements ProductClassificationRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listFamilies(bool $activeOnly = true): array
    {
        return array_values(array_map(
            static fn (array $row): ProductLookupItem => new ProductLookupItem($row['publicId'], $row['code'], $row['name'], null, 0, $row['isActive']),
            array_filter($this->store->families(), static fn (array $row): bool => !$activeOnly || $row['isActive']),
        ));
    }

    public function listCategories(?string $familyPublicId = null, bool $activeOnly = true): array
    {
        return array_values(array_map(
            static fn (array $row): ProductLookupItem => new ProductLookupItem($row['publicId'], $row['code'], $row['name'], $row['familyPublicId'], 0, $row['isActive']),
            array_filter(
                $this->store->categories(),
                static fn (array $row): bool => (!$activeOnly || $row['isActive'])
                    && ($familyPublicId === null || $row['familyPublicId'] === $familyPublicId),
            ),
        ));
    }

    public function listSeries(?string $categoryPublicId = null, bool $activeOnly = true): array
    {
        return array_values(array_map(
            static fn (array $row): ProductLookupItem => new ProductLookupItem($row['publicId'], $row['code'], $row['name'], $row['categoryPublicId'], 0, $row['isActive']),
            array_filter(
                $this->store->series(),
                static fn (array $row): bool => (!$activeOnly || $row['isActive'])
                    && ($categoryPublicId === null || $row['categoryPublicId'] === $categoryPublicId),
            ),
        ));
    }

    public function createFamily(array $data, UserId $actorUserId): ProductLookupItem
    {
        $publicId = bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-4' . substr(bin2hex(random_bytes(2)), 1) . '-a' . substr(bin2hex(random_bytes(2)), 1) . '-' . bin2hex(random_bytes(6));
        $this->store->addFamily([
            'id' => $this->store->nextClassificationId(),
            'publicId' => $publicId,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => $data['isActive'] ?? true,
            'rowVersion' => 'AAAAAAAAAAA=',
            'deletedAtUtc' => null,
        ]);

        return new ProductLookupItem($publicId, $data['code'], $data['name'], null, $data['displayOrder'] ?? 0, true);
    }

    public function createCategory(array $data, UserId $actorUserId): ProductLookupItem
    {
        $publicId = bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-4' . substr(bin2hex(random_bytes(2)), 1) . '-a' . substr(bin2hex(random_bytes(2)), 1) . '-' . bin2hex(random_bytes(6));
        $this->store->addCategory([
            'id' => $this->store->nextClassificationId(),
            'publicId' => $publicId,
            'code' => $data['code'],
            'name' => $data['name'],
            'familyPublicId' => $data['familyPublicId'] ?? null,
            'description' => $data['description'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => $data['isActive'] ?? true,
            'rowVersion' => 'AAAAAAAAAAA=',
            'deletedAtUtc' => null,
        ]);

        return new ProductLookupItem($publicId, $data['code'], $data['name'], $data['familyPublicId'] ?? null, $data['displayOrder'] ?? 0, true);
    }

    public function createSeries(array $data, UserId $actorUserId): ProductLookupItem
    {
        $publicId = bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)) . '-4' . substr(bin2hex(random_bytes(2)), 1) . '-a' . substr(bin2hex(random_bytes(2)), 1) . '-' . bin2hex(random_bytes(6));
        $this->store->addSeries([
            'id' => $this->store->nextClassificationId(),
            'publicId' => $publicId,
            'code' => $data['code'],
            'name' => $data['name'],
            'categoryPublicId' => $data['categoryPublicId'] ?? null,
            'description' => $data['description'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => $data['isActive'] ?? true,
            'rowVersion' => 'AAAAAAAAAAA=',
            'deletedAtUtc' => null,
        ]);

        return new ProductLookupItem($publicId, $data['code'], $data['name'], $data['categoryPublicId'] ?? null, $data['displayOrder'] ?? 0, true);
    }

    public function findFamilyIdByPublicId(string $publicId): ?ProductFamilyId
    {
        foreach ($this->store->families() as $row) {
            if ($row['publicId'] === $publicId && $row['isActive']) {
                return ProductFamilyId::fromInt($row['id']);
            }
        }

        return null;
    }

    public function findCategoryIdByPublicId(string $publicId): ?ProductCategoryId
    {
        foreach ($this->store->categories() as $row) {
            if ($row['publicId'] === $publicId && $row['isActive']) {
                return ProductCategoryId::fromInt($row['id']);
            }
        }

        return null;
    }

    public function findSeriesIdByPublicId(string $publicId): ?ProductSeriesId
    {
        foreach ($this->store->series() as $row) {
            if ($row['publicId'] === $publicId && $row['isActive']) {
                return ProductSeriesId::fromInt($row['id']);
            }
        }

        return null;
    }

    public function assertHierarchyValid(?string $familyPublicId, ?string $categoryPublicId, ?string $seriesPublicId): void
    {
        $familyRow = $familyPublicId !== null ? $this->findRow($this->store->families(), $familyPublicId) : null;
        if ($familyPublicId !== null && ($familyRow === null || !$familyRow['isActive'])) {
            throw InvalidProductClassification::forPublicId($familyPublicId);
        }

        $categoryRow = $categoryPublicId !== null ? $this->findRow($this->store->categories(), $categoryPublicId) : null;
        if ($categoryPublicId !== null && ($categoryRow === null || !$categoryRow['isActive'])) {
            throw InvalidProductClassification::forPublicId($categoryPublicId);
        }

        if ($categoryRow !== null && $familyPublicId !== null && $categoryRow['familyPublicId'] !== $familyPublicId) {
            throw InvalidProductClassification::invalidHierarchy('Category does not belong to the given family.');
        }

        $seriesRow = $seriesPublicId !== null ? $this->findRow($this->store->series(), $seriesPublicId) : null;
        if ($seriesPublicId !== null && ($seriesRow === null || !$seriesRow['isActive'])) {
            throw InvalidProductClassification::forPublicId($seriesPublicId);
        }

        if ($seriesRow !== null && $categoryPublicId !== null && $seriesRow['categoryPublicId'] !== $categoryPublicId) {
            throw InvalidProductClassification::invalidHierarchy('Series does not belong to the given category.');
        }
    }


    public function findFamilyByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord
    {
        $row = $this->findRow($this->store->families(), $publicId);
        if ($row === null) {
            return null;
        }
        if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
            return null;
        }

        return $this->toRecord($row, null);
    }

    public function findCategoryByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord
    {
        $row = $this->findRow($this->store->categories(), $publicId);
        if ($row === null || (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null)) {
            return null;
        }

        return $this->toRecord($row, $row['familyPublicId'] ?? null);
    }

    public function findSeriesByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord
    {
        $row = $this->findRow($this->store->series(), $publicId);
        if ($row === null || (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null)) {
            return null;
        }

        return $this->toRecord($row, $row['categoryPublicId'] ?? null);
    }

    public function listFamiliesAdmin(array $filters = []): array
    {
        $out = array_values(array_filter(array_map(
            fn (array $row): ProductClassificationRecord => $this->toRecord($row, null),
            $this->store->families(),
        ), fn (ProductClassificationRecord $r): bool => ($filters['includeDeleted'] ?? false) || !$r->isDeleted));
        usort(
            $out,
            static fn (ProductClassificationRecord $a, ProductClassificationRecord $b): int =>
                [$a->displayOrder, $a->name] <=> [$b->displayOrder, $b->name],
        );

        return $out;
    }

    public function listCategoriesAdmin(array $filters = []): array
    {
        $parent = $filters['parentPublicId'] ?? null;
        $out = [];
        foreach ($this->store->categories() as $row) {
            if ($parent !== null && ($row['familyPublicId'] ?? null) !== $parent) {
                continue;
            }
            $record = $this->toRecord($row, $row['familyPublicId'] ?? null);
            if (!($filters['includeDeleted'] ?? false) && $record->isDeleted) {
                continue;
            }
            $out[] = $record;
        }

        return $out;
    }

    public function listSeriesAdmin(array $filters = []): array
    {
        $parent = $filters['parentPublicId'] ?? null;
        $out = [];
        foreach ($this->store->series() as $row) {
            if ($parent !== null && ($row['categoryPublicId'] ?? null) !== $parent) {
                continue;
            }
            $record = $this->toRecord($row, $row['categoryPublicId'] ?? null);
            if (!($filters['includeDeleted'] ?? false) && $record->isDeleted) {
                continue;
            }
            $out[] = $record;
        }

        return $out;
    }

    public function updateFamily(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateFamily($publicId, $data, $expectedRowVersion);
    }

    public function updateCategory(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateCategory($publicId, $data, $expectedRowVersion);
    }

    public function updateSeries(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateSeries($publicId, $data, $expectedRowVersion);
    }

    public function setFamilyActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateFamily($publicId, ['isActive' => $isActive], $expectedRowVersion);
    }

    public function setCategoryActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateCategory($publicId, ['isActive' => $isActive], $expectedRowVersion);
    }

    public function setSeriesActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateSeries($publicId, ['isActive' => $isActive], $expectedRowVersion);
    }

    public function softDeleteFamily(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateFamily($publicId, ['deletedAtUtc' => '2026-01-01T00:00:00+00:00', 'isActive' => false], $expectedRowVersion);
    }

    public function softDeleteCategory(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateCategory($publicId, ['deletedAtUtc' => '2026-01-01T00:00:00+00:00', 'isActive' => false], $expectedRowVersion);
    }

    public function softDeleteSeries(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateSeries($publicId, ['deletedAtUtc' => '2026-01-01T00:00:00+00:00', 'isActive' => false], $expectedRowVersion);
    }

    public function restoreFamily(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateFamily($publicId, ['deletedAtUtc' => null, 'isActive' => true], $expectedRowVersion);
    }

    public function restoreCategory(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateCategory($publicId, ['deletedAtUtc' => null, 'isActive' => true], $expectedRowVersion);
    }

    public function restoreSeries(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutateSeries($publicId, ['deletedAtUtc' => null, 'isActive' => true], $expectedRowVersion);
    }

    public function reorderFamilies(array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $this->mutateFamily($publicId, ['displayOrder' => $order], null);
            $order += 10;
        }
    }

    public function reorderCategories(string $familyPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $this->mutateCategory($publicId, ['displayOrder' => $order], null);
            $order += 10;
        }
    }

    public function reorderSeries(string $categoryPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $this->mutateSeries($publicId, ['displayOrder' => $order], null);
            $order += 10;
        }
    }

    public function getFamilyUsage(string $publicId): ProductClassificationUsage
    {
        $active = 0;
        $inactive = 0;
        foreach ($this->store->categories() as $row) {
            if (($row['familyPublicId'] ?? null) !== $publicId || ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if ($row['isActive'] ?? true) {
                $active++;
            } else {
                $inactive++;
            }
        }

        return new ProductClassificationUsage($publicId, 'family', 0, $active, $inactive);
    }

    public function getCategoryUsage(string $publicId): ProductClassificationUsage
    {
        $active = 0;
        $inactive = 0;
        foreach ($this->store->series() as $row) {
            if (($row['categoryPublicId'] ?? null) !== $publicId || ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if ($row['isActive'] ?? true) {
                $active++;
            } else {
                $inactive++;
            }
        }

        return new ProductClassificationUsage($publicId, 'category', 0, 0, 0, $active, $inactive);
    }

    public function getSeriesUsage(string $publicId): ProductClassificationUsage
    {
        return new ProductClassificationUsage($publicId, 'series', 0);
    }

    public function familyCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->store->families(), $code, $excludePublicId);
    }

    public function categoryCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->store->categories(), $code, $excludePublicId);
    }

    public function seriesCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->store->series(), $code, $excludePublicId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mutateFamily(string $publicId, array $data, ?string $expectedRowVersion): ProductClassificationRecord
    {
        $families = $this->store->families();
        foreach ($families as $i => $row) {
            if ($row['publicId'] !== $publicId) {
                continue;
            }
            $current = (string) ($row['rowVersion'] ?? 'AAA=');
            if ($expectedRowVersion !== null && $current !== $expectedRowVersion) {
                throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
            }
            $families[$i] = array_merge($row, $data, ['rowVersion' => 'RV' . bin2hex(random_bytes(3))]);
            $this->store->setFamilies($families);

            return $this->toRecord($families[$i], null);
        }
        throw ProductClassificationNotFound::forPublicId($publicId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mutateCategory(string $publicId, array $data, ?string $expectedRowVersion): ProductClassificationRecord
    {
        if (isset($data['familyPublicId'])) {
            // keep
        }
        $categories = $this->store->categories();
        foreach ($categories as $i => $row) {
            if ($row['publicId'] !== $publicId) {
                continue;
            }
            $current = (string) ($row['rowVersion'] ?? 'AAA=');
            if ($expectedRowVersion !== null && $current !== $expectedRowVersion) {
                throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
            }
            $categories[$i] = array_merge($row, $data, ['rowVersion' => 'RV' . bin2hex(random_bytes(3))]);
            $this->store->setCategories($categories);

            return $this->toRecord($categories[$i], $categories[$i]['familyPublicId'] ?? null);
        }
        throw ProductClassificationNotFound::forPublicId($publicId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mutateSeries(string $publicId, array $data, ?string $expectedRowVersion): ProductClassificationRecord
    {
        $series = $this->store->series();
        foreach ($series as $i => $row) {
            if ($row['publicId'] !== $publicId) {
                continue;
            }
            $current = (string) ($row['rowVersion'] ?? 'AAA=');
            if ($expectedRowVersion !== null && $current !== $expectedRowVersion) {
                throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
            }
            $series[$i] = array_merge($row, $data, ['rowVersion' => 'RV' . bin2hex(random_bytes(3))]);
            $this->store->setSeries($series);

            return $this->toRecord($series[$i], $series[$i]['categoryPublicId'] ?? null);
        }
        throw ProductClassificationNotFound::forPublicId($publicId);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function codeExists(array $rows, string $code, ?string $excludePublicId): bool
    {
        $needle = strtoupper(trim($code));
        foreach ($rows as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if ($excludePublicId !== null && $row['publicId'] === $excludePublicId) {
                continue;
            }
            if (strtoupper((string) $row['code']) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRecord(array $row, ?string $parentPublicId): ProductClassificationRecord
    {
        return new ProductClassificationRecord(
            publicId: (string) $row['publicId'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            description: isset($row['description']) && is_string($row['description']) ? $row['description'] : null,
            parentPublicId: $parentPublicId,
            parentName: null,
            displayOrder: (int) ($row['displayOrder'] ?? 0),
            isActive: (bool) ($row['isActive'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: (string) ($row['rowVersion'] ?? 'AAAAAAAAAAA='),
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRow(array $rows, string $publicId): ?array
    {
        foreach ($rows as $row) {
            if ($row['publicId'] === $publicId) {
                return $row;
            }
        }

        return null;
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductAdminQueryRepository implements ProductAdminQueryRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function list(ProductListCriteria $criteria): ProductListResult
    {
        return new ProductListResult([], \TriCab\Core\Product\Application\DTO\Pagination::fromTotals($criteria->page, $criteria->pageSize, 0));
    }

    public function findDetailsByPublicIds(array $publicIds): array
    {
        $out = [];
        foreach ($publicIds as $publicId) {
            $detail = $this->findDetailByPublicId($publicId);
            if ($detail !== null) {
                $out[$publicId->toString()] = $detail;
            }
        }

        return $out;
    }

    public function findDetailByPublicId(ProductPublicId $publicId): ?ProductAdminDetail
    {
        $id = $this->store->findProductIdByPublicId($publicId->toString());
        if ($id === null) {
            return null;
        }

        $product = $this->store->findProductById($id);
        if ($product === null) {
            return null;
        }

        $regionsByPublicId = [];
        foreach ($this->store->regions() as $region) {
            $regionsByPublicId[$region->publicId] = $region->code;
        }

        $regionCodes = array_values(array_filter(array_map(
            static fn (string $regionPublicId): ?string => $regionsByPublicId[$regionPublicId] ?? null,
            $this->store->productRegions($publicId->toString()),
        )));

        $marketsByPublicId = [];
        foreach ($this->store->marketsLookup() as $market) {
            $marketsByPublicId[$market->publicId] = $market->code;
        }
        $marketCodes = array_values(array_filter(array_map(
            static fn (string $marketPublicId): ?string => $marketsByPublicId[$marketPublicId] ?? null,
            $this->store->productMarkets($publicId->toString()),
        )));

        $industriesByPublicId = [];
        foreach ($this->store->industries() as $industry) {
            $industriesByPublicId[$industry->publicId] = $industry->code;
        }
        $industryCodes = array_values(array_filter(array_map(
            static fn (string $industryPublicId): ?string => $industriesByPublicId[$industryPublicId] ?? null,
            $this->store->productIndustries($publicId->toString()),
        )));

        $applicationsByPublicId = [];
        foreach ($this->store->applicationsLookup() as $application) {
            $applicationsByPublicId[$application->publicId] = $application->code;
        }
        $applicationCodes = array_values(array_filter(array_map(
            static fn (string $applicationPublicId): ?string => $applicationsByPublicId[$applicationPublicId] ?? null,
            $this->store->productApplications($publicId->toString()),
        )));

        $primarySlug = $this->store->findSlug($publicId->toString(), null);

        $createdAtUtc = $this->store->productCreatedAt($publicId->toString());

        return new ProductAdminDetail(
            publicId: $product->publicId()->toString(),
            productName: $product->productName(),
            internalName: $product->internalName(),
            internalCode: $product->internalCode()?->toString(),
            shortDescription: $product->shortDescription(),
            fullDescription: $product->fullDescription(),
            familyPublicId: null,
            categoryPublicId: null,
            seriesPublicId: null,
            statusCode: $product->status()->toString(),
            lifecycleStatusCode: $product->lifecycleStatus()->toString(),
            isFeatured: $product->isFeatured(),
            publishAtUtc: $product->publishAtUtc()?->format(DATE_ATOM),
            unpublishAtUtc: $product->unpublishAtUtc()?->format(DATE_ATOM),
            discontinueAtUtc: $product->discontinueAtUtc()?->format(DATE_ATOM),
            erpReference: $product->erpReference(),
            legacyProductId: $product->legacyProductId(),
            variants: $this->listVariantSummariesByProduct($publicId),
            regionCodes: $regionCodes,
            marketCodes: $marketCodes,
            industryCodes: $industryCodes,
            applicationCodes: $applicationCodes,
            primarySlug: $primarySlug['slug'] ?? null,
            isActive: $product->isActive(),
            isArchived: $product->isArchived(),
            createdAtUtc: $createdAtUtc,
            updatedAtUtc: (new DateTimeImmutable())->format(DATE_ATOM),
            createdByDisplayName: null,
            updatedByDisplayName: null,
            rowVersion: $product->rowVersion()?->toBase64() ?? '',
        );
    }

    public function listVariantSummariesByProduct(
        ProductPublicId $productPublicId,
        bool $includeArchived = true,
        bool $includeDeleted = false,
    ): array {
        $id = $this->store->findProductIdByPublicId($productPublicId->toString());
        if ($id === null) {
            return [];
        }

        $summaries = [];
        foreach ($this->store->allVariants() as $variant) {
            if ($variant->productId()->toInt() !== $id) {
                continue;
            }
            if (!$includeDeleted && $variant->isSoftDeleted()) {
                continue;
            }
            if (!$includeArchived && $variant->isArchived()) {
                continue;
            }

            $summaries[] = self::toSummary($variant);
        }

        return $summaries;
    }

    public function findVariantSummaryByPublicId(
        ProductVariantPublicId $publicId,
        bool $includeDeleted = false,
    ): ?ProductVariantSummary {
        $id = $this->store->findVariantIdByPublicId($publicId->toString());
        if ($id === null) {
            return null;
        }

        $variant = $this->store->findVariantById($id);
        if ($variant === null || (!$includeDeleted && $variant->isSoftDeleted())) {
            return null;
        }

        return self::toSummary($variant);
    }

    private static function toSummary(ProductVariant $variant): ProductVariantSummary
    {
        return new ProductVariantSummary(
            publicId: $variant->publicId()->toString(),
            variantName: $variant->variantName(),
            sku: $variant->sku()?->toString(),
            variantCode: $variant->variantCode()?->toString(),
            statusCode: $variant->status()->toString(),
            displayOrder: $variant->displayOrder()->toInt(),
            isActive: $variant->isActive(),
        );
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductMarketRepository implements ProductMarketRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listRegions(bool $activeOnly = true): array
    {
        return array_values(array_filter($this->store->regions(), static fn (ProductLookupItem $item): bool => !$activeOnly || $item->isActive));
    }

    public function listMarkets(?string $regionPublicId = null, bool $activeOnly = true): array
    {
        return array_values(array_filter(
            $this->store->marketsLookup(),
            static fn (ProductLookupItem $item): bool => (!$activeOnly || $item->isActive)
                && ($regionPublicId === null || $item->parentPublicId === $regionPublicId),
        ));
    }

    public function listIndustries(bool $activeOnly = true): array
    {
        return array_values(array_filter($this->store->industries(), static fn (ProductLookupItem $item): bool => !$activeOnly || $item->isActive));
    }

    public function listApplications(bool $activeOnly = true): array
    {
        return array_values(array_filter($this->store->applicationsLookup(), static fn (ProductLookupItem $item): bool => !$activeOnly || $item->isActive));
    }

    public function replaceProductRegions(ProductPublicId $productPublicId, array $regionPublicIds, UserId $actorUserId): void
    {
        $this->store->setProductRegions($productPublicId->toString(), $regionPublicIds);
    }

    public function replaceProductMarkets(ProductPublicId $productPublicId, array $marketPublicIds, UserId $actorUserId): void
    {
        $this->store->setProductMarkets($productPublicId->toString(), $marketPublicIds);
    }

    public function replaceProductIndustries(ProductPublicId $productPublicId, array $industryPublicIds, UserId $actorUserId): void
    {
        $this->store->setProductIndustries($productPublicId->toString(), $industryPublicIds);
    }

    public function replaceProductApplications(ProductPublicId $productPublicId, array $applicationPublicIds, UserId $actorUserId): void
    {
        $this->store->setProductApplications($productPublicId->toString(), $applicationPublicIds);
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductRelationshipRepository implements ProductRelationshipRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listByProduct(ProductPublicId $productPublicId, ProductRelationshipType $type): array
    {
        return $this->store->relationshipsFor($productPublicId->toString(), $type->toString());
    }

    public function replace(ProductPublicId $productPublicId, ProductRelationshipType $type, array $items, UserId $actorUserId): void
    {
        $this->store->setRelationshipsFor($productPublicId->toString(), $type->toString(), $items);
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductSeoRepository implements ProductSeoRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function findPrimarySlug(ProductPublicId $productPublicId): ?ProductSlug
    {
        $row = $this->store->findSlug($productPublicId->toString(), null);

        return $row !== null ? ProductSlug::fromString($row['slug']) : null;
    }

    public function findSeo(ProductPublicId $productPublicId, ?string $regionPublicId = null): ?array
    {
        return $this->store->findSeo($productPublicId->toString(), $regionPublicId);
    }

    public function upsertSlug(ProductPublicId $productPublicId, ProductSlug $slug, bool $isPrimary, ?string $regionPublicId, UserId $actorUserId): void
    {
        $this->store->setSlug($productPublicId->toString(), $regionPublicId, $slug->toString(), $isPrimary);
    }

    public function upsertSeo(ProductPublicId $productPublicId, array $data, UserId $actorUserId): void
    {
        $this->store->setSeo($productPublicId->toString(), $data['regionPublicId'] ?? null, $data);
    }

    public function createRedirect(
        ProductPublicId $productPublicId,
        string $fromSlug,
        string $toSlug,
        ?string $regionPublicId,
        int $httpStatusCode,
        UserId $actorUserId,
    ): void {
        $this->store->addRedirect([
            'productPublicId' => $productPublicId->toString(),
            'fromSlug' => $fromSlug,
            'toSlug' => $toSlug,
            'regionPublicId' => $regionPublicId,
            'httpStatusCode' => $httpStatusCode,
        ]);
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductPricingRepository implements ProductPricingRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        return $this->store->pricingFor($productPublicId->toString());
    }

    public function getOverview(ProductPublicId $productPublicId): \TriCab\Core\Product\Application\DTO\ProductPricingOverview
    {
        $items = $this->listByProduct($productPublicId);

        return new \TriCab\Core\Product\Application\DTO\ProductPricingOverview(
            variantCount: 0,
            variantsWithPricing: count($items),
            missingBaseCostCount: 0,
            currentRecordCount: count($items),
            scheduledRecordCount: 0,
            expiredRecordCount: 0,
            inactiveRecordCount: 0,
            defaultUnitOfMeasureCode: 'M',
            defaultUnitOfMeasureSymbol: 'm',
            defaultUnitSource: 'system-default',
        );
    }

    public function setProductDefaultPricingUnit(
        ProductPublicId $productPublicId,
        ?string $pricingUnitPublicId,
        UserId $actorUserId,
    ): \TriCab\Core\Product\Application\DTO\ProductPricingOverview {
        unset($pricingUnitPublicId, $actorUserId);

        return $this->getOverview($productPublicId);
    }

    public function listMatrixByProduct(ProductPublicId $productPublicId): array
    {
        return [];
    }

    public function listHistory(ProductPublicId $productPublicId, ?string $variantPublicId = null, ?string $priceListPublicId = null): array
    {
        unset($variantPublicId, $priceListPublicId);

        return [];
    }

    public function findByPublicId(string $publicId): ?ProductPricingSummary
    {
        unset($publicId);

        return null;
    }

    public function upsert(ProductPublicId $productPublicId, array $data, UserId $actorUserId): ProductPricingSummary
    {
        unset($actorUserId);
        $baseCost = $data['baseCost'] ?? $data['unitPrice'] ?? '0';
        $summary = new ProductPricingSummary(
            publicId: bin2hex(random_bytes(16)),
            recordType: ($data['variantPublicId'] ?? null) !== null ? 'variant' : 'product',
            variantPublicId: $data['variantPublicId'] ?? null,
            variantName: null,
            variantCode: null,
            currencyCode: $data['currencyCode'],
            baseCost: $baseCost,
            minimumQuantity: $data['minimumQuantity'],
            priceListCode: $data['priceListPublicId'] ?? null,
            priceListPublicId: $data['priceListPublicId'] ?? null,
            regionPublicId: $data['regionPublicId'] ?? null,
            marketPublicId: $data['marketPublicId'] ?? null,
            unitOfMeasureCode: 'M',
            unitOfMeasureSymbol: 'm',
            unitOfMeasurePublicId: null,
            priceSourceCode: $data['priceSourceCode'] ?? 'manual',
            effectiveFromUtc: $data['effectiveFromUtc'] ?? null,
            effectiveToUtc: $data['effectiveToUtc'] ?? null,
            status: \TriCab\Core\Product\Support\PricingRecordStatus::CURRENT,
            isActive: true,
            rowVersion: null,
            legacyItemCode: null,
            erpSourceReference: null,
            lastSourceUpdatedAtUtc: null,
        );

        $rows = $this->store->pricingFor($productPublicId->toString());
        $rows[] = $summary;
        $this->store->setPricingFor($productPublicId->toString(), $rows);

        return $summary;
    }

    public function setActive(string $pricingPublicId, bool $isActive, UserId $actorUserId, ?string $expectedRowVersion = null): ProductPricingSummary
    {
        unset($pricingPublicId, $isActive, $actorUserId, $expectedRowVersion);
        throw new \RuntimeException('Not implemented in fake pricing repository.');
    }
}

/**
 * @internal
 */
final class InMemoryFakeProductCodeRuleRepository implements ProductCodeRuleRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listRuleSets(bool $activeOnly = true): array
    {
        return [];
    }

    public function preview(string $ruleSetPublicId, array $segmentValues): array
    {
        $code = implode('-', array_values($segmentValues));

        return [
            'code' => $code !== '' ? $code : 'CODE',
            'explanation' => 'Generated from rule set ' . $ruleSetPublicId,
        ];
    }

    public function assignPrimaryCode(
        ProductPublicId $productPublicId,
        ProductCodeValue $code,
        ?string $explanation,
        ?string $ruleSetPublicId,
        UserId $actorUserId,
    ): void {
        $this->store->setProductCode($productPublicId->toString(), $code->toString());
    }
}
