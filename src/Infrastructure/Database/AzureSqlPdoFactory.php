<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

use PDO;
use PDOException;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenException;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;

/**
 * Creates PDO connections for Azure SQL using either SQL login or Entra CLI access tokens.
 * May retry *connection establishment* once on classified serverless-resume timeouts.
 * Does not retry SQL queries.
 */
final class AzureSqlPdoFactory
{
    /** @var callable(int): void */
    private $sleeper;

    public function __construct(
        private readonly Settings $settings,
        private readonly AzureCliAccessTokenProvider $accessTokenProvider,
        private readonly RequestPerformanceMetrics $metrics,
        private readonly PdoConnector $pdoConnector = new NativePdoConnector(),
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };
    }

    public function create(): PDO
    {
        $this->metrics->setDbAuthMode($this->settings->dbAuthMode);
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
            'loginTimeoutSeconds' => $this->settings->dbLoginTimeoutSeconds,
            'connectRetryEnabled' => $this->settings->dbConnectRetryEnabled,
            'connectRetryMaxAttempts' => $this->settings->dbConnectRetryMaxAttempts,
            'dsnBase' => $this->buildDsn(),
        ]);

        if ($this->pdoConnector instanceof NativePdoConnector && !extension_loaded('pdo_sqlsrv')) {
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

        $maxAttempts = $this->maxConnectAttempts();
        $lastTransient = null;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            $attemptStarted = hrtime(true);
            try {
                $pdo = $this->openPdo();
                $attemptMs = RequestPerformanceMetrics::durationMs($attemptStarted);
                $this->metrics->recordPdoCreate($attemptMs);
                $this->metrics->recordConnectAttempt($attempt, $attemptMs, $lastTransient);

                return $pdo;
            } catch (AzureCliAccessTokenException $exception) {
                DatabaseAuthDiagnostics::logThrowable('pdo_factory.token.failed', $exception, [
                    'failureLocation' => 'before_obtaining_token',
                    'tokenAcquired' => false,
                    'tokenPassedIntoPdo' => false,
                    'connectAttempt' => $attempt,
                ]);
                throw DatabaseAuthFailed::fromThrowable($exception);
            } catch (PDOException $exception) {
                $attemptMs = RequestPerformanceMetrics::durationMs($attemptStarted);
                $this->metrics->recordPdoCreate($attemptMs);
                $transient = TransientSqlConnectionError::classify($exception);
                $this->metrics->recordConnectAttempt($attempt, $attemptMs, $transient);
                $lastException = $exception;
                $lastTransient = $transient;

                DatabaseAuthDiagnostics::logThrowable('pdo_factory.pdo.failed', $exception, [
                    'failureLocation' => 'while_creating_pdo',
                    'pdoConnectionCreated' => false,
                    'authMode' => $this->settings->dbAuthMode,
                    'dsnBase' => $this->redactedDsn(),
                    'connectAttempt' => $attempt,
                    'transientReason' => $transient,
                ]);

                if ($transient !== null && $attempt < $maxAttempts) {
                    DatabaseAuthDiagnostics::log('pdo_factory.connect.retry', [
                        'reason' => $transient,
                        'attempt' => $attempt,
                        'delayMs' => $this->settings->dbConnectRetryDelayMs,
                    ]);
                    ($this->sleeper)($this->settings->dbConnectRetryDelayMs);
                    continue;
                }

                throw $this->mapConnectFailure($exception, $transient);
            }
        }

        if ($lastException instanceof PDOException) {
            throw $this->mapConnectFailure($lastException, $lastTransient);
        }

        throw DatabaseUnavailable::because('Unable to connect to Azure SQL.');
    }

    private function openPdo(): PDO
    {
        if ($this->settings->usesEntraCliAuth()) {
            return $this->createWithAccessToken();
        }

        return $this->createWithSqlLogin();
    }

    private function createWithAccessToken(): PDO
    {
        $token = $this->accessTokenProvider->getAccessToken();
        DatabaseAuthDiagnostics::log('pdo_factory.token.acquired', [
            'tokenAcquired' => true,
            'tokenLength' => strlen($token),
            'tokenPassedIntoPdo' => true,
        ]);

        $dsn = $this->buildAccessTokenDsn($token);
        $pdo = $this->pdoConnector->connect($dsn, null, null, $this->pdoOptions());
        DatabaseAuthDiagnostics::log('pdo_factory.pdo.created', [
            'pdoConnectionCreated' => true,
            'authMode' => 'entra-cli',
            'dsnBase' => $this->buildAccessTokenDsn('[REDACTED]'),
            'accessTokenOptionPresent' => true,
            'uidPwdPassed' => false,
            'authenticationKeywordPresent' => false,
        ]);

        return $pdo;
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

        $pdo = $this->pdoConnector->connect(
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
    }

    private function mapConnectFailure(PDOException $exception, ?string $transientReason): DatabaseAuthFailed|DatabaseUnavailable
    {
        if ($transientReason !== null) {
            return DatabaseTemporarilyUnavailable::afterConnectTimeout($transientReason, $exception);
        }

        $message = strtolower($exception->getMessage());
        if (
            str_contains($message, 'login failed')
            || str_contains($message, 'authentication failed')
            || str_contains($message, 'login is invalid')
        ) {
            return DatabaseAuthFailed::because('Unable to authenticate with Azure SQL.');
        }

        return DatabaseUnavailable::fromThrowable($exception);
    }

    private function maxConnectAttempts(): int
    {
        if (!$this->settings->dbConnectRetryEnabled) {
            return 1;
        }

        return min(2, max(1, $this->settings->dbConnectRetryMaxAttempts));
    }

    private function buildDsn(): string
    {
        $encrypt = $this->settings->dbEncrypt ? 'yes' : 'no';
        $trust = $this->settings->dbTrustServerCertificate ? 'yes' : 'no';

        return sprintf(
            'sqlsrv:Server=tcp:%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=%d',
            $this->settings->dbHost,
            $this->settings->dbPort,
            $this->settings->dbDatabase,
            $encrypt,
            $trust,
            $this->settings->dbLoginTimeoutSeconds,
        );
    }

    private function buildAccessTokenDsn(string $accessToken): string
    {
        $encrypt = $this->settings->dbEncrypt ? 'yes' : 'no';
        $trust = $this->settings->dbTrustServerCertificate ? 'yes' : 'no';
        $server = $this->settings->dbHost;
        if ($this->settings->dbPort !== 1433) {
            $server .= ',' . $this->settings->dbPort;
        }

        return sprintf(
            'sqlsrv:server = %s; Database = %s; AccessToken = %s; Encrypt = %s; TrustServerCertificate = %s; LoginTimeout = %d',
            $server,
            $this->settings->dbDatabase,
            $accessToken,
            $encrypt,
            $trust,
            $this->settings->dbLoginTimeoutSeconds,
        );
    }

    private function redactedDsn(): string
    {
        if ($this->settings->usesEntraCliAuth()) {
            return $this->buildAccessTokenDsn('[REDACTED]');
        }

        return $this->buildDsn();
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
