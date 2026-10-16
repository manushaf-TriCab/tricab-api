<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductPublishReadinessCheck;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessIssue;
use TriCab\Core\Product\Application\DTO\ProductPublishReadinessReport;
use TriCab\Core\Product\Application\Exception\ProductNotReadyForPublication;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Contract\ProductPublishReadinessEvaluator;
use TriCab\Core\Product\Contract\ProductRelationshipRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;

/**
 * Cross-aggregate publication readiness (Phase 4). Only requires evidence-based
 * checks: classification and at least one active region are hard requirements;
 * variants / media / short description are advisory warnings.
 * Documents are optional and never affect readiness.
 */
final class CrossAggregateProductPublishReadinessEvaluator implements ProductPublishReadinessEvaluator
{
    public function __construct(
        private readonly ProductAdminQueryRepository $adminQuery,
        private readonly ProductMediaRepository $media,
        private readonly ProductRelationshipRepository $relationships,
    ) {
    }

    public function assertReadyToPublish(Product $product): void
    {
        $report = $this->evaluate($product);

        if (!$report->isReady) {
            throw ProductNotReadyForPublication::forReport($product->publicId()->toString(), $report);
        }
    }

    public function evaluate(Product $product): ProductPublishReadinessReport
    {
        $issues = [];
        $checks = [];

        // Always true by construction — Product enforces a non-empty name and a
        // valid publish/unpublish window in its constructor.
        $checks[] = new ProductPublishReadinessCheck('product.name.present', true);
        $checks[] = new ProductPublishReadinessCheck('publishWindow.valid', true);

        $hasClassification = $product->familyId() !== null || $product->categoryId() !== null;
        $checks[] = new ProductPublishReadinessCheck('classification.present', $hasClassification);
        if (!$hasClassification) {
            $issues[] = new ProductPublishReadinessIssue(
                code: 'classification.missing',
                area: 'classification',
                message: 'Product requires at least a family or a category before publication.',
                severity: ProductPublishReadinessIssue::SEVERITY_ERROR,
                entityPublicId: $product->publicId()->toString(),
            );
        }

        $detail = $this->adminQuery->findDetailByPublicId($product->publicId());

        $hasRegion = $detail !== null && $detail->regionCodes !== [];
        $checks[] = new ProductPublishReadinessCheck('market.region.present', $hasRegion);
        if (!$hasRegion) {
            $issues[] = new ProductPublishReadinessIssue(
                code: 'market.region.missing',
                area: 'market',
                message: 'Product requires at least one active region assignment before publication.',
                severity: ProductPublishReadinessIssue::SEVERITY_ERROR,
                entityPublicId: $product->publicId()->toString(),
            );
        }

        $hasVariants = $detail !== null && $detail->variants !== [];
        $checks[] = new ProductPublishReadinessCheck('variant.present', $hasVariants);
        if (!$hasVariants) {
            $issues[] = new ProductPublishReadinessIssue(
                code: 'variant.none',
                area: 'variant',
                message: 'Product has no variants.',
                severity: ProductPublishReadinessIssue::SEVERITY_WARNING,
                entityPublicId: $product->publicId()->toString(),
            );
        }

        $hasPrimarySlug = $detail !== null && $detail->primarySlug !== null;
        $checks[] = new ProductPublishReadinessCheck('seo.primarySlug.present', $hasPrimarySlug);
        if (!$hasPrimarySlug) {
            $issues[] = new ProductPublishReadinessIssue(
                code: 'seo.slug.missing',
                area: 'seo',
                message: 'Product has no primary slug assigned.',
                severity: ProductPublishReadinessIssue::SEVERITY_WARNING,
                entityPublicId: $product->publicId()->toString(),
            );
        }

        $mediaItems = $this->media->listByProduct($product->publicId());
        $checks[] = new ProductPublishReadinessCheck('media.present', $mediaItems !== []);
        if ($mediaItems === []) {
            $issues[] = new ProductPublishReadinessIssue(
                code: 'media.none',
                area: 'media',
                message: 'Product has no media.',
                severity: ProductPublishReadinessIssue::SEVERITY_WARNING,
                entityPublicId: $product->publicId()->toString(),
            );
        }

        $hasShortDescription = trim((string) $product->shortDescription()) !== '';
        $checks[] = new ProductPublishReadinessCheck('shortDescription.present', $hasShortDescription);
        if (!$hasShortDescription) {
            $issues[] = new ProductPublishReadinessIssue(
                code: 'shortDescription.empty',
                area: 'content',
                message: 'Product has no short description.',
                severity: ProductPublishReadinessIssue::SEVERITY_WARNING,
                entityPublicId: $product->publicId()->toString(),
                field: 'shortDescription',
            );
        }

        if ($product->lifecycleStatus()->equals(ProductLifecycleStatusCode::replaced())) {
            $replacements = $this->relationships->listByProduct($product->publicId(), ProductRelationshipType::replacement());
            $hasReplacement = $replacements !== [];
            $checks[] = new ProductPublishReadinessCheck('lifecycle.replacement.present', $hasReplacement);
            if (!$hasReplacement) {
                $issues[] = new ProductPublishReadinessIssue(
                    code: 'lifecycle.replacement.missing',
                    area: 'lifecycle',
                    message: 'Lifecycle status is "replaced" but no replacement relationship exists.',
                    severity: ProductPublishReadinessIssue::SEVERITY_ERROR,
                    entityPublicId: $product->publicId()->toString(),
                );
            }
        }

        $hasErrors = $this->hasBlockingIssue($issues);

        return new ProductPublishReadinessReport(
            isReady: !$hasErrors,
            issues: $issues,
            checks: $checks,
        );
    }

    /**
     * @param list<ProductPublishReadinessIssue> $issues
     */
    private function hasBlockingIssue(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }
}
