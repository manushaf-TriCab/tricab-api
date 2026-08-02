<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use DI\Container;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\DatabaseHealthAction;
use TriCab\Api\Http\Action\GetAuthenticatedUserAction;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Action\LogoutGuestAction;
use TriCab\Api\Http\Action\RequestGuestOtpAction;
use TriCab\Api\Http\Action\VerifyGuestOtpAction;
use TriCab\Api\Http\Middleware\BearerTokenAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\CorsMiddleware;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\JsonExceptionMiddleware;

final class AppBuilder
{
    /**
     * @return App<ContainerInterface>
     */
    public static function build(Container $container): App
    {
        AppFactory::setContainer($container);
        /** @var App<ContainerInterface> $app */
        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        /** @var JsonExceptionMiddleware $jsonExceptionMiddleware */
        $jsonExceptionMiddleware = $container->get(JsonExceptionMiddleware::class);
        $app->add($jsonExceptionMiddleware);

        /** @var BearerTokenAuthenticationMiddleware $bearerMiddleware */
        $bearerMiddleware = $container->get(BearerTokenAuthenticationMiddleware::class);
        $app->add($bearerMiddleware);

        /** @var DevelopmentAuthenticationMiddleware $devAuthMiddleware */
        $devAuthMiddleware = $container->get(DevelopmentAuthenticationMiddleware::class);
        $app->add($devAuthMiddleware);

        /** @var CorsMiddleware $corsMiddleware */
        $corsMiddleware = $container->get(CorsMiddleware::class);
        $app->add($corsMiddleware);

        /** @var Settings $settings */
        $settings = $container->get(Settings::class);
        $app->addErrorMiddleware($settings->appDebug, true, true);

        self::registerRoutes($app, $settings);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerRoutes(App $app, Settings $settings): void
    {
        $app->get('/health', HealthAction::class);
        $app->get('/health/database', DatabaseHealthAction::class);
        $app->get('/api/v1/me', GetAuthenticatedUserAction::class);

        if ($settings->isGuestJwtConfigured()) {
            $app->post('/api/v1/auth/guest/request-otp', RequestGuestOtpAction::class);
            $app->post('/api/v1/auth/guest/verify-otp', VerifyGuestOtpAction::class);
            $app->post('/api/v1/auth/guest/logout', LogoutGuestAction::class);
        }
    }
}
