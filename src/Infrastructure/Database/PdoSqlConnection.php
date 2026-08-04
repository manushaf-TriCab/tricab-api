<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class PdoSqlConnection implements SqlConnection
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $this->bindParams($statement, $params);
            $statement->execute();
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return $rows;
        } catch (PDOException $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        } catch (Throwable $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        }
    }

    public function execute(string $sql, array $params = []): void
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $this->bindParams($statement, $params);
            $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        } catch (Throwable $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        }
    }

    public function beginTransaction(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                return;
            }

            $this->pdo->beginTransaction();
        } catch (PDOException $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        } catch (Throwable $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        }
    }

    public function commit(): void
    {
        try {
            if (!$this->pdo->inTransaction()) {
                return;
            }

            $this->pdo->commit();
        } catch (PDOException $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        } catch (Throwable $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        }
    }

    public function rollBack(): void
    {
        try {
            if (!$this->pdo->inTransaction()) {
                return;
            }

            $this->pdo->rollBack();
        } catch (PDOException $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        } catch (Throwable $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @param array<string, scalar|SqlBinary|null> $params
     */
    private function bindParams(PDOStatement $statement, array $params): void
    {
        // bindParam requires variables that remain in scope until execute().
        $binaryBuffers = [];

        foreach ($params as $name => $value) {
            if ($value instanceof SqlBinary) {
                $binaryBuffers[$name] = $value->bytes;
                if (defined('PDO::SQLSRV_ENCODING_BINARY')) {
                    $statement->bindParam(
                        $name,
                        $binaryBuffers[$name],
                        PDO::PARAM_LOB,
                        0,
                        PDO::SQLSRV_ENCODING_BINARY,
                    );
                } else {
                    $statement->bindValue($name, $binaryBuffers[$name], PDO::PARAM_LOB);
                }

                continue;
            }

            if ($value === null) {
                $statement->bindValue($name, null, PDO::PARAM_NULL);

                continue;
            }

            // sqlsrv rejects string-bound values for OFFSET/FETCH/TOP — bind ints explicitly.
            if (is_int($value)) {
                $statement->bindValue($name, $value, PDO::PARAM_INT);

                continue;
            }

            if (is_bool($value)) {
                $statement->bindValue($name, $value, PDO::PARAM_BOOL);

                continue;
            }

            $statement->bindValue($name, $value);
        }
    }
}
