<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use PDO;
use PDOException;
use RuntimeException;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;

/**
 * Creates PDO connections for legacy MySQL that are usable only through the read-only guard.
 * Never logs passwords.
 */
final class ReadOnlyMysqlConnectionFactory
{
    public function __construct(
        private readonly LegacyReadOnlySqlGuard $guard,
        private readonly LegacySqlStatementLogger $logger,
    ) {
    }

    public function create(
        string $dsn,
        string $user,
        string $password,
        string $sourceLabel,
        ?string $sslMode = null,
    ): GuardedReadOnlyMysqlConnection
    {
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            throw new RuntimeException('Legacy read-only DSN must use the mysql: PDO driver.');
        }

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Named placeholders + native MySQL prepares break SHOW / some meta reads.
                PDO::ATTR_EMULATE_PREPARES => true,
            ];
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
            }
            $normalizedSsl = $sslMode !== null ? strtolower($sslMode) : null;
            if (
                $normalizedSsl !== null
                && in_array($normalizedSsl, ['required', 'verify_ca', 'verify_identity', 'true', '1'], true)
                && defined('PDO::MYSQL_ATTR_SSL_CA')
            ) {
                // Azure Database for MySQL requires TLS. Empty CA + disabled verify
                // uses system trust where available without shipping a PEM in-repo.
                $options[PDO::MYSQL_ATTR_SSL_CA] = '';
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                }
            }
            $pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Failed to open read-only legacy MySQL connection for ' . $sourceLabel . ': ' . $e->getMessage(),
                0,
                $e,
            );
        }

        return new GuardedReadOnlyMysqlConnection($pdo, $this->guard, $this->logger, $sourceLabel);
    }
}
