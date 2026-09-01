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
