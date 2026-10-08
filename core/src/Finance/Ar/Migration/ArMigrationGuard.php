<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

/**
 * When `--apply` may go ahead (plan 9: nothing is applied to a shared database without the owner's approval).
 * "Local" is decided as `bin/map-finance-portal-users.php` decides it: the database host is this machine.
 * Anything else, `tricab-mel.database.windows.net` included, needs `--approved-by NAME` (the name is only printed).
 */
final class ArMigrationGuard
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '(local)'];

    private function __construct()
    {
    }

    /** `DB_HOST` may carry a port after a comma, as SQL Server writes it. */
    public static function isLocal(string $dbHost): bool
    {
        return in_array(strtolower(trim(explode(',', $dbHost)[0])), self::LOCAL_HOSTS, true);
    }

    /** Why a write to this host is refused, or null when it may go ahead. */
    public static function hostRefusal(string $dbHost, string $approvedBy): ?string
    {
        if (self::isLocal($dbHost) || trim($approvedBy) !== '') {
            return null;
        }

        return "DB_HOST is not this machine. Writing there needs the owner's approval: add --approved-by NAME.";
    }

    /** The files must land somewhere that keeps them: not the in-memory blob store, which forgets at exit. */
    public static function blobRefusal(bool $storeKeepsFiles): ?string
    {
        return $storeKeepsFiles
            ? null
            : "The file store's blob container is not configured (BLOB_AUTH_MODE is memory or disabled), so the files would be lost when the tool exits. Set BLOB_AUTH_MODE=connection-string and the finance container.";
    }
}
