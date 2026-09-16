<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\DatabaseAuthFailed;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;

/**
 * On-demand readiness. Opens Azure SQL and must not be used as a frequent
 * serverless/non-prod probe — that prevents auto-pause. Prefer GET /health.
 */
final class DatabaseHealthAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AzureSqlPdoFactory $pdoFactory,
        private readonly JsonResponder $responder,
        private readonly RequestPerformanceMetrics $metrics,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        if (!$this->settings->usesAzureSqlUserRepository()) {
            return $this->responder->payload([
                'success' => true,
                'service' => $this->settings->appName,
                'database' => [
                    'configured' => false,
                    'driver' => $this->settings->userRepository,
                    'status' => 'skipped',
                    'message' => 'USER_REPOSITORY is not azure-sql; database health check skipped.',
                ],
            ]);
        }

        try {
            $pdo = $this->pdoFactory->create();
            $connection = new PdoSqlConnection($pdo, $this->metrics);
            $connection->fetchAll('SELECT 1 AS [Ok]');
            \TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics::log('database_health.first_query.success', [
                'firstSqlQuerySuccess' => true,
                'authMode' => $this->settings->dbAuthMode,
                'userRepository' => $this->settings->userRepository,
            ]);
        } catch (DatabaseTemporarilyUnavailable $exception) {
            \TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics::logThrowable(
                'database_health.temporarily_unavailable',
                $exception,
                [
                    'failureLocation' => 'database_health',
                    'firstSqlQuerySuccess' => false,
                    'retryReason' => $exception->retryReason,
                ],
            );
            error_log('Database temporarily unavailable: ' . $exception->getMessage());

            return $this->responder->error(
                'DATABASE_TEMPORARILY_UNAVAILABLE',
                'The database is temporarily unavailable.',
                503,
            );
        } catch (DatabaseAuthFailed $exception) {
            \TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics::logThrowable(
                'database_health.auth_failed',
                $exception,
                [
                    'failureLocation' => 'database_health',
                    'firstSqlQuerySuccess' => false,
                ],
            );
            error_log('Database authentication failed: ' . $exception->getMessage());

            return $this->responder->error(
                'DATABASE_AUTH_FAILED',
                'Unable to authenticate with Azure SQL.',
                503,
            );
        } catch (DatabaseUnavailable $exception) {
            \TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics::logThrowable(
                'database_health.unavailable',
                $exception,
                [
                    'failureLocation' => 'database_health',
                    'firstSqlQuerySuccess' => false,
                ],
            );
            error_log('Database health check failed: ' . $exception->getMessage());

            return $this->responder->error(
                'DATABASE_UNAVAILABLE',
                'The database is currently unavailable.',
                503,
            );
        } catch (\Throwable $exception) {
            \TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics::logThrowable(
                'database_health.first_query.failed',
                $exception,
                [
                    'failureLocation' => 'during_first_select',
                    'firstSqlQuerySuccess' => false,
                ],
            );
            throw $exception;
        }

        // Public-safe payload only — no host, database name, or auth mode.
        return $this->responder->payload([
            'success' => true,
            'service' => $this->settings->appName,
            'database' => [
                'configured' => true,
                'status' => 'healthy',
            ],
        ]);
    }
}
