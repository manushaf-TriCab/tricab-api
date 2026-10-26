<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

/** Finds the platform user for a payroll login. Never by email, and it never creates users. */
interface UserLoginLinker
{
    /**
     * @param list<string> $normalizedLogins lower-case, trimmed
     *
     * @return array<string, int> login => [identity].[Users].UserId, only for logins with a user
     */
    public function link(array $normalizedLogins): array;
}
