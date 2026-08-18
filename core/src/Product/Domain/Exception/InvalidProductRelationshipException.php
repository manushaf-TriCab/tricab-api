<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Exception;

use DomainException;

final class InvalidProductRelationshipException extends DomainException
{
    public static function selfReference(string $relationshipType): self
    {
        return new self(sprintf('A product cannot be its own %s.', $relationshipType));
    }

    public static function duplicate(string $relationshipType): self
    {
        return new self(sprintf('Duplicate %s relationship is not allowed.', $relationshipType));
    }

    public static function for(string $message): self
    {
        return new self($message);
    }
}
