<?php

declare(strict_types=1);

namespace TriCab\Core\Expense;

/**
 * Marker for the Tri Expense bounded context (receipt-centric).
 */
final class Module
{
    public const NAME = 'Expense';

    /** Existing launcher permission — module entry only; profile still required. */
    public const PERMISSION_ACCESS = 'TRIEXPENSE.ACCESS';
}