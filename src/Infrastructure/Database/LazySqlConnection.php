<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

/**
 * Defers PDO creation until the first SQL operation.
 */
final class LazySqlConnection implements SqlConnection
{
    /** @var callable(): SqlConnection */
    private $factory;

    private ?SqlConnection $inner = null;

    /**
     * @param callable(): SqlConnection $factory
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->inner()->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->inner()->execute($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->inner()->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner()->commit();
    }

    public function rollBack(): void
    {
        $this->inner()->rollBack();
    }

    public function inTransaction(): bool
    {
        if ($this->inner === null) {
            return false;
        }

        return $this->inner->inTransaction();
    }

    public function isConnected(): bool
    {
        return $this->inner !== null;
    }

    private function inner(): SqlConnection
    {
        return $this->inner ??= ($this->factory)();
    }
}
