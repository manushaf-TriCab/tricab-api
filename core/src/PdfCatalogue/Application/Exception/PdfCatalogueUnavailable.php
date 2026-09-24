<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Application\Exception;

use RuntimeException;

final class PdfCatalogueUnavailable extends RuntimeException
{
    public static function documentMissing(string $publicId): self
    {
        return new self('Catalogue document is not available for download: ' . $publicId);
    }

    public static function storage(): self
    {
        return new self('Catalogue document storage is unavailable.');
    }
}
