<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Groups;

/** Graph answered 401/403: `GroupMember.Read.All` has not been granted (admin consent) to the API's app registration. */
final class GraphPermissionNotGranted extends EntraGroupDirectoryUnavailable
{
    public const OUTCOME = 'permission_not_granted';

    public static function make(): self
    {
        return new self('Microsoft Graph refused the group read: GroupMember.Read.All has not been granted to the API app registration (tenant admin consent).');
    }

    public function outcome(): string
    {
        return self::OUTCOME;
    }
}
