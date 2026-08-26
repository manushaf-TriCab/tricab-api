<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DomainException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Core\Product\Application\Exception\CatalogueConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\CatalogueInUse;
use TriCab\Core\Product\Application\Exception\CatalogueNotFound;
use TriCab\Core\Product\Application\Exception\DuplicateCatalogueCode;
use TriCab\Core\Product\Application\Exception\DuplicateCatalogueSlug;
use TriCab\Core\Product\Application\Exception\InvalidCatalogueHierarchy;
use TriCab\Core\Product\Application\Exception\DuplicateProductCode;
use TriCab\Core\Product\Application\Exception\DuplicateProductSlug;
use TriCab\Core\Product\Application\Exception\DuplicateVariantSku;
use TriCab\Core\Product\Application\Exception\InvalidMarketAssignment;
use TriCab\Core\Product\Application\Exception\InvalidProductClassification;
use TriCab\Core\Product\Application\Exception\InvalidProductRelationshipAssignment;
use TriCab\Core\Product\Application\Exception\InvalidSpecificationSet;
use TriCab\Core\Product\Application\Exception\ProductArchived;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\Product3DAssetNotFound;
use TriCab\Core\Product\Application\Exception\Product3DAssetProcessingException;
use TriCab\Core\Product\Application\Exception\Product3DModelNotFound;
use TriCab\Core\Product\Application\Exception\ProductDocumentNotFound;
use TriCab\Core\Product\Application\Exception\ProductFileUploadException;
use TriCab\Core\Product\Application\Exception\ProductMediaNotFound;
use TriCab\Core\Product\Application\Exception\OverlappingBaseCostRecord;
use TriCab\Core\Product\Application\Exception\ProductPricingConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\DuplicateLegacyPriceContext;
use TriCab\Core\Product\Application\Exception\DuplicatePriceListCode;
use TriCab\Core\Product\Application\Exception\InvalidPricingUnit;
use TriCab\Core\Product\Application\Exception\InvalidPriceListCurrency;
use TriCab\Core\Product\Application\Exception\PriceListConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\PriceListInUse;
use TriCab\Core\Product\Application\Exception\PriceListNotFound;
use TriCab\Core\Product\Application\Exception\ProductClassificationConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductClassificationInUse;
use TriCab\Core\Product\Application\Exception\DuplicateProductClassificationCode;
use TriCab\Core\Product\Application\Exception\DuplicateProductStyleCode;
use TriCab\Core\Product\Application\Exception\InvalidProductStyleHierarchy;
use TriCab\Core\Product\Application\Exception\ProductClassificationNotFound;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Application\Exception\ProductStyleConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductStyleInUse;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Application\Exception\DuplicateSpecificationCode;
use TriCab\Core\Product\Application\Exception\SpecificationMasterConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\SpecificationMasterInUse;
use TriCab\Core\Product\Application\Exception\SpecificationMasterNotFound;
use TriCab\Core\Product\Domain\Exception\InvalidProductFileException;
use TriCab\Core\Product\Application\Exception\ProductNotReadyForPublication;
use TriCab\Core\Product\Application\Exception\ProductSoftDeleted;
use TriCab\Core\Product\Application\Exception\ProductVariantNotFound;
use TriCab\Core\Product\Application\Exception\DuplicateRegionCode;
use TriCab\Core\Product\Application\Exception\InvalidRegion;
use TriCab\Core\Product\Application\Exception\RegionInUse;
use TriCab\Core\Product\Application\Exception\RegionNotFound;
use TriCab\Core\Product\Domain\Exception\InvalidProductLifecycleException;
use TriCab\Core\Product\Domain\Exception\InvalidProductRelationshipException;
use TriCab\Core\Product\Domain\Exception\InvalidProductStatusTransition;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\Exception\InvalidSpecificationValueException;

/**
 * Maps Core Product application/domain exceptions to safe JSON errors for
 * the admin/public Product endpoints. Order matters: more specific exception
 * types must be checked before their broader DomainException/RuntimeException
 * ancestors.
 */
final class ProductManagementErrorMapper
{
    public function __construct(
        private readonly JsonResponder $responder,
    ) {
    }

    public function map(Throwable $exception): ResponseInterface
    {
        if ($exception instanceof HttpRequestValidationFailed) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof DatabaseTemporarilyUnavailable) {
            return $this->responder->error(
                'DATABASE_TEMPORARILY_UNAVAILABLE',
                'The database is temporarily unavailable.',
                503,
            );
        }

        if ($exception instanceof DatabaseUnavailable) {
            if ($exception->isForeignKeyViolation()) {
                return $this->responder->error(
                    'REFERENCE_IN_USE',
                    'This record is referenced by other data and cannot be deleted yet.',
                    422,
                );
            }

            if ($exception->isInvalidObjectReference()) {
                $previousMessage = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
                error_log('Database schema mismatch: ' . $previousMessage);

                $regionRelated = self::mentionsRegionSchema($previousMessage);
                if ($regionRelated) {
                    return $this->responder->error(
                        'DATABASE_SCHEMA_MISMATCH',
                        'The database schema is missing a required region table or column. Run the latest region migration scripts.',
                        503,
                    );
                }

                return $this->responder->error(
                    'DATABASE_SCHEMA_MISMATCH',
                    'The database schema is missing a required table or column for this product operation. Check API logs for the underlying SQL object name.',
                    503,
                );
            }

            return $this->responder->error('DATABASE_UNAVAILABLE', 'The database is temporarily unavailable.', 503);
        }

        if ($exception instanceof ProductNotFound) {
            return $this->responder->error('PRODUCT_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof RegionNotFound) {
            return $this->responder->error('REGION_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof DuplicateRegionCode) {
            return $this->responder->error('DUPLICATE_REGION_CODE', $exception->getMessage(), 409);
        }

        if ($exception instanceof RegionInUse) {
            return $this->responder->error('REGION_IN_USE', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidRegion) {
            return $this->responder->error('INVALID_REGION', $exception->getMessage(), 422);
        }

        if ($exception instanceof ProductStyleNotFound) {
            return $this->responder->error('PRODUCT_STYLE_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ProductStyleConcurrencyConflict) {
            return $this->responder->error('PRODUCT_STYLE_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateProductStyleCode) {
            return $this->responder->error('DUPLICATE_PRODUCT_STYLE_CODE', $exception->getMessage(), 409);
        }

        if ($exception instanceof ProductStyleInUse) {
            return $this->responder->error('PRODUCT_STYLE_IN_USE', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidProductStyleHierarchy) {
            return $this->responder->error('INVALID_PRODUCT_STYLE_HIERARCHY', $exception->getMessage(), 422);
        }

        if ($exception instanceof ProductClassificationNotFound) {
            return $this->responder->error('PRODUCT_CLASSIFICATION_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ProductClassificationConcurrencyConflict) {
            return $this->responder->error('PRODUCT_CLASSIFICATION_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateProductClassificationCode) {
            return $this->responder->error('DUPLICATE_PRODUCT_CLASSIFICATION_CODE', $exception->getMessage(), 409);
        }

        if ($exception instanceof ProductClassificationInUse) {
            return $this->responder->error('PRODUCT_CLASSIFICATION_IN_USE', $exception->getMessage(), 422);
        }

        if ($exception instanceof PriceListNotFound) {
            return $this->responder->error('PRICE_LIST_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof PriceListConcurrencyConflict) {
            return $this->responder->error('PRICE_LIST_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicatePriceListCode) {
            return $this->responder->error('DUPLICATE_PRICE_LIST_CODE', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateLegacyPriceContext) {
            return $this->responder->error('DUPLICATE_LEGACY_PRICE_CONTEXT', $exception->getMessage(), 409);
        }

        if ($exception instanceof PriceListInUse) {
            return $this->responder->error('PRICE_LIST_IN_USE', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidPriceListCurrency) {
            return $this->responder->error('INVALID_PRICE_LIST_CURRENCY', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidPricingUnit) {
            return $this->responder->error('INVALID_PRICING_UNIT', $exception->getMessage(), 422);
        }

        if ($exception instanceof CatalogueNotFound) {
            return $this->responder->error('CATALOGUE_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof CatalogueConcurrencyConflict) {
            return $this->responder->error('CATALOGUE_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateCatalogueCode) {
            return $this->responder->error('DUPLICATE_CATALOGUE_CODE', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateCatalogueSlug) {
            return $this->responder->error('DUPLICATE_CATALOGUE_SLUG', $exception->getMessage(), 409);
        }

        if ($exception instanceof CatalogueInUse) {
            return $this->responder->error('CATALOGUE_IN_USE', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidCatalogueHierarchy) {
            return $this->responder->error('INVALID_CATALOGUE_HIERARCHY', $exception->getMessage(), 422);
        }

        if ($exception instanceof SpecificationMasterNotFound) {
            return $this->responder->error('PRODUCT_SPECIFICATION_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof SpecificationMasterConcurrencyConflict) {
            return $this->responder->error('PRODUCT_SPECIFICATION_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateSpecificationCode) {
            return $this->responder->error('DUPLICATE_SPECIFICATION_CODE', $exception->getMessage(), 409);
        }

        if ($exception instanceof SpecificationMasterInUse) {
            return $this->responder->error('PRODUCT_SPECIFICATION_IN_USE', $exception->getMessage(), 422);
        }



        if ($exception instanceof ProductMediaNotFound) {
            return $this->responder->error('PRODUCT_MEDIA_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof Product3DModelNotFound) {
            return $this->responder->error('PRODUCT_3D_MODEL_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof Product3DAssetNotFound) {
            return $this->responder->error('PRODUCT_3D_ASSET_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof Product3DAssetProcessingException) {
            return $this->responder->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        }

        if ($exception instanceof ProductDocumentNotFound) {
            return $this->responder->error('PRODUCT_DOCUMENT_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ProductVariantNotFound) {
            return $this->responder->error('PRODUCT_VARIANT_NOT_FOUND', $exception->getMessage(), 404);
        }

        if ($exception instanceof ProductFileUploadException) {
            return $this->responder->error('PRODUCT_FILE_UPLOAD_FAILED', $exception->getMessage(), 422);
        }

        if (
            $exception instanceof RuntimeException
            && str_starts_with($exception->getMessage(), 'Blob ')
        ) {
            return $this->responder->error(
                'PRODUCT_FILE_UPLOAD_FAILED',
                'Azure Blob verification failed during upload completion. Retry the upload. If it persists, check Blob credentials and file name characters.',
                422,
            );
        }

        if ($exception instanceof InvalidProductFileException) {
            return $this->responder->error('INVALID_PRODUCT_FILE', $exception->getMessage(), 422);
        }

        if ($exception instanceof ProductConcurrencyConflict) {
            return $this->responder->error('PRODUCT_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof ProductPricingConcurrencyConflict) {
            return $this->responder->error('PRODUCT_PRICING_CONCURRENCY_CONFLICT', $exception->getMessage(), 409);
        }

        if ($exception instanceof OverlappingBaseCostRecord) {
            return $this->responder->error('OVERLAPPING_BASE_COST_RECORD', $exception->getMessage(), 422);
        }

        if ($exception instanceof DuplicateProductCode) {
            return $this->responder->error('DUPLICATE_PRODUCT_CODE', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateProductSlug) {
            return $this->responder->error('DUPLICATE_PRODUCT_SLUG', $exception->getMessage(), 409);
        }

        if ($exception instanceof DuplicateVariantSku) {
            return $this->responder->error('DUPLICATE_VARIANT_SKU', $exception->getMessage(), 409);
        }

        if ($exception instanceof ProductNotReadyForPublication) {
            return $this->responder->error('PRODUCT_NOT_READY_FOR_PUBLICATION', $exception->getMessage(), 422);
        }

        if ($exception instanceof ProductSoftDeleted) {
            return $this->responder->error('PRODUCT_SOFT_DELETED', $exception->getMessage(), 422);
        }

        if ($exception instanceof ProductArchived) {
            return $this->responder->error('PRODUCT_ARCHIVED', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidProductClassification) {
            return $this->responder->error('INVALID_PRODUCT_CLASSIFICATION', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidSpecificationSet) {
            return $this->responder->error('INVALID_SPECIFICATION_SET', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidProductRelationshipAssignment) {
            return $this->responder->error('INVALID_PRODUCT_RELATIONSHIP_ASSIGNMENT', $exception->getMessage(), 422);
        }

        if ($exception instanceof InvalidMarketAssignment) {
            return $this->responder->error('INVALID_MARKET_ASSIGNMENT', $exception->getMessage(), 422);
        }

        if (
            $exception instanceof InvalidProductValue
            || $exception instanceof InvalidProductStatusTransition
            || $exception instanceof InvalidProductLifecycleException
            || $exception instanceof InvalidSpecificationValueException
            || $exception instanceof InvalidProductRelationshipException
            || $exception instanceof InvalidArgumentException
            || $exception instanceof DomainException
        ) {
            return $this->responder->error('VALIDATION_FAILED', $exception->getMessage(), 422);
        }

        if ($exception instanceof RuntimeException) {
            return $this->responder->error('VALIDATION_FAILED', 'The request could not be processed.', 422);
        }

        return $this->responder->error('INTERNAL_ERROR', 'An unexpected error occurred.', 500);
    }

    /**
     * Only attribute schema mismatches to region migrations when the driver message
     * names a region-related object. Pricing/UOM/other schema gaps must not surface
     * as "run the latest region migration scripts".
     */
    private static function mentionsRegionSchema(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'region')
            || str_contains($lower, 'productregions')
            || str_contains($lower, 'marketregions');
    }
}
