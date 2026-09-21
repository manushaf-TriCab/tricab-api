<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;

/**
 * Turning SQLSTATE 07002 into something a person can act on.
 *
 * PDO_SQLSRV rewrites named parameters to positional markers and binds each
 * occurrence separately, so a name written twice leaves the second marker
 * unbound. The driver answers "COUNT field incorrect or syntax error", which
 * names neither the parameter nor the query and reads like a malformed
 * statement rather than a binding fault — the wrong place to start looking.
 *
 * Found the hard way on 21 Sep 2026: `bin/onboarding-assign-admins.php` used
 * `:email` twice, and the branch only ran once `identity.UserEmailAddresses`
 * existed, so applying the Phase P migration is what surfaced it.
 */
final class ReusedNamedParameterDiagnosticTest extends TestCase
{
    private function driverError(string $sqlState): PDOException
    {
        $exception = new PDOException('COUNT field incorrect or syntax error');
        $exception->errorInfo = [$sqlState, 0, 'COUNT field incorrect or syntax error'];

        return $exception;
    }

    public function test_it_names_the_parameter_that_was_reused(): void
    {
        $failure = DatabaseUnavailable::fromQueryThrowable(
            $this->driverError('07002'),
            'SELECT 1 FROM t WHERE a = :email OR b = :email',
        );

        self::assertStringContainsString('":email"', $failure->getMessage());
        self::assertStringContainsString('appears more than once', $failure->getMessage());
    }

    public function test_it_says_what_to_do_about_it(): void
    {
        $failure = DatabaseUnavailable::fromQueryThrowable(
            $this->driverError('07002'),
            'UPDATE t SET a = :actor, b = :actor WHERE id = :id',
        );

        self::assertStringContainsString('give each one its own name', $failure->getMessage());
    }

    public function test_a_query_binding_each_name_once_keeps_the_ordinary_message(): void
    {
        $failure = DatabaseUnavailable::fromQueryThrowable(
            $this->driverError('07002'),
            'SELECT 1 FROM t WHERE a = :email AND b = :userId',
        );

        self::assertSame('Azure SQL query failed.', $failure->getMessage());
    }

    /**
     * A different fault that happens to involve a repeated name must not be
     * explained as this one. Guessing wrong sends somebody further from the
     * cause than saying nothing would.
     */
    public function test_a_different_sql_state_is_not_explained_away(): void
    {
        $failure = DatabaseUnavailable::fromQueryThrowable(
            $this->driverError('42S02'),
            'SELECT 1 FROM t WHERE a = :email OR b = :email',
        );

        self::assertSame('Azure SQL query failed.', $failure->getMessage());
    }

    /**
     * A colon inside a string literal is not a parameter. Times and URLs both
     * carry one, and reading `'12:30'` as `:30` would produce a confident,
     * wrong explanation.
     */
    public function test_a_colon_inside_a_literal_is_not_a_parameter(): void
    {
        $failure = DatabaseUnavailable::fromQueryThrowable(
            $this->driverError('07002'),
            "SELECT 1 FROM t WHERE a = '12:30' AND b = '12:30' AND c = :id",
        );

        self::assertSame('Azure SQL query failed.', $failure->getMessage());
    }

    public function test_a_non_driver_failure_keeps_the_ordinary_message(): void
    {
        $failure = DatabaseUnavailable::fromQueryThrowable(
            new RuntimeException('something else'),
            'SELECT 1 FROM t WHERE a = :email OR b = :email',
        );

        self::assertSame('Azure SQL query failed.', $failure->getMessage());
    }

    public function test_the_driver_exception_is_still_the_cause(): void
    {
        $driver = $this->driverError('07002');
        $failure = DatabaseUnavailable::fromQueryThrowable(
            $driver,
            'SELECT 1 FROM t WHERE a = :email OR b = :email',
        );

        self::assertSame($driver, $failure->getPrevious());
    }
}
