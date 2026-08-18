<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use DomainException;

final class InvalidCatalogueHierarchy extends DomainException
{
    public static function circular(): self
    {
        return new self('Cannot move a catalogue category under one of its descendants.');
    }

    public static function selfParent(): self
    {
        return new self('A catalogue category cannot be its own parent.');
    }

    public static function maxDepth(int $maxDepth): self
    {
        return new self(sprintf('Catalogue nesting cannot exceed %d levels.', $maxDepth));
    }

    public static function differentTab(): self
    {
        return new self('Parent category must belong to the same catalogue tab.');
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
