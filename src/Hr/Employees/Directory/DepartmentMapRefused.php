<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use RuntimeException;

/** A department map change that is refused; carries the API error code and status. */
final class DepartmentMapRefused extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message): self
    {
        return new self('VALIDATION_FAILED', $message, 422);
    }

    public static function unknownValue(string $kind, string $raw): self
    {
        return new self('HR_DEPARTMENT_CODE_UNKNOWN', sprintf('No employee has the %s value "%s".', $kind, $raw), 404);
    }
}
