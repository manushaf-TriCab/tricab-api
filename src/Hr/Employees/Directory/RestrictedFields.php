<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees\Directory;

/** The keys of the restricted block, as they appear in JSON. */
final class RestrictedFields
{
    public const KEYS = [
        'personalEmail', 'mobile', 'street', 'suburb', 'state', 'postcode', 'dateOfBirth',
        'annualLeaveRemaining', 'longServiceLeaveRemaining', 'sickLeaveRemaining', 'leaveAsOf',
    ];
}
