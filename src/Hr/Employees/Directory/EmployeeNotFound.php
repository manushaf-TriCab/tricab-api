<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

use RuntimeException;

final class EmployeeNotFound extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self('Employee not found.');
    }
}
