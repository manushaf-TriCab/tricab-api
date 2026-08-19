<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\StaffEntraLink;

final class StaffEntraLinkProposedAction
{
    public const ALREADY_ENTRA_LINKED = 'ALREADY_ENTRA_LINKED';
    public const ALREADY_LINKED = self::ALREADY_ENTRA_LINKED;
    public const SAFE_LINK_TO_MAPPED_USER = 'SAFE_LINK_TO_MAPPED_USER';
    public const SAFE_LINK_TO_EXISTING_USER = 'SAFE_LINK_TO_EXISTING_USER';
    public const NO_ENTRA_MATCH = 'NO_ENTRA_MATCH';
    public const GRAPH_DIRECTORY_UNAVAILABLE = 'GRAPH_DIRECTORY_UNAVAILABLE';
    public const MULTIPLE_TRICAB_USERS = 'MULTIPLE_TRICAB_USERS';
    public const ENTRA_OID_CONFLICT = 'ENTRA_OID_CONFLICT';
    public const EMAIL_CONFLICT = 'EMAIL_CONFLICT';
    public const MANUAL_REVIEW_REQUIRED = 'MANUAL_REVIEW_REQUIRED';
    public const ENTRA_DISABLED = 'ENTRA_DISABLED';
    public const DISABLED_ENTRA_ACCOUNT = self::ENTRA_DISABLED;
    public const NON_TRICAB_EMAIL = 'NON_TRICAB_EMAIL';
    public const MULTIPLE_ENTRA_MATCHES = 'MULTIPLE_ENTRA_MATCHES';
    public const EMAIL_UPN_MISMATCH = 'EMAIL_UPN_MISMATCH';
    public const PROVIDER_CONFLICT = 'PROVIDER_CONFLICT';

    private function __construct()
    {
    }
}
