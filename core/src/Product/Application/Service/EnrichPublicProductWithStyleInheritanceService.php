<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\EffectiveStyleAsset;
use TriCab\Core\Product\Application\DTO\Product360FrameSummary;
use TriCab\Core\Product\Application\DTO\Product360SetSummary;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\DTO\ProductPublic3DModel;
use TriCab\Core\Product\Application\DTO\ProductPublic3DPayload;
use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Application\DTO\ProductPublicSpecificationItem;
use TriCab\Core\Product\Application\Service\ProductThumbnailImageResolver;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

/**
 * Applies style-inherited specifications/media onto a public product detail
 * without exposing admin provenance or inheritance controls.
 */
final class EnrichPublicProductWithStyleInheritanceService
{
    public function __construct(
        private readonly ProductStyleRepository $styles,
        private readonly ResolveEffectiveStyleResourcesService $effective,
    ) {
    }

    public function enrich(ProductPublicDetail $detail): ProductPublicDetail
    {
        try {
            if ($this->styles->findAssignmentByProductPublicId($detail->publicId) === null) {
                return $detail;
            }

            return $this->enrichAssigned($detail);
        } catch (\Throwable) {
            return $detail;
        }
    }

    private function enrichAssigned(ProductPublicDetail $detail): ProductPublicDetail
    {

        $specifications = [];
        foreach ($this->effective->specificationsForProduct($detail->publicId) as $item) {
            if ($item->displayValue === null || $item->displayValue === '') {
                continue;
            }
            $specifications[] = new ProductPublicSpecificationItem(
                code: $item->definitionCode,
                name: $item->definitionName,
                groupName: null,
                displayValue: $item->displayValue,
                unit: null,
            );
        }

        $media = $this->mergeMedia(
            $this->effective->assetsForProduct($detail->publicId, StyleResourceType::images()),
            $detail->media,
        );
        $documents = $this->mergeDocuments(
            $this->effective->assetsForProduct($detail->publicId, StyleResourceType::fromString(StyleResourceType::DOCUMENTS)),
            $detail->documents,
        );

        $thumbnailUrl = $detail->thumbnailUrl;
        foreach ($media as $item) {
            if ($item->isPrimary && $item->publicUrl !== null) {
                $thumbnailUrl = $item->publicUrl;
                break;
            }
        }
        $thumbnailImageUrl = ProductThumbnailImageResolver::fromMediaSummaries($media);

        return new ProductPublicDetail(
            publicId: $detail->publicId,
            productName: $detail->productName,
            productCode: $detail->productCode,
            shortDescription: $detail->shortDescription,
            fullDescription: $detail->fullDescription,
            familyName: $detail->familyName,
            categoryName: $detail->categoryName,
            seriesName: $detail->seriesName,
            slug: $detail->slug,
            thumbnailUrl: $thumbnailUrl,
            thumbnailImageUrl: $thumbnailImageUrl,
            thumbnailBackgroundColor: $detail->thumbnailBackgroundColor,
            variants: $detail->variants,
            media: $media,
            documents: $documents,
            applicationNames: $detail->applicationNames,
            industryNames: $detail->industryNames,
            relationships: $detail->relationships,
            specifications: $specifications,
            approvalCertificates: $detail->approvalCertificates,
            threeD: $this->mergeAr(
                $this->effective->assetsForProduct($detail->publicId, StyleResourceType::fromString(StyleResourceType::AR_ASSETS)),
                $this->mergeThreeD(
                    $this->effective->assetsForProduct($detail->publicId, StyleResourceType::fromString(StyleResourceType::MODELS_3D)),
                    $detail->threeD,
                ),
            ),
            descriptionImageWidth: $detail->descriptionImageWidth,
            descriptionImageAlignment: $detail->descriptionImageAlignment,
            pageContent: $detail->pageContent,
            spin360: $this->mergeSpin360(
                $this->effective->assetsForProduct($detail->publicId, StyleResourceType::fromString(StyleResourceType::SPIN_360)),
                $detail,
            ),
        );
    }

    /**
     * @param list<EffectiveStyleAsset> $inherited
     * @param list<ProductMediaSummary> $productMedia
     * @return list<ProductMediaSummary>
     */
    private function mergeMedia(array $inherited, array $productMedia): array
    {
        $out = [];
        foreach ($inherited as $asset) {
            $out[] = new ProductMediaSummary(
                publicId: is_string($asset->asset['publicId'] ?? null) ? $asset->asset['publicId'] : $asset->title,
                mediaCategoryCode: 'image',
                mediaTypeCode: 'image',
                title: $asset->title,
                publicUrl: $asset->publicUrl,
                altText: isset($asset->asset['altText']) && is_string($asset->asset['altText']) ? $asset->asset['altText'] : null,
                displayOrder: $asset->displayOrder,
                isPrimary: $asset->isPrimary || strtolower((string) $asset->roleCode) === 'hero' || strtolower((string) $asset->roleCode) === 'primary',
                storagePath: $asset->storagePath,
                storageContainer: $asset->storageContainer,
            );
        }
        foreach ($productMedia as $item) {
            $replaced = false;
            if ($item->isPrimary) {
                foreach ($out as $i => $existing) {
                    if ($existing->isPrimary) {
                        $out[$i] = $item;
                        $replaced = true;
                        break;
                    }
                }
            }
            if (!$replaced) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<EffectiveStyleAsset> $inherited
     * @param list<ProductDocumentSummary> $productDocuments
     * @return list<ProductDocumentSummary>
     */
    private function mergeDocuments(array $inherited, array $productDocuments): array
    {
        $out = [];
        $paths = [];
        foreach ($inherited as $asset) {
            if ($this->isRestrictedDocument($asset)) {
                continue;
            }
            $path = $asset->storagePath ?? $asset->title;
            $paths[$path] = true;
            $out[] = new ProductDocumentSummary(
                publicId: is_string($asset->asset['publicId'] ?? null) ? $asset->asset['publicId'] : $asset->title,
                documentTypeCode: is_string($asset->asset['roleCode'] ?? null) ? $asset->asset['roleCode'] : 'document',
                title: $asset->title,
                versionLabel: null,
                languageCode: null,
                publicUrl: $asset->publicUrl,
                accessCode: $this->documentAccessCode($asset),
                displayOrder: $asset->displayOrder,
                storagePath: $asset->storagePath,
                storageContainer: $asset->storageContainer,
            );
        }
        foreach ($productDocuments as $item) {
            $path = $item->storagePath ?? $item->title;
            if (isset($paths[$path])) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param list<EffectiveStyleAsset> $inherited
     */
    private function mergeThreeD(array $inherited, ProductPublic3DPayload $existing): ProductPublic3DPayload
    {
        $models = $existing->models;
        $urls = [];
        foreach ($models as $model) {
            $urls[$model->url] = true;
        }
        foreach ($inherited as $asset) {
            $url = $asset->publicUrl ?? '';
            $path = $asset->storagePath ?? '';
            if (($url === '' && $path === '') || ($url !== '' && isset($urls[$url])) || ($path !== '' && isset($urls[$path]))) {
                continue;
            }
            if ($url !== '') {
                $urls[$url] = true;
            }
            if ($path !== '') {
                $urls[$path] = true;
            }
            $models[] = new ProductPublic3DModel(
                publicId: is_string($asset->asset['publicId'] ?? null) ? $asset->asset['publicId'] : $asset->title,
                name: $asset->title,
                modelType: $this->modelTypeFromAsset($asset, 'glb'),
                url: $url !== '' ? $url : $path,
                previewImageUrl: null,
                viewerConfiguration: [],
                isPrimary: $asset->isPrimary,
                productVariantPublicId: null,
                storageContainer: $asset->storageContainer,
                storagePath: $asset->storagePath,
            );
        }
        if ($models === $existing->models) {
            return $existing;
        }
        $primary = $existing->primary;
        foreach ($models as $model) {
            if ($model->isPrimary) {
                $primary = $model;
                break;
            }
        }
        if ($primary === null && $models !== []) {
            $primary = $models[0];
        }

        return new ProductPublic3DPayload(available: $models !== [], primary: $primary, models: $models);
    }

    /**
     * @param list<EffectiveStyleAsset> $inherited
     */
    private function mergeAr(array $inherited, ProductPublic3DPayload $existing): ProductPublic3DPayload
    {
        return $this->mergeThreeD($inherited, $existing);
    }

    /**
     * @param list<EffectiveStyleAsset> $inherited
     * @return list<Product360SetSummary>
     */
    private function mergeSpin360(array $inherited, ProductPublicDetail $detail): array
    {
        if ($detail->spin360 !== []) {
            return $detail->spin360;
        }
        if ($inherited === []) {
            return [];
        }

        $frames = [];
        foreach ($inherited as $index => $asset) {
            $publicId = is_string($asset->asset['publicId'] ?? null) ? $asset->asset['publicId'] : $asset->title;
            $frames[] = new Product360FrameSummary(
                publicId: $publicId,
                setPublicId: $detail->publicId,
                frameNumber: $asset->displayOrder > 0 ? $asset->displayOrder : ($index + 1),
                uploadStatusCode: 'active',
                scanStatusCode: 'not_required',
                publicUrl: $asset->publicUrl,
                storagePath: $asset->storagePath,
                storageContainer: $asset->storageContainer,
                contentType: is_string($asset->asset['contentType'] ?? null) ? $asset->asset['contentType'] : null,
                byteSize: isset($asset->asset['byteSize']) && is_int($asset->asset['byteSize']) ? $asset->asset['byteSize'] : null,
                originalFileName: is_string($asset->asset['originalFileName'] ?? null) ? $asset->asset['originalFileName'] : $asset->title,
            );
        }

        return [
            new Product360SetSummary(
                publicId: $detail->publicId,
                productPublicId: $detail->publicId,
                title: '360°',
                expectedFrameCount: count($frames),
                uploadedFrameCount: count($frames),
                statusCode: 'active',
                isActive: true,
                frames: $frames,
            ),
        ];
    }

    private function isRestrictedDocument(EffectiveStyleAsset $asset): bool
    {
        $access = $this->documentAccessCode($asset);

        return !in_array(strtolower($access), ['public', 'published'], true);
    }

    private function documentAccessCode(EffectiveStyleAsset $asset): string
    {
        $metadata = is_array($asset->asset['metadata'] ?? null) ? $asset->asset['metadata'] : [];
        if (isset($metadata['accessCode']) && is_string($metadata['accessCode']) && $metadata['accessCode'] !== '') {
            return $metadata['accessCode'];
        }
        if (isset($asset->asset['accessCode']) && is_string($asset->asset['accessCode']) && $asset->asset['accessCode'] !== '') {
            return $asset->asset['accessCode'];
        }

        return 'public';
    }

    private function modelTypeFromAsset(EffectiveStyleAsset $asset, string $fallback): string
    {
        $path = strtolower((string) ($asset->storagePath ?? $asset->title));
        if (str_ends_with($path, '.usdz')) {
            return 'usdz';
        }
        if (str_ends_with($path, '.glb')) {
            return $asset->resourceType === StyleResourceType::AR_ASSETS ? 'glb' : 'glb';
        }

        return $fallback;
    }
}
