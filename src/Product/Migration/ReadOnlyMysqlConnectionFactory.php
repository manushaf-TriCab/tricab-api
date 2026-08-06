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

    public function create(string $dsn, string $user, string $password, string $sourceLabel): GuardedReadOnlyMysqlConnection
    {
        if (!str_starts_with(strtolower($dsn), 'mysql:')) {
            throw new RuntimeException('Legacy read-only DSN must use the mysql: PDO driver.');
        }

        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
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
