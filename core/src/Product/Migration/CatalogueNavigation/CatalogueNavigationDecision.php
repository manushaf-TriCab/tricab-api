<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\CatalogueNavigation;

/**
 * Additive-only catalogue navigation mapping decisions.
 * ProductCategory / Family / Series are never catalogue nodes.
 */
final class CatalogueNavigationDecision
{
    public const MAP_EXISTING_NODE = 'MAP_EXISTING_NODE';
    public const EXISTING_IDENTICAL = 'EXISTING_IDENTICAL';
    public const REVIEW_AMBIGUOUS = 'REVIEW_AMBIGUOUS';
    public const REVIEW_TARGET_NODE_MISSING = 'REVIEW_TARGET_NODE_MISSING';
    public const REVIEW_CONFLICT = 'REVIEW_CONFLICT';
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const ACTION_INSERT = 'INSERT';
    public const ACTION_NOOP = 'NOOP';
    public const ACTION_REVIEW = 'REVIEW';

    public const OWNERSHIP_PRE_EXISTING_PROTECTED = 'PRE_EXISTING_PROTECTED';
    public const OWNERSHIP_MIGRATION_CREATED = 'MIGRATION_CREATED';

    public const CONFIDENCE_HIGH = 'HIGH';
    public const CONFIDENCE_NONE = 'NONE';

    public const EVIDENCE_EXPLICIT_APPROVED_MAP = 'exact_approved_mapping_artifact';
    public const EVIDENCE_EXACT_NODE_CODE = 'exact_stable_node_code';
    public const EVIDENCE_EXACT_PATH = 'exact_path_correspondence';
    public const EVIDENCE_NAME_SIMILARITY_REJECTED = 'name_similarity_insufficient';
    public const EVIDENCE_PRODUCT_CATEGORY_REJECTED = 'product_category_is_not_catalogue_node';
    public const EVIDENCE_REGION_TREE_REJECTED = 'region_must_not_duplicate_catalogue_tree';
}