<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Specification;

/** Batch C style-specific field mapping decision states. Never auto-promote PROPOSED → APPROVED. */
final class SpecFieldMappingDecisionState
{
    public const APPROVED_MAP = 'APPROVED_MAP';
    public const PROPOSED_MAP = 'PROPOSED_MAP';
    public const REVIEW = 'REVIEW';
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';
    public const REJECT = 'REJECT';
    public const CONFLICT = 'CONFLICT';
}
