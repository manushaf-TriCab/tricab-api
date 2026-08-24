<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Readiness;

/**
 * Allowed Product identity match methods. No name-only or prefix matching.
 */
final class IdentityMatchMethod
{
    public const EXACT_STYLE = 'EXACT_STYLE';
    public const EXPLICIT_MAPPING = 'EXPLICIT_MAPPING';
    public const ACCESSORY_MAPPING = 'ACCESSORY_MAPPING';
    public const NO_MATCH = 'NO_MATCH';
    public const AMBIGUOUS = 'AMBIGUOUS';
    public const INVALID = 'INVALID';

    /** Accessory-specific identity sources */
    public const EXACT_ITEM = 'EXACT_ITEM';
    public const ACCESSORY_CODE_MAPPING = 'ACCESSORY_CODE_MAPPING';
}
