<?php

declare(strict_types=1);

namespace TriCab\Core\TelDirectory;

/**
 * Tel Directory — staff telephone directory backed by Microsoft Graph / Entra ID.
 * Directory users are never copied into Azure SQL.
 */
final class Module
{
    public const NAME = 'TelDirectory';

    /** Module entry — list staff directory contacts from Microsoft Graph. */
    public const PERMISSION_ACCESS = 'TEL_DIRECTORY.ACCESS';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_ACCESS,
    ];
}
