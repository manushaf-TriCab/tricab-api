<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;
use PDOException;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenException;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;

/**
 * Creates PDO connections for Azure SQL using either SQL login or Entra CLI access tokens.
 */
final class AzureSqlPdoFactory
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AzureCliAccessTokenProvider $accessTokenProvider,
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

        if ($this->settings->usesEntraCliAuth()) {
            return $this->createWithAccessToken();
        }

        return $this->createWithSqlLogin();
    }

    private function createWithAccessToken(): PDO
    {
        try {
            $token = $this->accessTokenProvider->getAccessToken();
        } catch (AzureCliAccessTokenException $exception) {
            throw DatabaseAuthFailed::fromThrowable($exception);
        }

        // pdo_sqlsrv 5.13 accepts AccessToken as a DSN connection option (not UID/PWD/Authentication).
        // Do not put the token into exception chains that may be logged.
        try {
            return new PDO(
                $this->buildDsn() . ';AccessToken=' . $token,
                null,
                null,
                $this->pdoOptions(),
            );
        } catch (PDOException) {
            throw DatabaseAuthFailed::because('Unable to authenticate with Azure SQL.');
        }
    }

    private function createWithSqlLogin(): PDO
    {
        if ($this->settings->dbUsername === '' || $this->settings->dbPassword === '') {
            throw DatabaseAuthFailed::because(
                'SQL login credentials are incomplete for DB_AUTH_MODE=sql-login.',
            );
        }

        try {
            return new PDO(
                $this->buildDsn(),
                $this->settings->dbUsername,
                $this->settings->dbPassword,
                $this->pdoOptions(),
            );
        } catch (PDOException $exception) {
            throw DatabaseAuthFailed::fromThrowable($exception);
        }
    }

    private function buildDsn(): string
    {
        $encrypt = $this->settings->dbEncrypt ? 'yes' : 'no';
        $trust = $this->settings->dbTrustServerCertificate ? 'yes' : 'no';

        return sprintf(
            'sqlsrv:Server=tcp:%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s',
            $this->settings->dbHost,
            $this->settings->dbPort,
            $this->settings->dbDatabase,
            $encrypt,
            $trust,
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function pdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }
}
