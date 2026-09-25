<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue;

/**
 * PDF product catalogues — staff-facing document library (NOT product browse navigation).
 */
final class Module
{
    public const NAME = 'PdfCatalogue';

    public const PERMISSION_READ = 'CATALOGUE.READ';

    public const BLOB_PATH_PREFIX = 'catalogues';

    public const BLOB_CONTAINER = 'catalogues';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_READ,
    ];

    /** @var list<string> */
    public const REGION_CODES = [
        'AU_NZ',
        'ASIA',
        'USA',
    ];
}
