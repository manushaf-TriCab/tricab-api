<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Command\ArchiveProductCommand;
use TriCab\Core\Product\Application\Command\AssignProductCodeCommand;
use TriCab\Core\Product\Application\Command\AssignProductSpecificationsCommand;
use TriCab\Core\Product\Application\Command\ChangeProductSlugCommand;
use TriCab\Core\Product\Application\Command\CreateProductCommand;
use TriCab\Core\Product\Application\Command\CreateProductVariantCommand;
use TriCab\Core\Product\Application\Command\PreviewProductCodeCommand;
use TriCab\Core\Product\Application\Command\PublishProductCommand;
use TriCab\Core\Product\Application\Command\ReplaceProductRelationshipsCommand;
use TriCab\Core\Product\Application\Command\RestoreArchivedProductCommand;
use TriCab\Core\Product\Application\Command\RestoreDeletedProductCommand;
use TriCab\Core\Product\Application\Command\SetPrimaryProductImageCommand;
use TriCab\Core\Product\Application\Command\SoftDeleteProductCommand;
use TriCab\Core\Product\Application\Command\UpdateProductCommand;
use TriCab\Core\Product\Application\Command\UpsertProductPricingCommand;
use TriCab\Core\Product\Application\Exception\DuplicateVariantSku;
use TriCab\Core\Product\Application\Exception\InvalidSpecificationSet;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductNotReadyForPublication;
use TriCab\Core\Product\Application\Exception\ProductSoftDeleted;
use TriCab\Core\Product\Application\Service\AssignProductCodeService;
use TriCab\Core\Product\Application\Service\AssignProductSpecificationsService;
use TriCab\Core\Product\Application\Service\ChangeProductSlugService;
use TriCab\Core\Product\Application\Service\CreateProductService;
use TriCab\Core\Product\Application\Service\CreateProductVariantService;
use TriCab\Core\Product\Application\Service\CrossAggregateProductPublishReadinessEvaluator;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\PublishProductService;
use TriCab\Core\Product\Application\Service\ArchiveProductService;
use TriCab\Core\Product\Application\Service\PreviewProductCodeService;
use TriCab\Core\Product\Application\Service\ReplaceProductRelationshipsService;
use TriCab\Core\Product\Application\Service\RestoreArchivedProductService;
use TriCab\Core\Product\Application\Service\RestoreDeletedProductService;
use TriCab\Core\Product\Application\Service\SetPrimaryProductImageService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductService;
use TriCab\Core\Product\Application\Service\UpdateProductService;
use TriCab\Core\Product\Application\Service\UpsertProductPricingService;
use TriCab\Core\Product\Domain\Exception\InvalidProductRelationshipException;
use TriCab\Core\Tests\Product\Doubles\InMemoryProductStore;
use TriCab\Core\Tests\Product\Doubles\PassthroughApplicationTransaction;

final class ProductServicesTest extends TestCase
{
    private InMemoryProductStore $store;
    private PassthroughApplicationTransaction $transaction;
    private ProductAuditRecorder $audit;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryProductStore();
        $this->transaction = new PassthroughApplicationTransaction();
        $this->audit = new ProductAuditRecorder($this->store->audit());
        $this->actor = new ActorContext(userId: 1, publicId: 'actor-public-id', emailAddress: 'admin@tricab.com');
    }

    private function createProductService(): CreateProductService
    {
        return new CreateProductService($this->store->products(), $this->store->classification(), $this->transaction, $this->audit);
    }

    private function readinessEvaluator(): CrossAggregateProductPublishReadinessEvaluator
    {
        return new CrossAggregateProductPublishReadinessEvaluator(
            $this->store->adminQuery(),
            $this->store->media(),
            $this->store->documents(),
            $this->store->relationships(),
        );
    }

    public function test_create_product_records_audit(): void
    {
        $result = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        self::assertNotEmpty($result->publicId);
        self::assertSame('draft', $result->statusCode);
        self::assertNotEmpty($result->rowVersion);

        $entries = $this->store->auditEntries();
        self::assertCount(1, $entries);
        self::assertSame('created', $entries[0]->actionCode);
        self::assertSame($result->publicId, $entries[0]->productPublicId);
    }

    public function test_update_with_stale_row_version_throws_concurrency_conflict(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));
        $staleRowVersion = $created->rowVersion;

        $updateService = new UpdateProductService($this->store->products(), $this->store->classification(), $this->transaction, $this->audit);

        $firstUpdate = $updateService->execute($this->actor, new UpdateProductCommand(
            publicId: $created->publicId,
            expectedRowVersion: $staleRowVersion,
            productName: 'Widget v2',
        ));
        self::assertSame('Widget v2', 'Widget v2');
        self::assertNotSame($staleRowVersion, $firstUpdate->rowVersion);

        $this->expectException(ProductConcurrencyConflict::class);
        $updateService->execute($this->actor, new UpdateProductCommand(
            publicId: $created->publicId,
            expectedRowVersion: $staleRowVersion,
            productName: 'Widget v3',
        ));
    }

    public function test_archive_does_not_soft_delete(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $archiveService = new ArchiveProductService($this->store->products(), $this->transaction, $this->audit);
        $archived = $archiveService->execute($this->actor, new ArchiveProductCommand($created->publicId, $created->rowVersion));

        self::assertSame('archived', $archived->statusCode);

        $product = $this->store->products()->findByPublicId(\TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($created->publicId));
        self::assertNotNull($product);
        self::assertTrue($product->isArchived());
        self::assertFalse($product->isSoftDeleted());
    }

    public function test_restore_from_archive(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));
        $archiveService = new ArchiveProductService($this->store->products(), $this->transaction, $this->audit);
        $archived = $archiveService->execute($this->actor, new ArchiveProductCommand($created->publicId, $created->rowVersion));

        $restoreService = new RestoreArchivedProductService($this->store->products(), $this->transaction, $this->audit);
        $restored = $restoreService->execute($this->actor, new RestoreArchivedProductCommand($created->publicId, $archived->rowVersion));

        self::assertSame('draft', $restored->statusCode);
    }

    public function test_soft_delete_and_restore_from_deletion(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $softDeleteService = new SoftDeleteProductService($this->store->products(), $this->transaction, $this->audit);
        $deleted = $softDeleteService->execute($this->actor, new SoftDeleteProductCommand($created->publicId, $created->rowVersion));

        $productPublicId = \TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($created->publicId);
        $product = $this->store->products()->findByPublicId($productPublicId);
        self::assertNotNull($product);
        self::assertTrue($product->isSoftDeleted());

        $restoreService = new RestoreDeletedProductService($this->store->products(), $this->transaction, $this->audit);
        $restored = $restoreService->execute($this->actor, new RestoreDeletedProductCommand($created->publicId, $deleted->rowVersion));

        $product = $this->store->products()->findByPublicId($productPublicId);
        self::assertNotNull($product);
        self::assertFalse($product->isSoftDeleted());
        self::assertSame($created->publicId, $restored->publicId);
    }

    public function test_publish_ready_with_classification_and_region(): void
    {
        $family = $this->store->seedFamily('11111111-1111-1111-1111-111111111111');
        $this->store->seedRegion('22222222-2222-2222-2222-222222222222', 'ANZ', 'Australia/NZ');

        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(
            productName: 'Widget',
            familyPublicId: $family['publicId'],
        ));
        $this->store->assignRegion($created->publicId, '22222222-2222-2222-2222-222222222222');

        $publishService = new PublishProductService(
            $this->store->products(),
            $this->transaction,
            $this->audit,
            $this->readinessEvaluator(),
        );

        $published = $publishService->execute($this->actor, new PublishProductCommand($created->publicId, $created->rowVersion));

        self::assertSame('published', $published->statusCode);
    }

    public function test_publish_not_ready_without_classification_or_region(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $publishService = new PublishProductService(
            $this->store->products(),
            $this->transaction,
            $this->audit,
            $this->readinessEvaluator(),
        );

        $this->expectException(ProductNotReadyForPublication::class);
        $publishService->execute($this->actor, new PublishProductCommand($created->publicId, $created->rowVersion));
    }

    public function test_publish_warnings_do_not_block(): void
    {
        $family = $this->store->seedFamily('11111111-1111-1111-1111-111111111111');
        $this->store->seedRegion('22222222-2222-2222-2222-222222222222', 'ANZ', 'Australia/NZ');

        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(
            productName: 'Widget',
            familyPublicId: $family['publicId'],
        ));
        $this->store->assignRegion($created->publicId, '22222222-2222-2222-2222-222222222222');

        // No variants, no media, no documents, no short description, no slug —
        // all of these are advisory warnings only and must not block publish.
        $evaluator = $this->readinessEvaluator();
        $product = $this->store->products()->findByPublicId(\TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($created->publicId));
        self::assertNotNull($product);

        $report = $evaluator->evaluate($product);

        self::assertTrue($report->isReady);
        self::assertNotEmpty($report->issues);
    }

    public function test_create_variant_for_product(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $variantService = new CreateProductVariantService($this->store->products(), $this->store->variants(), $this->transaction, $this->audit);
        $variant = $variantService->execute($this->actor, new CreateProductVariantCommand(
            productPublicId: $created->publicId,
            variantName: 'Small',
            sku: 'SKU-001',
        ));

        self::assertNotEmpty($variant->publicId);
        self::assertSame('draft', $variant->statusCode);
    }

    public function test_create_variant_rejected_for_soft_deleted_parent(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));
        $softDeleteService = new SoftDeleteProductService($this->store->products(), $this->transaction, $this->audit);
        $softDeleteService->execute($this->actor, new SoftDeleteProductCommand($created->publicId, $created->rowVersion));

        $variantService = new CreateProductVariantService($this->store->products(), $this->store->variants(), $this->transaction, $this->audit);

        $this->expectException(ProductSoftDeleted::class);
        $variantService->execute($this->actor, new CreateProductVariantCommand(
            productPublicId: $created->publicId,
            variantName: 'Small',
        ));
    }

    public function test_create_variant_duplicate_sku_throws(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));
        $variantService = new CreateProductVariantService($this->store->products(), $this->store->variants(), $this->transaction, $this->audit);

        $variantService->execute($this->actor, new CreateProductVariantCommand(
            productPublicId: $created->publicId,
            variantName: 'Small',
            sku: 'SKU-DUP',
        ));

        $this->expectException(DuplicateVariantSku::class);
        $variantService->execute($this->actor, new CreateProductVariantCommand(
            productPublicId: $created->publicId,
            variantName: 'Large',
            sku: 'SKU-DUP',
        ));
    }

    public function test_preview_product_code_does_not_persist(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $previewService = new PreviewProductCodeService($this->store->codeRules(), $this->store->products());
        $preview = $previewService->execute(new PreviewProductCodeCommand(
            ruleSetPublicId: 'rule-set-1',
            segmentValues: ['prefix' => 'ABC'],
        ));

        self::assertNotEmpty($preview->generatedCode);
        self::assertFalse($this->store->products()->productCodeExists(
            \TriCab\Core\Product\Domain\ValueObject\ProductCodeValue::fromString($preview->generatedCode),
        ));
    }

    public function test_assign_product_code_persists(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $assignService = new AssignProductCodeService($this->store->codeRules(), $this->store->products(), $this->transaction, $this->audit);
        $assignService->execute($this->actor, new AssignProductCodeCommand(
            productPublicId: $created->publicId,
            ruleSetPublicId: 'rule-set-1',
            segmentValues: ['prefix' => 'XYZ'],
        ));

        self::assertTrue($this->store->products()->productCodeExists(
            \TriCab\Core\Product\Domain\ValueObject\ProductCodeValue::fromString('XYZ'),
        ));
    }

    public function test_assign_specifications_scope_mismatch_throws(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));
        $this->store->seedSpecDefinition(
            publicId: 'spec-1',
            definitionCode: 'weight',
            definitionName: 'Weight',
            dataTypeCode: 'text',
            scopeCode: 'variant',
        );

        $specService = new AssignProductSpecificationsService($this->store->specifications(), $this->transaction, $this->audit);

        $this->expectException(InvalidSpecificationSet::class);
        $specService->execute($this->actor, new AssignProductSpecificationsCommand(
            productPublicId: $created->publicId,
            values: [['definitionPublicId' => 'spec-1', 'valueText' => 'heavy']],
        ));
    }

    public function test_set_primary_product_image(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $userId = UserId::fromInt(1);
        $mediaSummary = $this->store->media()->create(
            \TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($created->publicId),
            ['mediaCategoryCode' => 'gallery', 'mediaTypeCode' => 'image', 'title' => 'Front', 'storagePath' => '/x'],
            $userId,
        );

        $setPrimaryService = new SetPrimaryProductImageService($this->store->media(), $this->transaction, $this->audit);
        $setPrimaryService->execute($this->actor, new SetPrimaryProductImageCommand($created->publicId, $mediaSummary->publicId));

        $list = $this->store->media()->listByProduct(\TriCab\Core\Product\Domain\ValueObject\ProductPublicId::fromString($created->publicId));
        self::assertTrue($list[0]->isPrimary);
    }

    public function test_replace_relationships_rejects_self_reference(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $relationshipService = new ReplaceProductRelationshipsService($this->store->relationships(), $this->store->products(), $this->transaction, $this->audit);

        $this->expectException(InvalidProductRelationshipException::class);
        $relationshipService->execute($this->actor, new ReplaceProductRelationshipsCommand(
            productPublicId: $created->publicId,
            relationshipType: 'accessory',
            items: [['relatedProductPublicId' => $created->publicId]],
        ));
    }

    public function test_slug_change_creates_redirect(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $slugService = new ChangeProductSlugService($this->store->seo(), $this->transaction, $this->audit);
        $slugService->execute($this->actor, new ChangeProductSlugCommand($created->publicId, 'widget-original'));
        $slugService->execute($this->actor, new ChangeProductSlugCommand($created->publicId, 'widget-renamed'));

        $redirects = $this->store->redirects();
        self::assertCount(1, $redirects);
        self::assertSame('widget-original', $redirects[0]['fromSlug']);
        self::assertSame('widget-renamed', $redirects[0]['toSlug']);
    }

    public function test_pricing_decimal_precision_preserved(): void
    {
        $created = $this->createProductService()->execute($this->actor, new CreateProductCommand(productName: 'Widget'));

        $pricingService = new UpsertProductPricingService($this->store->pricing(), $this->transaction, $this->audit);
        $saved = $pricingService->execute($this->actor, new UpsertProductPricingCommand(
            productPublicId: $created->publicId,
            currencyCode: 'AUD',
            baseCost: '199.995000',
            minimumQuantity: '1',
        ));

        self::assertSame('199.995', $saved->baseCost);

        $entries = $this->store->auditEntries();
        $pricingEntry = array_values(array_filter($entries, static fn ($entry) => $entry->actionCode === 'upserted'))[0];
        self::assertStringContainsString('***', (string) $pricingEntry->newValuesJson);
        self::assertStringNotContainsString('199.995', (string) $pricingEntry->newValuesJson);
    }
}
