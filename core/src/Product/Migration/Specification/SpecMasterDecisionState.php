<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Specification;

/** Batch B definition / option decision states. Never auto-APPROVED_CREATE. */
final class SpecMasterDecisionState
{
    public const APPROVED_MAP_EXISTING = 'APPROVED_MAP_EXISTING';
    public const APPROVED_CREATE = 'APPROVED_CREATE';
    public const PROPOSED_CREATE = 'PROPOSED_CREATE';
    public const REVIEW = 'REVIEW';
    public const NOT_A_SPECIFICATION = 'NOT_A_SPECIFICATION';
    public const REJECT = 'REJECT';

    public const SAFE_ALIAS = 'SAFE_ALIAS';
    public const ALIAS_REVIEW = 'REVIEW';
    public const ALIAS_REJECT = 'REJECT';
}
