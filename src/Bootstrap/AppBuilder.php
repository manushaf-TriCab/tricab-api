<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use DI\Container;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\Admin\AssignRolePermissionAction;
use TriCab\Api\Http\Action\Admin\AssignUserRoleAction;
use TriCab\Api\Http\Action\Admin\ChangeRoleStatusAction;
use TriCab\Api\Http\Action\Admin\ChangeUserStatusAction;
use TriCab\Api\Http\Action\Admin\CreatePermissionAction;
use TriCab\Api\Http\Action\Admin\CreateRoleAction;
use TriCab\Api\Http\Action\Admin\CreateUserAction;
use TriCab\Api\Http\Action\Admin\GetRoleAction;
use TriCab\Api\Http\Action\Admin\GetUserAction;
use TriCab\Api\Http\Action\Admin\ListAuditAction;
use TriCab\Api\Http\Action\Admin\ListPermissionsAction;
use TriCab\Api\Http\Action\Admin\ListRolesAction;
use TriCab\Api\Http\Action\Admin\ListUsersAction;
use TriCab\Api\Http\Action\Admin\RemoveRolePermissionAction;
use TriCab\Api\Http\Action\Admin\RemoveUserRoleAction;
use TriCab\Api\Http\Action\Admin\UpdatePermissionAction;
use TriCab\Api\Http\Action\Admin\UpdateRoleAction;
use TriCab\Api\Http\Action\Admin\UpdateUserAction;
use TriCab\Api\Http\Action\DatabaseHealthAction;
use TriCab\Api\Http\Action\GetAuthenticatedUserAction;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Action\LogoutGuestAction;
use TriCab\Api\Http\Action\RequestGuestOtpAction;
use TriCab\Api\Http\Action\VerifyGuestOtpAction;
use TriCab\Api\Http\Auth\RequirePermission;
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

        self::registerRoutes($app, $settings, $container);

        return $app;
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerRoutes(App $app, Settings $settings, Container $container): void
    {
        $app->get('/health', HealthAction::class);
        $app->get('/health/database', DatabaseHealthAction::class);
        $app->get('/api/v1/me', GetAuthenticatedUserAction::class);

        if ($settings->isGuestJwtConfigured()) {
            $app->post('/api/v1/auth/guest/request-otp', RequestGuestOtpAction::class);
            $app->post('/api/v1/auth/guest/verify-otp', VerifyGuestOtpAction::class);
            $app->post('/api/v1/auth/guest/logout', LogoutGuestAction::class);
        }

        self::registerAdminRoutes($app, $container);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerAdminRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $app->get('/api/v1/admin/users', ListUsersAction::class)->add(($require)('USER.READ'));
        $app->get('/api/v1/admin/users/{publicId}', GetUserAction::class)->add(($require)('USER.READ'));
        $app->post('/api/v1/admin/users', CreateUserAction::class)->add(($require)('USER.CREATE'));
        $app->patch('/api/v1/admin/users/{publicId}', UpdateUserAction::class)->add(($require)('USER.UPDATE'));
        $app->patch('/api/v1/admin/users/{publicId}/status', ChangeUserStatusAction::class)
            ->add(($require)('USER.STATUS.UPDATE'));
        $app->post('/api/v1/admin/users/{publicId}/roles', AssignUserRoleAction::class)
            ->add(($require)('USER.ROLE.ASSIGN'));
        $app->delete('/api/v1/admin/users/{publicId}/roles/{roleCode}', RemoveUserRoleAction::class)
            ->add(($require)('USER.ROLE.REMOVE'));

        $app->get('/api/v1/admin/roles', ListRolesAction::class)->add(($require)('ROLE.READ'));
        $app->get('/api/v1/admin/roles/{roleCode}', GetRoleAction::class)->add(($require)('ROLE.READ'));
        $app->post('/api/v1/admin/roles', CreateRoleAction::class)->add(($require)('ROLE.CREATE'));
        $app->patch('/api/v1/admin/roles/{roleCode}', UpdateRoleAction::class)->add(($require)('ROLE.UPDATE'));
        $app->patch('/api/v1/admin/roles/{roleCode}/status', ChangeRoleStatusAction::class)
            ->add(($require)('ROLE.STATUS.UPDATE'));
        $app->post('/api/v1/admin/roles/{roleCode}/permissions', AssignRolePermissionAction::class)
            ->add(($require)('ROLE.PERMISSION.ASSIGN'));
        $app->delete('/api/v1/admin/roles/{roleCode}/permissions/{permissionCode}', RemoveRolePermissionAction::class)
            ->add(($require)('ROLE.PERMISSION.REMOVE'));

        $app->get('/api/v1/admin/permissions', ListPermissionsAction::class)->add(($require)('PERMISSION.READ'));
        $app->post('/api/v1/admin/permissions', CreatePermissionAction::class)->add(($require)('PERMISSION.CREATE'));
        $app->patch('/api/v1/admin/permissions/{permissionCode}', UpdatePermissionAction::class)
            ->add(($require)('PERMISSION.UPDATE'));

        $app->get('/api/v1/admin/user-management/audit', ListAuditAction::class)
            ->add(($require)('AUDIT.USER_MANAGEMENT.READ'));
    }
}
