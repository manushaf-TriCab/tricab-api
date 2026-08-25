<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class InvalidProductStyleHierarchy extends RuntimeException
{
    public static function selfParent(string $publicId): self
    {
        return new self(sprintf('Style "%s" cannot be its own parent.', $publicId));
    }

    public static function cycle(string $stylePublicId, string $parentPublicId): self
    {
        return new self(sprintf(
            'Assigning parent "%s" to style "%s" would create a circular hierarchy.',
            $parentPublicId,
            $stylePublicId,
        ));
    }

    public static function parentNotFound(string $parentPublicId): self
    {
        return new self(sprintf('Parent style "%s" was not found.', $parentPublicId));
    }

    public static function inheritRequiresParent(string $resourceType): self
    {
        return new self(sprintf(
            'Inheritance mode INHERIT cannot be used for %s on a root style without a parent.',
            $resourceType,
        ));
    }
}
