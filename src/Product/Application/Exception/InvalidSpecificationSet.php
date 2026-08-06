<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class InvalidSpecificationSet extends RuntimeException
{
    public static function unknownDefinition(string $definitionPublicId): self
    {
        return new self(sprintf('Unknown specification definition "%s".', $definitionPublicId));
    }

    public static function scopeMismatch(string $definitionPublicId, string $target): self
    {
        return new self(sprintf(
            'Specification definition "%s" is not assignable at "%s" scope.',
            $definitionPublicId,
            $target,
        ));
    }

    public static function requiredMissing(string $definitionPublicId): self
    {
        return new self(sprintf('Required specification "%s" is missing a value.', $definitionPublicId));
    }
}
