<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use DI\Container;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Action\Admin\AddProductDocumentAction;
use TriCab\Api\Http\Action\Admin\AddProductMediaAction;
use TriCab\Api\Http\Action\Admin\ArchiveProductAction;
use TriCab\Api\Http\Action\Admin\ArchiveProductVariantAction;
use TriCab\Api\Http\Action\Admin\AssignProductApplicationsAction;
use TriCab\Api\Http\Action\Admin\AssignProductCodeAction;
use TriCab\Api\Http\Action\Admin\AssignProductIndustriesAction;
use TriCab\Api\Http\Action\Admin\AssignProductMarketsAction;
use TriCab\Api\Http\Action\Admin\AssignProductSpecificationsAction;
use TriCab\Api\Http\Action\Admin\AssignRolePermissionAction;
use TriCab\Api\Http\Action\Admin\AssignUserRoleAction;
use TriCab\Api\Http\Action\Admin\AssignVariantSpecificationsAction;
use TriCab\Api\Http\Action\Admin\ChangeProductLifecycleAction;
use TriCab\Api\Http\Action\Admin\ChangeProductSlugAction;
use TriCab\Api\Http\Action\Admin\ChangeCustomerContactStatusAction;
use TriCab\Api\Http\Action\Admin\ChangeCustomerPortalAccessAction;
use TriCab\Api\Http\Action\Admin\ChangeCustomerStatusAction;
use TriCab\Api\Http\Action\Admin\ChangeRoleStatusAction;
use TriCab\Api\Http\Action\Admin\ChangeUserStatusAction;
use TriCab\Api\Http\Action\Admin\CreateCustomerAction;
use TriCab\Api\Http\Action\Admin\CreateCustomerContactAction;
use TriCab\Api\Http\Action\Admin\CreateCustomerPortalUserAction;
use TriCab\Api\Http\Action\Admin\CreatePermissionAction;
use TriCab\Api\Http\Action\Admin\CreateProductAction;
use TriCab\Api\Http\Action\Admin\CreateProductVariantAction;
use TriCab\Api\Http\Action\Admin\CreateRoleAction;
use TriCab\Api\Http\Action\Admin\CreateUserAction;
use TriCab\Api\Http\Action\Admin\GetCustomerAction;
use TriCab\Api\Http\Action\Admin\GetCustomerContactAction;
use TriCab\Api\Http\Action\Admin\GetProductAction;
use TriCab\Api\Http\Action\Admin\GetProductAuditAction;
use TriCab\Api\Http\Action\Admin\GetProductLookupsAction;
use TriCab\Api\Http\Action\Admin\GetProductPricingAction;
use TriCab\Api\Http\Action\Admin\GetProductPublicationReadinessAction;
use TriCab\Api\Http\Action\Admin\GetProductSeoAction;
use TriCab\Api\Http\Action\Admin\GetRoleAction;
use TriCab\Api\Http\Action\Admin\GetUserAction;
use TriCab\Api\Http\Action\Admin\LinkCustomerContactUserAction;
use TriCab\Api\Http\Action\Admin\ListAuditAction;
use TriCab\Api\Http\Action\Admin\ListCustomerContactsAction;
use TriCab\Api\Http\Action\Admin\ListCustomerPortalUsersAction;
use TriCab\Api\Http\Action\Admin\ListCustomersAction;
use TriCab\Api\Http\Action\Admin\ListProductDocumentsAction;
use TriCab\Api\Http\Action\Admin\ListProductMediaAction;
use TriCab\Api\Http\Action\Admin\ListProductsAction;
use TriCab\Api\Http\Action\Admin\ListProductVariantsAction;
use TriCab\Api\Http\Action\Admin\ListPermissionsAction;
use TriCab\Api\Http\Action\Admin\ListRolesAction;
use TriCab\Api\Http\Action\Admin\ListUsersAction;
use TriCab\Api\Http\Action\Admin\PreviewProductCodeAction;
use TriCab\Api\Http\Action\Admin\PublishProductAction;
use TriCab\Api\Http\Action\Admin\RemoveRolePermissionAction;
use TriCab\Api\Http\Action\Admin\RemoveUserRoleAction;
use TriCab\Api\Http\Action\Admin\ReorderProductMediaAction;
use TriCab\Api\Http\Action\Admin\ReplaceProductRelationshipsAction;
use TriCab\Api\Http\Action\Admin\RestoreArchivedProductAction;
use TriCab\Api\Http\Action\Admin\RestoreDeletedProductAction;
use TriCab\Api\Http\Action\Admin\RestoreProductVariantAction;
use TriCab\Api\Http\Action\Admin\SetPrimaryProductImageAction;
use TriCab\Api\Http\Action\Admin\SoftDeleteProductAction;
use TriCab\Api\Http\Action\Admin\SoftDeleteProductDocumentAction;
use TriCab\Api\Http\Action\Admin\SoftDeleteProductMediaAction;
use TriCab\Api\Http\Action\Admin\SoftDeleteProductVariantAction;
use TriCab\Api\Http\Action\Admin\UnlinkCustomerContactUserAction;
use TriCab\Api\Http\Action\Admin\UnpublishProductAction;
use TriCab\Api\Http\Action\Admin\UpdateCustomerAction;
use TriCab\Api\Http\Action\Admin\UpdateCustomerContactAction;
use TriCab\Api\Http\Action\Admin\UpdatePermissionAction;
use TriCab\Api\Http\Action\Admin\UpdateProductAction;
use TriCab\Api\Http\Action\Admin\UpdateProductSeoAction;
use TriCab\Api\Http\Action\Admin\UpdateProductVariantAction;
use TriCab\Api\Http\Action\Admin\UpsertProductPricingAction;
use TriCab\Api\Http\Action\Admin\WithdrawProductDocumentAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductBySlugAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductDocumentsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductMediaAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductRelationshipsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductSpecificationsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductVariantsAction;
use TriCab\Api\Http\Action\PublicProduct\SearchPublicProductsAction;
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

        self::registerPublicProductRoutes($app);
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

        self::registerAdminCustomerRoutes($app, $require);
        self::registerAdminProductRoutes($app, $require);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerPublicProductRoutes(App $app): void
    {
        $app->get('/api/v1/products/{slug}', GetPublicProductBySlugAction::class);
        $app->get('/api/v1/products/{slug}/variants', GetPublicProductVariantsAction::class);
        $app->get('/api/v1/products/{slug}/media', GetPublicProductMediaAction::class);
        $app->get('/api/v1/products/{slug}/documents', GetPublicProductDocumentsAction::class);
        $app->get('/api/v1/products/{slug}/relationships', GetPublicProductRelationshipsAction::class);
        $app->get('/api/v1/products/{slug}/specifications', GetPublicProductSpecificationsAction::class);
        $app->get('/api/v1/product-search', SearchPublicProductsAction::class);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerAdminProductRoutes(App $app, RequirePermission $require): void
    {
        $app->get('/api/v1/admin/products', ListProductsAction::class)->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products', CreateProductAction::class)->add(($require)('PRODUCT.CREATE'));
        $app->get('/api/v1/admin/products/{productPublicId}', GetProductAction::class)->add(($require)('PRODUCT.READ'));
        $app->patch('/api/v1/admin/products/{productPublicId}', UpdateProductAction::class)->add(($require)('PRODUCT.UPDATE'));

        $app->post('/api/v1/admin/products/{productPublicId}/publish', PublishProductAction::class)
            ->add(($require)('PRODUCT.PUBLISH'));
        $app->post('/api/v1/admin/products/{productPublicId}/unpublish', UnpublishProductAction::class)
            ->add(($require)('PRODUCT.PUBLISH'));
        $app->post('/api/v1/admin/products/{productPublicId}/archive', ArchiveProductAction::class)
            ->add(($require)('PRODUCT.ARCHIVE'));
        $app->post('/api/v1/admin/products/{productPublicId}/restore-archive', RestoreArchivedProductAction::class)
            ->add(($require)('PRODUCT.RESTORE'));
        $app->post('/api/v1/admin/products/{productPublicId}/soft-delete', SoftDeleteProductAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->post('/api/v1/admin/products/{productPublicId}/restore-deletion', RestoreDeletedProductAction::class)
            ->add(($require)('PRODUCT.RESTORE'));
        $app->patch('/api/v1/admin/products/{productPublicId}/lifecycle', ChangeProductLifecycleAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->get('/api/v1/admin/products/{productPublicId}/publication-readiness', GetProductPublicationReadinessAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/products/{productPublicId}/audit', GetProductAuditAction::class)
            ->add(($require)('PRODUCT.AUDIT.VIEW'));

        $app->get('/api/v1/admin/products/{productPublicId}/variants', ListProductVariantsAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products/{productPublicId}/variants', CreateProductVariantAction::class)
            ->add(($require)('PRODUCT.VARIANTS.MANAGE'));

        $app->get('/api/v1/admin/products/{productPublicId}/media', ListProductMediaAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products/{productPublicId}/media', AddProductMediaAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/media/reorder', ReorderProductMediaAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));

        $app->get('/api/v1/admin/products/{productPublicId}/documents', ListProductDocumentsAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products/{productPublicId}/documents', AddProductDocumentAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));

        $app->put('/api/v1/admin/products/{productPublicId}/markets', AssignProductMarketsAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/industries', AssignProductIndustriesAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/applications', AssignProductApplicationsAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/relationships', ReplaceProductRelationshipsAction::class)
            ->add(($require)('PRODUCT.RELATIONSHIPS.MANAGE'));
        $app->put('/api/v1/admin/products/{productPublicId}/specifications', AssignProductSpecificationsAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->put('/api/v1/admin/products/{productPublicId}/seo', UpdateProductSeoAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/pricing', UpsertProductPricingAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/slugs', ChangeProductSlugAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->get('/api/v1/admin/products/{productPublicId}/seo', GetProductSeoAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/products/{productPublicId}/pricing', GetProductPricingAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));

        $app->get('/api/v1/admin/product-lookups', GetProductLookupsAction::class)->add(($require)('PRODUCT.READ'));

        $app->patch('/api/v1/admin/product-variants/{variantPublicId}', UpdateProductVariantAction::class)
            ->add(($require)('PRODUCT.VARIANTS.MANAGE'));
        $app->post('/api/v1/admin/product-variants/{variantPublicId}/archive', ArchiveProductVariantAction::class)
            ->add(($require)('PRODUCT.VARIANTS.MANAGE'));
        $app->post('/api/v1/admin/product-variants/{variantPublicId}/soft-delete', SoftDeleteProductVariantAction::class)
            ->add(($require)('PRODUCT.VARIANTS.MANAGE'));
        $app->post('/api/v1/admin/product-variants/{variantPublicId}/restore', RestoreProductVariantAction::class)
            ->add(($require)('PRODUCT.VARIANTS.MANAGE'));
        $app->put('/api/v1/admin/product-variants/{variantPublicId}/specifications', AssignVariantSpecificationsAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));

        $app->post('/api/v1/admin/product-media/{mediaPublicId}/set-primary', SetPrimaryProductImageAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-media/{mediaPublicId}/soft-delete', SoftDeleteProductMediaAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));

        $app->post('/api/v1/admin/product-documents/{documentPublicId}/withdraw', WithdrawProductDocumentAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));
        $app->post('/api/v1/admin/product-documents/{documentPublicId}/soft-delete', SoftDeleteProductDocumentAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));

        $app->post('/api/v1/admin/product-codes/preview', PreviewProductCodeAction::class)
            ->add(($require)('PRODUCT.CODES.MANAGE'));
        $app->post('/api/v1/admin/product-codes/assign', AssignProductCodeAction::class)
            ->add(($require)('PRODUCT.CODES.MANAGE'));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerAdminCustomerRoutes(App $app, RequirePermission $require): void
    {
        $app->get('/api/v1/admin/customers', ListCustomersAction::class)->add(($require)('CUSTOMER.READ'));
        $app->get('/api/v1/admin/customers/{customerPublicId}', GetCustomerAction::class)
            ->add(($require)('CUSTOMER.READ'));
        $app->post('/api/v1/admin/customers', CreateCustomerAction::class)->add(($require)('CUSTOMER.UPDATE'));
        $app->patch('/api/v1/admin/customers/{customerPublicId}', UpdateCustomerAction::class)
            ->add(($require)('CUSTOMER.UPDATE'));
        $app->patch('/api/v1/admin/customers/{customerPublicId}/status', ChangeCustomerStatusAction::class)
            ->add(($require)('CUSTOMER.UPDATE'));

        $app->get('/api/v1/admin/customers/{customerPublicId}/contacts', ListCustomerContactsAction::class)
            ->add(($require)('CUSTOMER.CONTACT.READ'));
        $app->get('/api/v1/admin/customer-contacts/{contactPublicId}', GetCustomerContactAction::class)
            ->add(($require)('CUSTOMER.CONTACT.READ'));
        $app->post('/api/v1/admin/customers/{customerPublicId}/contacts', CreateCustomerContactAction::class)
            ->add(($require)('CUSTOMER.CONTACT.CREATE'));
        $app->patch('/api/v1/admin/customer-contacts/{contactPublicId}', UpdateCustomerContactAction::class)
            ->add(($require)('CUSTOMER.CONTACT.UPDATE'));
        $app->patch('/api/v1/admin/customer-contacts/{contactPublicId}/status', ChangeCustomerContactStatusAction::class)
            ->add(($require)('CUSTOMER.CONTACT.STATUS.UPDATE'));

        $app->post('/api/v1/admin/customer-contacts/{contactPublicId}/portal-user', CreateCustomerPortalUserAction::class)
            ->add(($require)('CUSTOMER.USER.INVITE'));
        $app->post('/api/v1/admin/customer-contacts/{contactPublicId}/link-user', LinkCustomerContactUserAction::class)
            ->add(($require)('CUSTOMER.USER.LINK'));
        $app->delete(
            '/api/v1/admin/customer-contacts/{contactPublicId}/link-user/{userPublicId}',
            UnlinkCustomerContactUserAction::class,
        )->add(($require)('CUSTOMER.USER.UNLINK'));
        $app->patch('/api/v1/admin/customer-contacts/{contactPublicId}/portal-access', ChangeCustomerPortalAccessAction::class)
            ->add(($require)('CUSTOMER.PORTAL_ACCESS.MANAGE'));

        $app->get('/api/v1/admin/customers/{customerPublicId}/users', ListCustomerPortalUsersAction::class)
            ->add(($require)('CUSTOMER.USER.READ'));
    }
}
