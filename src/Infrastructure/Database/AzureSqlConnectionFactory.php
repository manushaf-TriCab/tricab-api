<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;
use PDOException;
use TriCab\Api\Config\Settings;

final class AzureSqlConnectionFactory
{
    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function create(): PDO
    {
        if (!extension_loaded('pdo_sqlsrv')) {
            throw DatabaseUnavailable::because(
                'The pdo_sqlsrv PHP extension is not installed.',
            );
        }

        if ($this->settings->dbHost === '' || $this->settings->dbDatabase === '') {
            throw DatabaseUnavailable::because(
                'Azure SQL connection settings are incomplete.',
            );
        }

        $encrypt = $this->settings->dbEncrypt ? 'yes' : 'no';
        $trust = $this->settings->dbTrustServerCertificate ? 'yes' : 'no';
        $dsn = sprintf(
            'sqlsrv:Server=tcp:%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s',
            $this->settings->dbHost,
            $this->settings->dbPort,
            $this->settings->dbDatabase,
            $encrypt,
            $trust,
        );

        try {
            return new PDO(
                $dsn,
                $this->settings->dbUsername,
                $this->settings->dbPassword,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
        } catch (PDOException $exception) {
            throw DatabaseUnavailable::fromThrowable($exception);
        }
    }
}
