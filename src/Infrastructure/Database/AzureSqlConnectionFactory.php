<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;

/**
 * @deprecated Use AzureSqlPdoFactory. Kept as a compatibility wrapper.
 */
final class AzureSqlConnectionFactory
{
    public function __construct(
        private readonly AzureSqlPdoFactory $pdoFactory,
    ) {
    }

    public function create(): PDO
    {
        return $this->pdoFactory->create();
    }
}
