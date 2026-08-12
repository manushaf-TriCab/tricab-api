<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PDOException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\TransientSqlConnectionError;

final class TransientSqlConnectionErrorTest extends TestCase
{
    public function test_hyt00_login_timeout_is_retryable(): void
    {
        $exception = self::pdoException(
            'HYT00',
            0,
            'SQLSTATE[HYT00]: [Microsoft][ODBC Driver 18 for SQL Server]Login timeout expired',
        );

        self::assertSame('HYT00', TransientSqlConnectionError::classify($exception));
    }

    public function test_login_timeout_message_without_sqlstate_is_retryable(): void
    {
        $exception = new PDOException('Login timeout expired');

        self::assertSame('HYT00', TransientSqlConnectionError::classify($exception));
    }

    public function test_azure_40613_unavailable_is_retryable(): void
    {
        $exception = self::pdoException(
            '08004',
            40613,
            'Database is not currently available',
        );

        self::assertSame('40613', TransientSqlConnectionError::classify($exception));
    }

    public function test_authentication_failure_is_not_retryable(): void
    {
        $exception = self::pdoException(
            '28000',
            18456,
            'Login failed for user \'token\'.',
        );

        self::assertNull(TransientSqlConnectionError::classify($exception));
    }

    public function test_permission_denied_is_not_retryable(): void
    {
        $exception = self::pdoException('42000', 229, 'The SELECT permission was denied');

        self::assertNull(TransientSqlConnectionError::classify($exception));
    }

    public function test_sql_syntax_error_is_not_retryable(): void
    {
        $exception = self::pdoException('42000', 102, 'Incorrect syntax near \'FROM\'');

        self::assertNull(TransientSqlConnectionError::classify($exception));
    }

    public function test_generic_08001_without_timeout_is_not_retryable(): void
    {
        $exception = self::pdoException('08001', 0, 'Client unable to establish connection');

        self::assertNull(TransientSqlConnectionError::classify($exception));
    }

    public function test_08001_timeout_is_retryable(): void
    {
        $exception = self::pdoException('08001', 0, 'Client unable to establish connection because of timeout');

        self::assertSame('08001', TransientSqlConnectionError::classify($exception));
    }

    private static function pdoException(string $sqlState, int $driverCode, string $message): PDOException
    {
        $exception = new PDOException($message, 0);
        $exception->errorInfo = [$sqlState, $driverCode, $message];

        return $exception;
    }
}
