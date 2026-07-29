<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Infrastructure\Database\AzureSqlConnectionFactory;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;

final class DatabaseHealthAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AzureSqlConnectionFactory $connectionFactory,
        private readonly JsonResponder $responder,
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
            $pdo = $this->connectionFactory->create();
            $connection = new PdoSqlConnection($pdo);
            $connection->execute('SELECT 1');
        } catch (DatabaseUnavailable $exception) {
            error_log('Database health check failed: ' . $exception->getMessage());

            return $this->responder->error(
                'DATABASE_UNAVAILABLE',
                'The database is currently unavailable.',
                503,
            );
        }

        return $this->responder->payload([
            'success' => true,
            'service' => $this->settings->appName,
            'database' => [
                'configured' => true,
                'driver' => $this->settings->dbDriver,
                'host' => $this->settings->dbHost,
                'database' => $this->settings->dbDatabase,
                'status' => 'healthy',
            ],
        ]);
    }
}
