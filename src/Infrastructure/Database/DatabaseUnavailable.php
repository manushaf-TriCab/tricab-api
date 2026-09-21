<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use RuntimeException;
use Throwable;

class DatabaseUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function fromThrowable(Throwable $throwable): self
    {
        return new self('Azure SQL query failed.', 0, $throwable);
    }

    /**
     * The same, with a hint when the SQL reuses a named parameter.
     *
     * PDO_SQLSRV rewrites named parameters to positional markers and binds each
     * occurrence separately, so `:email` written twice leaves the second marker
     * unbound. The driver then answers SQLSTATE 07002, "COUNT field incorrect or
     * syntax error", which names neither the parameter nor the query and reads
     * like a malformed statement rather than a binding fault. It is worth an
     * hour of somebody's day, so the hour is spent here instead.
     *
     * Detection runs only on the failure path, and only for 07002.
     */
    public static function fromQueryThrowable(Throwable $throwable, string $sql): self
    {
        $duplicate = self::firstReusedParameter($throwable, $sql);

        if ($duplicate === null) {
            return self::fromThrowable($throwable);
        }

        return new self(
            sprintf(
                'Azure SQL query failed. The named parameter "%s" appears more than once in this '
                . 'statement, and PDO_SQLSRV binds each occurrence separately — give each one its own '
                . 'name and bind the same value to both.',
                $duplicate,
            ),
            0,
            $throwable,
        );
    }

    private static function firstReusedParameter(Throwable $throwable, string $sql): ?string
    {
        if (!$throwable instanceof \PDOException) {
            return null;
        }

        $state = is_array($throwable->errorInfo) ? ($throwable->errorInfo[0] ?? null) : null;
        if ($state !== '07002') {
            return null;
        }

        // Strip string literals so a ':' inside quoted text is not read as a
        // parameter — times and URLs both contain one.
        $stripped = (string) preg_replace("/'(?:[^']|'')*'/", "''", $sql);

        if (preg_match_all('/(?<![:\w]):([A-Za-z_]\w*)/', $stripped, $matches) === false) {
            return null;
        }

        $counts = array_count_values($matches[1]);
        foreach ($counts as $name => $count) {
            if ($count > 1) {
                return ':' . $name;
            }
        }

        return null;
    }

    public static function fromConnectionThrowable(Throwable $throwable): self
    {
        return new self('Azure SQL connection failed.', 0, $throwable);
    }

    public function sqlDriverCode(): ?int
    {
        $previous = $this->getPrevious();
        if (!$previous instanceof \PDOException) {
            return null;
        }

        $info = $previous->errorInfo;
        if (!is_array($info) || !isset($info[1]) || !is_numeric($info[1])) {
            return null;
        }

        return (int) $info[1];
    }

    public function isCheckConstraintViolation(): bool
    {
        if ($this->sqlDriverCode() !== 547) {
            return false;
        }

        $message = strtolower($this->getPrevious()?->getMessage() ?? $this->getMessage());

        return str_contains($message, 'check constraint');
    }

    public function isForeignKeyViolation(): bool
    {
        return $this->sqlDriverCode() === 547 && !$this->isCheckConstraintViolation();
    }

    public function isInvalidObjectReference(): bool
    {
        $code = $this->sqlDriverCode();
        if ($code === 208 || $code === 207) {
            return true;
        }

        $message = strtolower($this->getPrevious()?->getMessage() ?? $this->getMessage());

        return str_contains($message, 'invalid object name')
            || str_contains($message, 'invalid column name');
    }
}
