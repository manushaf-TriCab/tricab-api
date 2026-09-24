<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Exception;

use RuntimeException;

final class PriceLookupValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidCompany(string $value): self
    {
        return new self('INVALID_COMPANY', 'Unknown company "' . $value . '". Use TCA, TCUS, or TCASIA.');
    }

    public static function invalidCurrency(string $value): self
    {
        return new self('INVALID_CURRENCY', 'Unsupported currency "' . $value . '".');
    }

    public static function invalidMetric(string $value): self
    {
        return new self('INVALID_METRIC', 'Metric must be metre or foot.');
    }

    public static function invalidMargin(string $value): self
    {
        return new self('INVALID_MARGIN', 'Margin percent must be >= 0 and < 100.');
    }

    public static function invalidProductCode(): self
    {
        return new self('INVALID_PRODUCT_CODE', 'Product code is required.');
    }

    public static function invalidQuery(): self
    {
        return new self('INVALID_QUERY', 'Search query q is required.');
    }

    public static function invalidLength(string $value): self
    {
        return new self('INVALID_LENGTH', 'Length must be a positive number when provided.');
    }
}
