<?php

declare(strict_types=1);

namespace TriCab\Core\PdfCatalogue\Application\Exception;

use RuntimeException;

final class PdfCatalogueNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self('Catalogue not found: ' . $publicId);
    }
}
