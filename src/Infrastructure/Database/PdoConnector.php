<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;

interface PdoConnector
{
    /**
     * @param array<int, mixed> $options
     */
    public function connect(string $dsn, ?string $username, ?string $password, array $options): PDO;
}
