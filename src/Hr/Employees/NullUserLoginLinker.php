<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

/** Links nobody; every login counts as unmatched. */
final class NullUserLoginLinker implements UserLoginLinker
{
    public function link(array $normalizedLogins): array
    {
        return [];
    }
}
