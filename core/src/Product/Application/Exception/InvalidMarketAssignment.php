<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class InvalidMarketAssignment extends RuntimeException
{
    public static function unknownRegion(string $publicId): self
    {
        return new self(sprintf('Unknown or inactive region "%s".', $publicId));
    }

    public static function unknownMarket(string $publicId): self
    {
        return new self(sprintf('Unknown or inactive market "%s".', $publicId));
    }

    public static function unknownIndustry(string $publicId): self
    {
        return new self(sprintf('Unknown or inactive industry "%s".', $publicId));
    }

    public static function unknownApplication(string $publicId): self
    {
        return new self(sprintf('Unknown or inactive application "%s".', $publicId));
    }

    public static function duplicateRegions(): self
    {
        return new self('Duplicate region assignments are not allowed.');
    }

    public static function duplicateMarkets(): self
    {
        return new self('Duplicate market assignments are not allowed.');
    }

    public static function publishedRequiresRegion(): self
    {
        return new self('Published products must remain assigned to at least one active region.');
    }
}
