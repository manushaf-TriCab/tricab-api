<?php

declare(strict_types=1);

namespace TriCab\Core\LegacyMigration\Live;

use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;

/**
 * Verifies DATABASE(), CURRENT_USER(), SHOW GRANTS, and SSL cipher.
 * Never returns passwords, DSNs, or raw GRANT strings.
 */
final class LegacyIdentitySourceHealth
{
    public function __construct(
        private readonly MysqlGrantInspector $grants = new MysqlGrantInspector(),
        private readonly LegacyMigrationExtractionPolicy $extractionPolicy = new LegacyMigrationExtractionPolicy(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(
        LegacyReadOnlyConnection $connection,
        string $code,
        string $expectedDatabase,
        bool $readOnlyConfigured,
        string $sslMode,
        ?LegacyMigrationDevelopmentOverride $developmentOverride = null,
    ): array {
        $databaseRows = $connection->query('SELECT DATABASE() AS db_name', [], 'LegacyIdentitySourceHealth', 'database');
        $userRows = $connection->query('SELECT CURRENT_USER() AS session_user_name', [], 'LegacyIdentitySourceHealth', 'currentUser');
        $grantRows = $connection->query('SHOW GRANTS', [], 'LegacyIdentitySourceHealth', 'grants');
        $sslRows = $connection->query("SHOW STATUS LIKE 'Ssl_cipher'", [], 'LegacyIdentitySourceHealth', 'ssl');

        $database = isset($databaseRows[0]['db_name']) ? (string) $databaseRows[0]['db_name'] : '';
        $currentUser = isset($userRows[0]['session_user_name'])
            ? (string) $userRows[0]['session_user_name']
            : (isset($userRows[0]['current_user']) ? (string) $userRows[0]['current_user'] : '');
        $cipher = '';
        foreach ($sslRows as $row) {
            $name = strtolower((string) ($row['Variable_name'] ?? $row['variable_name'] ?? ''));
            if ($name === 'ssl_cipher') {
                $cipher = (string) ($row['Value'] ?? $row['value'] ?? '');
            }
        }

        $sslRequired = in_array(strtolower($sslMode), ['required', 'verify_ca', 'verify_identity', 'true', '1'], true);
        $sslVerified = $cipher !== '';
        $databaseMatches = strcasecmp($database, $expectedDatabase) === 0;
        $selectOnly = $this->grants->isSelectOnly($grantRows);
        $errors = [];
        if (!$readOnlyConfigured) {
            $errors[] = 'LEGACY_*_READ_ONLY is not true. Extraction has been blocked.';
        }
        if (!$databaseMatches) {
            $errors[] = 'Configured database does not match the connected database.';
        }
        if (!$selectOnly) {
            $errors[] = 'Legacy credential contains write privileges. Extraction has been blocked. Use a SELECT-only migration account.';
        }
        if ($sslRequired && !$sslVerified) {
            $errors[] = 'SSL is required but the session cipher is empty. Extraction has been blocked.';
        }

        $safe = $readOnlyConfigured && $databaseMatches && $selectOnly && (!$sslRequired || $sslVerified);

        $base = [
            'code' => $code,
            'configured' => true,
            'connected' => true,
            'ssl' => $sslVerified,
            'sslRequired' => $sslRequired,
            'sslCipherPresent' => $sslVerified,
            'readOnlyConfigured' => $readOnlyConfigured,
            'readOnlyVerified' => $selectOnly,
            'safeForExtraction' => $safe,
            'database' => $database,
            'expectedDatabase' => $expectedDatabase,
            'currentUserHost' => self::redactUser($currentUser),
            'grantCount' => count($grantRows),
            'liveConnectionAttempted' => true,
            'errors' => $errors,
            'message' => $safe
                ? 'SELECT-only legacy connection verified.'
                : implode(' ', $errors),
            'health' => $safe ? 'healthy' : 'blocked',
        ];

        return $this->extractionPolicy->apply($base, $readOnlyConfigured, $developmentOverride);
    }

    public function blocked(
        string $code,
        string $message,
        bool $configured,
        bool $readOnlyConfigured,
        ?LegacyMigrationDevelopmentOverride $developmentOverride = null,
    ): array {
        $base = [
            'code' => $code,
            'configured' => $configured,
            'connected' => false,
            'ssl' => false,
            'sslRequired' => true,
            'sslCipherPresent' => false,
            'readOnlyConfigured' => $readOnlyConfigured,
            'readOnlyVerified' => false,
            'safeForExtraction' => false,
            'database' => null,
            'expectedDatabase' => null,
            'liveConnectionAttempted' => false,
            'errors' => [$message],
            'message' => $message,
            'health' => $configured ? 'blocked' : 'unconfigured',
        ];

        return $this->extractionPolicy->apply($base, $readOnlyConfigured, $developmentOverride);
    }

    private static function redactUser(string $currentUser): string
    {
        $at = strpos($currentUser, '@');
        if ($at === false) {
            return $currentUser === '' ? '' : '(redacted)';
        }

        return '(redacted)@' . substr($currentUser, $at + 1);
    }
}
