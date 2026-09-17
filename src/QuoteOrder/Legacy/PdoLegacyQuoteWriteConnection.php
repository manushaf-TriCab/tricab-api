<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Legacy;

use PDO;
use PDOException;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteFailedException;
use TriCab\Core\QuoteOrder\Contract\LegacyQuoteWriteConnection;
use TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig;

/**
 * PDO write connection for quote persistence. Separate credentials from RO bridge.
 */
final class PdoLegacyQuoteWriteConnection implements LegacyQuoteWriteConnection
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly LegacyQuoteWriteConfig $config,
    ) {
    }

    public function beginTransaction(): void
    {
        $this->pdo()->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo()->commit();
    }

    public function rollBack(): void
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo()->inTransaction();
    }

    public function execute(string $sql, array $params = []): int
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            foreach ($params as $name => $value) {
                $key = is_string($name) && !str_starts_with($name, ':') ? ':' . $name : (string) $name;
                if ($value === null) {
                    $statement->bindValue($key, null, PDO::PARAM_NULL);
                } elseif (is_bool($value)) {
                    $statement->bindValue($key, $value, PDO::PARAM_BOOL);
                } elseif (is_int($value)) {
                    $statement->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $statement->bindValue($key, (string) $value, PDO::PARAM_STR);
                }
            }
            $statement->execute();

            return $statement->rowCount();
        } catch (PDOException $e) {
            throw QuoteWriteFailedException::because('SQL execution failed.', $e);
        }
    }

    public function query(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo()->prepare($sql);
            foreach ($params as $name => $value) {
                $key = is_string($name) && !str_starts_with($name, ':') ? ':' . $name : (string) $name;
                if ($value === null) {
                    $statement->bindValue($key, null, PDO::PARAM_NULL);
                } elseif (is_bool($value)) {
                    $statement->bindValue($key, $value, PDO::PARAM_BOOL);
                } elseif (is_int($value)) {
                    $statement->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $statement->bindValue($key, (string) $value, PDO::PARAM_STR);
                }
            }
            $statement->execute();
            $fetched = $statement->fetchAll(PDO::FETCH_ASSOC);

            return is_array($fetched) ? $fetched : [];
        } catch (PDOException $e) {
            throw QuoteWriteFailedException::because('SQL query failed.', $e);
        }
    }

    public function lastInsertId(): string
    {
        return (string) $this->pdo()->lastInsertId();
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $this->config->assertExecutable();
        if ($this->config->dsn === null || $this->config->user === null || $this->config->password === null) {
            throw QuoteWriteFailedException::because('Write connection credentials are missing.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO(
                $this->config->dsn,
                $this->config->user,
                $this->config->password,
                $options,
            );
        } catch (PDOException $e) {
            throw QuoteWriteFailedException::because('Unable to open legacy quote write connection.', $e);
        }

        return $this->pdo;
    }
}
