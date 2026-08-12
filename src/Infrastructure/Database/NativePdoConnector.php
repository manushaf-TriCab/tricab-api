<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;

final class NativePdoConnector implements PdoConnector
{
    /**
     * @param array<int, mixed> $options
     */
    public function connect(string $dsn, ?string $username, ?string $password, array $options): PDO
    {
        if ($username === null && $password === null) {
            $pdo = new PDO($dsn);
            foreach ($options as $attribute => $value) {
                $pdo->setAttribute($attribute, $value);
            }

            return $pdo;
        }

        return new PDO($dsn, $username, $password, $options);
    }
}
