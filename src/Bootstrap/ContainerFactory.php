<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use DI\Container;
use DI\ContainerBuilder;
use PDO;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\DatabaseHealthAction;
use TriCab\Api\Http\Action\GetAuthenticatedUserAction;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Middleware\BearerTokenAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\CorsMiddleware;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\JsonExceptionMiddleware;
use TriCab\Api\Identity\Authentication\EntraJwksClient;
use TriCab\Api\Identity\Authentication\EntraJwksProvider;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\EntraTokenValidator;
use TriCab\Api\Identity\Authentication\MicrosoftEntraJwtValidator;
use TriCab\Api\Identity\Persistence\AzureSqlUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Database\AzureSqlConnectionFactory;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Contract\UserRepository;

final class ContainerFactory
{
    public static function create(Settings $settings): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        $definitions = [
            Settings::class => $settings,
            JsonResponder::class => \DI\create(JsonResponder::class),
            EntraJwksProvider::class => \DI\autowire(EntraJwksClient::class),
            EntraJwksClient::class => \DI\autowire(EntraJwksClient::class),
            EntraJwtValidationDebugLogger::class => \DI\autowire(EntraJwtValidationDebugLogger::class),
            EntraTokenValidator::class => \DI\autowire(MicrosoftEntraJwtValidator::class),
            ResolveAuthenticatedUserHandler::class => \DI\autowire(ResolveAuthenticatedUserHandler::class),
            HealthAction::class => \DI\autowire(HealthAction::class),
            DatabaseHealthAction::class => \DI\autowire(DatabaseHealthAction::class),
            GetAuthenticatedUserAction::class => \DI\autowire(GetAuthenticatedUserAction::class),
            DevelopmentAuthenticationMiddleware::class => \DI\autowire(DevelopmentAuthenticationMiddleware::class),
            BearerTokenAuthenticationMiddleware::class => \DI\autowire(BearerTokenAuthenticationMiddleware::class),
            CorsMiddleware::class => \DI\autowire(CorsMiddleware::class),
            JsonExceptionMiddleware::class => \DI\autowire(JsonExceptionMiddleware::class),
            AzureCliAccessTokenProvider::class => \DI\create(AzureCliAccessTokenProvider::class)
                ->constructor($settings->azureCliBinary),
            AzureSqlPdoFactory::class => \DI\autowire(AzureSqlPdoFactory::class),
            AzureSqlConnectionFactory::class => \DI\autowire(AzureSqlConnectionFactory::class),
        ];

        if ($settings->usesAzureSqlUserRepository()) {
            $definitions[PDO::class] = static function (Container $container): PDO {
                /** @var AzureSqlPdoFactory $factory */
                $factory = $container->get(AzureSqlPdoFactory::class);

                return $factory->create();
            };
            $definitions[SqlConnection::class] = \DI\autowire(PdoSqlConnection::class);
            $definitions[UserRepository::class] = \DI\autowire(AzureSqlUserRepository::class);
        } else {
            $definitions[UserRepository::class] = \DI\autowire(InMemoryApiUserRepository::class);
        }

        $builder->addDefinitions($definitions);

        return $builder->build();
    }
}
