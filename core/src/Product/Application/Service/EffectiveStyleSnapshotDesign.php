<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

/**
 * Design-only documentation for effective Style read model (not applied in dry-run).
 */
final class EffectiveStyleSnapshotDesign
{
    /** @return array<string, mixed> */
    public static function architectureAudit(): array
    {
        return [
            'productResources' => [
                'specifications' => ['table' => 'ProductSpecificationValues', 'styleOwnership' => false],
                'images' => ['tables' => ['ProductImages', 'ProductMedia'], 'styleOwnership' => false],
                'documents' => ['table' => 'ProductDocuments', 'styleOwnership' => false],
                'markets' => ['table' => 'ProductRegions', 'styleOwnership' => false],
                'spin360' => ['table' => 'Product360Sets', 'styleOwnership' => false],
                'models3d' => ['tables' => ['Product3DModels', 'Product3DModelAssets'], 'styleOwnership' => false],
                'ar' => ['table' => 'ProductARModels', 'styleOwnership' => false],
            ],
            'productStyleResources' => [
                'specifications' => ['table' => 'ProductStyleSpecificationValues', 'styleOwnership' => true],
                'media' => ['table' => 'ProductStyleMediaAssets', 'styleOwnership' => true],
                'documents' => ['table' => 'ProductStyleMediaAssets (DOCUMENTS)', 'styleOwnership' => true],
                'spin360' => ['table' => 'ProductStyleMediaAssets (SPIN_360)', 'styleOwnership' => true],
                'models3d' => ['table' => 'ProductStyleMediaAssets (MODELS_3D)', 'styleOwnership' => true],
                'ar' => ['table' => 'ProductStyleMediaAssets (AR_ASSETS)', 'styleOwnership' => true],
                'markets' => ['table' => null, 'styleOwnership' => false, 'note' => 'No ProductStyleRegions — additive extension required'],
            ],
            'inheritanceResolver' => [
                'service' => 'ResolveEffectiveStyleResourcesService',
                'mode' => 'runtime dynamic ancestor walk',
                'precedence' => 'Variant → Product → current Style → parent → ancestors',
                'materialisedSnapshot' => false,
            ],
            'publicLoadPath' => [
                'service' => 'GetPublicProductService',
                'enrichment' => 'EnrichPublicProductWithStyleInheritanceService',
                'provenanceInPublicDto' => false,
            ],
            'adminLoadPath' => [
                'serializer' => 'ProductManagementDtoSerializer',
                'provenanceInAdminDto' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function design(): array
    {
        return [
            'approach' => 'versioned materialised read model per ProductStyle',
            'headerTable' => 'ProductStyleEffectiveSnapshots',
            'headerColumns' => [
                'ProductStyleId', 'SnapshotVersion', 'BuiltAtUtc', 'IsCurrent', 'BuildStatus', 'SourceHash',
            ],
            'detailTables' => [
                'ProductStyleEffectiveSpecificationValues',
                'ProductStyleEffectiveMediaAssets',
                'ProductStyleEffectiveDocuments',
                'ProductStyleEffectiveRegions',
                'ProductStyleEffectiveSpin360Assets',
                'ProductStyleEffectiveModel3DAssets',
                'ProductStyleEffectiveArAssets',
            ],
            'provenanceFields' => [
                'SourceStyleId', 'SourceStyleCode', 'SourceLevel', 'Inherited', 'SourceRowVersion', 'SnapshotVersion',
            ],
            'swapStrategy' => 'Build new SnapshotVersion in transaction; flip IsCurrent; readers use IsCurrent=1 only',
            'fallback' => 'If snapshot missing/stale/failed → ResolveEffectiveStyleResourcesService (authoritative)',
            'ddlScript' => 'database/scripts/development/create-product-style-effective-snapshots.sql',
            'ddlAppliedInDryRun' => false,
        ];
    }

    /** @return array<string, mixed> */
    public static function invalidationPlan(): array
    {
        return [
            'parentStyleSpecChanged' => 'Rebuild parent + all descendants',
            'childStyleOverrideChanged' => 'Rebuild child + descendants only',
            'parentStyleMediaChanged' => 'Rebuild effective media for affected subtree',
            'parentStyleIdChanged' => 'Rebuild old and new subtrees',
            'inheritanceModeChanged' => 'Rebuild affected Style subtree',
            'styleMarketChanged' => 'Rebuild effective region snapshots for subtree',
            'makeLocalCopy' => 'Rebuild affected Style subtree',
        ];
    }

    /** @return array<string, int> */
    public static function writeGuard(): array
    {
        return [
            'productStyleSourceSpecificationWrites' => 0,
            'productStyleSourceMediaWrites' => 0,
            'productStyleSourceMarketWrites' => 0,
            'productStyleSourceDocumentWrites' => 0,
            'productStyleSource360Writes' => 0,
            'productStyleSource3DWrites' => 0,
            'productStyleSourceArWrites' => 0,
            'inheritanceModeWrites' => 0,
            'productWrites' => 0,
            'variantWrites' => 0,
            'productSpecificationDeletes' => 0,
            'productMediaDeletes' => 0,
            'productRegionDeletes' => 0,
            'blobWrites' => 0,
            'blobCopies' => 0,
            'blobDeletes' => 0,
            'catalogueWrites' => 0,
            'classificationWrites' => 0,
            'wordPressWrites' => 0,
            'tcMySqlWrites' => 0,
            'legacyFilesystemWrites' => 0,
            'productionWrites' => 0,
            'forbiddenWriteAttempts' => 0,
        ];
    }
}
