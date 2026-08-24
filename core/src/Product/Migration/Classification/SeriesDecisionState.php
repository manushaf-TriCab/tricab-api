<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Classification;

final class SeriesDecisionState
{
    public const APPROVED_MAP_EXISTING = 'APPROVED_MAP_EXISTING';
    public const APPROVED_CREATE = 'APPROVED_CREATE';
    public const PROPOSED_CREATE = 'PROPOSED_CREATE';
    public const REVIEW = 'REVIEW';
    public const INVALID = 'INVALID';
    public const SOURCE_ONLY = 'SOURCE_ONLY';
    public const REJECT = 'REJECT';
    public const CONFLICT = 'CONFLICT';
}
