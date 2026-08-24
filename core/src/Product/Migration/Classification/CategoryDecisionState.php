<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Classification;

final class CategoryDecisionState
{
    public const APPROVED_MAP_EXISTING = 'APPROVED_MAP_EXISTING';
    public const APPROVED_CREATE = 'APPROVED_CREATE';
    public const PROPOSED_CREATE = 'PROPOSED_CREATE';
    public const REVIEW = 'REVIEW';
    public const NOT_A_PRODUCT_CATEGORY = 'NOT_A_PRODUCT_CATEGORY';
    public const REJECT = 'REJECT';
    public const CONFLICT = 'CONFLICT';
}
