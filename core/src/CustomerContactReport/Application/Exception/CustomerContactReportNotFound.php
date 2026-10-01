<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\Exception;

use RuntimeException;

final class CustomerContactReportNotFound extends RuntimeException
{
    public static function forPublicId(string $publicId): self
    {
        return new self('Customer Contact report was not found: ' . $publicId);
    }
}
