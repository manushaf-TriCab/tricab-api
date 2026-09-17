<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Domain\Mapping;

/**
 * Proven legacy constants for first-write simple cable lines.
 * Sourced from QuoteManagement / Product::$packMatrix / create insert path.
 */
final class LegacyQuoteLineTypeDefaults
{
    public const CABLE_CATEGORY_TYPE = 1615;

    public const ACCESSORY_CATEGORY_TYPE = 1660;

    /** Pack-matrix descript id for cables (NOT margin %). */
    public const CABLE_PACK_MATRIX_ID = 1501;

    /** Pack-matrix descript id for accessories (out of first-write scope). */
    public const ACCESSORY_PACK_MATRIX_ID = 1513;

    /**
     * Legacy create path hardcodes tqoti.col = 4008 for all quote lines
     * (QuoteManagement::addToQuote INSERT). Descript colour id, not product jacket colour.
     */
    public const LEGACY_CREATE_COLOUR_ID = 4008;

    /** Staff operator rows in descript. */
    public const STAFF_DESCRIPT_TYPE = 36;
}
