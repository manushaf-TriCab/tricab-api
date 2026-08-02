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
        DatabaseAuthDiagnostics::log('pdo_factory.create.start', [
            'DB_AUTH_MODE' => $this->settings->dbAuthMode,
            'USER_REPOSITORY' => $this->settings->userRepository,
            'dbDriver' => $this->settings->dbDriver,
            'dbHostConfigured' => $this->settings->dbHost !== '',
            'dbPort' => $this->settings->dbPort,
            'dbDatabaseConfigured' => $this->settings->dbDatabase !== '',
            'dbUsernameConfigured' => $this->settings->dbUsername !== '',
            'dbEncrypt' => $this->settings->dbEncrypt,
            'dbTrustServerCertificate' => $this->settings->dbTrustServerCertificate,
            'pdo_sqlsrv_loaded' => extension_loaded('pdo_sqlsrv'),
            'dsnBase' => $this->buildDsn(),
        ]);

        if (!extension_loaded('pdo_sqlsrv')) {
            DatabaseAuthDiagnostics::log('pdo_factory.create.fail', [
                'failureLocation' => 'before_token',
                'reason' => 'pdo_sqlsrv_missing',
            ]);
            throw DatabaseUnavailable::because(
                'The pdo_sqlsrv PHP extension is not installed.',
            );
        }

        if ($this->settings->dbHost === '' || $this->settings->dbDatabase === '') {
            DatabaseAuthDiagnostics::log('pdo_factory.create.fail', [
                'failureLocation' => 'before_token',
                'reason' => 'incomplete_connection_settings',
            ]);
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
            DatabaseAuthDiagnostics::log('pdo_factory.token.acquired', [
                'tokenAcquired' => true,
                'tokenLength' => strlen($token),
                'tokenPassedIntoPdo' => true,
            ]);
        } catch (AzureCliAccessTokenException $exception) {
            DatabaseAuthDiagnostics::logThrowable('pdo_factory.token.failed', $exception, [
                'failureLocation' => 'before_obtaining_token',
                'tokenAcquired' => false,
                'tokenPassedIntoPdo' => false,
            ]);
            throw DatabaseAuthFailed::fromThrowable($exception);
        }

        // Microsoft Entra access-token auth for PDO_SQLSRV (ODBC Driver 17+/18):
        // https://learn.microsoft.com/en-us/sql/connect/php/azure-active-directory
        // - Pass AccessToken in the DSN (there is no PDO::SQLSRV_ATTR_ACCESS_TOKEN).
        // - Do not pass UID/PWD and do not set Authentication=.
        // - AccessToken is mutually exclusive with Authentication / UID / PWD.
        // Do not put the token into exception chains that may be logged.
        $dsn = $this->buildAccessTokenDsn($token);

        try {
            // Two-argument-less constructor: username/password omitted on purpose.
            $pdo = new PDO($dsn);
            foreach ($this->pdoOptions() as $attribute => $value) {
                $pdo->setAttribute($attribute, $value);
            }
            DatabaseAuthDiagnostics::log('pdo_factory.pdo.created', [
                'pdoConnectionCreated' => true,
                'authMode' => 'entra-cli',
                'dsnBase' => $this->buildAccessTokenDsn('[REDACTED]'),
                'accessTokenOptionPresent' => true,
                'uidPwdPassed' => false,
                'authenticationKeywordPresent' => false,
            ]);

            return $pdo;
        } catch (PDOException $exception) {
            DatabaseAuthDiagnostics::logThrowable('pdo_factory.pdo.failed', $exception, [
                'failureLocation' => 'while_creating_pdo',
                'pdoConnectionCreated' => false,
                'authMode' => 'entra-cli',
                'dsnBase' => $this->buildAccessTokenDsn('[REDACTED]'),
                'accessTokenOptionPresent' => true,
                'uidPwdPassed' => false,
                'authenticationKeywordPresent' => false,
            ]);
            throw DatabaseAuthFailed::because('Unable to authenticate with Azure SQL.');
        }
    }

    private function createWithSqlLogin(): PDO
    {
        if ($this->settings->dbUsername === '' || $this->settings->dbPassword === '') {
            DatabaseAuthDiagnostics::log('pdo_factory.sql_login.incomplete', [
                'failureLocation' => 'during_sql_login',
                'reason' => 'incomplete_sql_login_credentials',
            ]);
            throw DatabaseAuthFailed::because(
                'SQL login credentials are incomplete for DB_AUTH_MODE=sql-login.',
            );
        }

        try {
            $pdo = new PDO(
                $this->buildDsn(),
                $this->settings->dbUsername,
                $this->settings->dbPassword,
                $this->pdoOptions(),
            );
            DatabaseAuthDiagnostics::log('pdo_factory.pdo.created', [
                'pdoConnectionCreated' => true,
                'authMode' => 'sql-login',
                'dsnBase' => $this->buildDsn(),
            ]);

            return $pdo;
        } catch (PDOException $exception) {
            DatabaseAuthDiagnostics::logThrowable('pdo_factory.pdo.failed', $exception, [
                'failureLocation' => 'during_sql_login',
                'pdoConnectionCreated' => false,
                'authMode' => 'sql-login',
                'dsnBase' => $this->buildDsn(),
            ]);
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
     * DSN shape matches Microsoft's PDO_SQLSRV AccessToken sample:
     * sqlsrv:server = {host}; Database = {db}; AccessToken = {token};
     * Encrypt / TrustServerCertificate kept explicit for ODBC Driver 18 + Azure SQL.
     */
    private function buildAccessTokenDsn(string $accessToken): string
    {
        $encrypt = $this->settings->dbEncrypt ? 'yes' : 'no';
        $trust = $this->settings->dbTrustServerCertificate ? 'yes' : 'no';
        $server = $this->settings->dbHost;
        if ($this->settings->dbPort !== 1433) {
            $server .= ',' . $this->settings->dbPort;
        }

        return sprintf(
            'sqlsrv:server = %s; Database = %s; AccessToken = %s; Encrypt = %s; TrustServerCertificate = %s',
            $server,
            $this->settings->dbDatabase,
            $accessToken,
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
