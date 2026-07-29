<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;
use PDOException;
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
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value);
            }
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
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value);
            }
            $statement->execute();
        } catch (PDOException $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        } catch (Throwable $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        }
    }
}
