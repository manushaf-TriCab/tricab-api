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
use TriCab\Api\Http\Action\Admin\ProductSeriesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductCategoriesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductFamiliesAdminAction;
use TriCab\Api\Http\Action\Admin\CatalogueTabsAdminAction;
use TriCab\Api\Http\Action\Admin\CatalogueNodesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductCataloguePlacementsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationDefinitionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationGroupsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationOptionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationUnitsAdminAction;
use TriCab\Api\Http\Action\Admin\Migration\ProductMigrationAdminAction;
use TriCab\Api\Http\Action\Admin\DeleteProductDocumentFileAction;
use TriCab\Api\Http\Action\Admin\ReplaceProductDocumentUploadAction;
use TriCab\Api\Http\Action\Admin\CompleteProductDocumentUploadAction;
use TriCab\Api\Http\Action\Admin\InitiateProductDocumentUploadAction;
use TriCab\Api\Http\Action\Admin\DeleteProductMediaFileAction;
use TriCab\Api\Http\Action\Admin\ReplaceProductMediaUploadAction;
use TriCab\Api\Http\Action\Admin\CompleteProductMediaUploadAction;
use TriCab\Api\Http\Action\Admin\InitiateProductMediaUploadAction;
use TriCab\Api\Http\Action\Admin\CompleteArModelUploadAction;
use TriCab\Api\Http\Action\Admin\InitiateArModelUploadAction;
use TriCab\Api\Http\Action\Admin\Product3DModelsAdminAction;
use TriCab\Api\Http\Action\Admin\GetProduct3DSpecificationSourceAction;
use TriCab\Api\Http\Action\Admin\ActivateProduct360SetAction;
use TriCab\Api\Http\Action\Admin\Complete360FrameUploadAction;
use TriCab\Api\Http\Action\Admin\Initiate360FrameUploadAction;
use TriCab\Api\Http\Action\Admin\CreateProduct360SetAction;
use TriCab\Api\Http\Action\Admin\ArchiveProductAction;
use TriCab\Api\Http\Action\Admin\ArchiveProductVariantAction;
use TriCab\Api\Http\Action\Admin\AssignProductApplicationsAction;
use TriCab\Api\Http\Action\Admin\AssignProductCodeAction;
use TriCab\Api\Http\Action\Admin\AssignProductIndustriesAction;
use TriCab\Api\Http\Action\Admin\AssignProductMarketsAction;
use TriCab\Api\Http\Action\Admin\AssignProductRegionsAction;
use TriCab\Api\Http\Action\Admin\AssignProductSpecificationsAction;
use TriCab\Api\Http\Action\Admin\GetProductSpecificationsAction;
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
use TriCab\Api\Http\Action\Admin\GetCustomerViewThemeAction;
use TriCab\Api\Http\Action\Admin\GetProductCustomerViewAction;
use TriCab\Api\Http\Action\Admin\UpdateCustomerViewThemeAction;
use TriCab\Api\Http\Action\Admin\UpdateProductMediaRoleAction;
use TriCab\Api\Http\Action\Admin\GetProductLookupsAction;
use TriCab\Api\Http\Action\Admin\GetProductPricingAction;
use TriCab\Api\Http\Action\Admin\GetProductPublicationReadinessAction;
use TriCab\Api\Http\Action\Admin\GetProductRegionsAction;
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
use TriCab\Api\Http\Action\Admin\RegionAdminAction;
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
use TriCab\Api\Http\Action\PublicProduct\DownloadProductDocumentAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductBySlugAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductCustomerViewAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCataloguePanelAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCatalogueTabsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCatalogueTabTreeAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCatalogueNodeAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductDocumentsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductMediaAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductRelationshipsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductSpecificationsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductVariantsAction;
use TriCab\Api\Http\Action\PublicProduct\SearchPublicProductsAction;
use TriCab\Api\Http\Action\PublicRegionAction;
use TriCab\Api\Http\Action\AssistantMessageAction;
use TriCab\Api\Http\Action\Admin\UpdateRoleAction;
use TriCab\Api\Http\Action\Admin\UpdateUserAction;
use TriCab\Api\Http\Action\Admin\GetAdminDashboardAction;
use TriCab\Api\Http\Action\Admin\GetAdminDashboardProductActivityAction;
use TriCab\Api\Http\Action\DatabaseHealthAction;
use TriCab\Api\Http\Action\Dev\DevProductFileDeliveryAction;
use TriCab\Api\Http\Action\GetAuthenticatedUserAction;
use TriCab\Api\Http\Action\GenerateDocumentPdfAction;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Action\LogoutGuestAction;
use TriCab\Api\Http\Action\RequestGuestOtpAction;
use TriCab\Api\Http\Action\AuthenticateGoogleAction;
use TriCab\Api\Http\Action\AppleAuthChallengeAction;
use TriCab\Api\Http\Action\AuthenticateAppleAction;
use TriCab\Api\Http\Action\VerifyGuestOtpAction;
use TriCab\Api\Http\Auth\RequirePermission;
use TriCab\Api\Http\Middleware\BearerTokenAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\CorsMiddleware;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\JsonExceptionMiddleware;
use TriCab\Api\Http\Middleware\ReferenceDataCacheInvalidationMiddleware;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMiddleware;

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

        /** @var ReferenceDataCacheInvalidationMiddleware $cacheInvalidation */
        $cacheInvalidation = $container->get(ReferenceDataCacheInvalidationMiddleware::class);
        $app->add($cacheInvalidation);

        /** @var Settings $settings */
        $settings = $container->get(Settings::class);
        $app->addErrorMiddleware($settings->appDebug, true, true);

        /** @var RequestPerformanceMiddleware $performanceMiddleware */
        $performanceMiddleware = $container->get(RequestPerformanceMiddleware::class);
        $app->add($performanceMiddleware);

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

        if ($settings->isGoogleAuthConfigured()) {
            $app->post('/api/v1/auth/google', AuthenticateGoogleAction::class);
        }

        if ($settings->isAppleAuthConfigured()) {
            $app->get('/api/v1/auth/apple/challenge', AppleAuthChallengeAction::class);
            $app->post('/api/v1/auth/apple', AuthenticateAppleAction::class);
        }

        if ($settings->isDevMemoryProductFileDeliveryEnabled()) {
            $app->get('/api/v1/dev/product-files/{filePublicId}', DevProductFileDeliveryAction::class);
        }

        self::registerPublicProductRoutes($app);
        $app->get('/api/v1/regions', PublicRegionAction::class);
        $app->get('/api/v1/regions/current', PublicRegionAction::class);
        $app->post('/api/v1/regions/select', PublicRegionAction::class);
        self::registerAdminRoutes($app, $container);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerAdminRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $app->get('/api/v1/admin/dashboard', GetAdminDashboardAction::class)
            ->add(($require)('ACCOUNT.LOGIN'));
        $app->get('/api/v1/admin/dashboard/product-activity', GetAdminDashboardProductActivityAction::class)
            ->add(($require)('PRODUCT.READ'));

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
        $app->delete('/api/v1/admin/roles/{roleCode}/permissions', RemoveRolePermissionAction::class)
            ->add(($require)('ROLE.PERMISSION.REMOVE'));
        $app->delete('/api/v1/admin/roles/{roleCode}/permissions/{permissionCode}', RemoveRolePermissionAction::class)
            ->add(($require)('ROLE.PERMISSION.REMOVE'));

        $app->get('/api/v1/admin/permissions', ListPermissionsAction::class)->add(($require)('PERMISSION.READ'));
        $app->post('/api/v1/admin/permissions', CreatePermissionAction::class)->add(($require)('PERMISSION.CREATE'));
        $app->patch('/api/v1/admin/permissions/{permissionCode}', UpdatePermissionAction::class)
            ->add(($require)('PERMISSION.UPDATE'));

        $app->get('/api/v1/admin/user-management/audit', ListAuditAction::class)
            ->add(($require)('AUDIT.USER_MANAGEMENT.READ'));

        self::registerAdminCustomerRoutes($app, $require);
        $app->get('/api/v1/admin/regions', RegionAdminAction::class)->add(($require)('REGION.READ'));
        $app->get('/api/v1/admin/regions/{publicId}', RegionAdminAction::class)->add(($require)('REGION.READ'));
        $app->post('/api/v1/admin/regions', RegionAdminAction::class)->add(($require)('REGION.CREATE'));
        $app->put('/api/v1/admin/regions/{publicId}', RegionAdminAction::class)->add(($require)('REGION.UPDATE'));
        $app->post('/api/v1/admin/regions/{publicId}/activate', RegionAdminAction::class)
            ->add(($require)('REGION.UPDATE'));
        $app->post('/api/v1/admin/regions/{publicId}/deactivate', RegionAdminAction::class)
            ->add(($require)('REGION.UPDATE'));
        $app->delete('/api/v1/admin/regions/{publicId}', RegionAdminAction::class)
            ->add(($require)('REGION.DELETE'));
        self::registerAdminProductRoutes($app, $require);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerPublicProductRoutes(App $app): void
    {
        $app->post('/api/v1/assistant/messages', AssistantMessageAction::class);
        $app->get('/api/v1/products/{slug}', GetPublicProductBySlugAction::class);
        $app->get('/api/v1/products/{slug}/customer-view', GetPublicProductCustomerViewAction::class);
        $app->get('/api/v1/customer-view-theme', GetCustomerViewThemeAction::class);
        $app->get('/api/v1/products/{slug}/variants', GetPublicProductVariantsAction::class);
        $app->get('/api/v1/products/{slug}/media', GetPublicProductMediaAction::class);
        $app->get('/api/v1/products/{slug}/documents', GetPublicProductDocumentsAction::class);
        $app->get('/api/v1/products/{slug}/documents/{documentPublicId}/download', DownloadProductDocumentAction::class);
        $app->get('/api/v1/products/{slug}/relationships', GetPublicProductRelationshipsAction::class);
        $app->get('/api/v1/products/{slug}/specifications', GetPublicProductSpecificationsAction::class);
        $app->get('/api/v1/product-search', SearchPublicProductsAction::class);
        $app->get('/api/v1/catalogue/panel', GetPublicCataloguePanelAction::class);
        $app->get('/api/v1/catalogue/tabs', GetPublicCatalogueTabsAction::class);
        $app->get('/api/v1/catalogue/tabs/{tabSlug}/tree', GetPublicCatalogueTabTreeAction::class);
        $app->get('/api/v1/catalogue/nodes/{nodeSlugOrPublicId}', GetPublicCatalogueNodeAction::class);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerAdminProductRoutes(App $app, RequirePermission $require): void
    {
        $app->get('/api/v1/admin/products', ListProductsAction::class)->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products', CreateProductAction::class)->add(($require)('PRODUCT.CREATE'));
        $app->get('/api/v1/admin/products/{productPublicId}', GetProductAction::class)->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/products/{productPublicId}/customer-view', GetProductCustomerViewAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/settings/customer-view-theme', GetCustomerViewThemeAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/settings/customer-view-theme', UpdateCustomerViewThemeAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
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
        $app->post('/api/v1/admin/products/{productPublicId}/media/upload-session', InitiateProductMediaUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/media/reorder', ReorderProductMediaAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));

        $app->get('/api/v1/admin/products/{productPublicId}/documents', ListProductDocumentsAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products/{productPublicId}/documents', AddProductDocumentAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/documents/upload-session', InitiateProductDocumentUploadAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));

        $app->put('/api/v1/admin/products/{productPublicId}/markets', AssignProductMarketsAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->get('/api/v1/admin/products/{productPublicId}/regions', GetProductRegionsAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/products/{productPublicId}/regions', AssignProductRegionsAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/industries', AssignProductIndustriesAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/applications', AssignProductApplicationsAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/relationships', ReplaceProductRelationshipsAction::class)
            ->add(($require)('PRODUCT.RELATIONSHIPS.MANAGE'));
        $app->get('/api/v1/admin/products/{productPublicId}/specifications', GetProductSpecificationsAction::class)
            ->add(($require)('PRODUCT.READ'));
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


        $app->get('/api/v1/admin/product-families', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/product-families', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-families/reorder', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/product-families/{familyPublicId}', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/product-families/{familyPublicId}', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-families/{familyPublicId}/activate', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-families/{familyPublicId}/deactivate', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-families/{familyPublicId}/archive', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-families/{familyPublicId}/restore', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/product-families/{familyPublicId}/usage', ProductFamiliesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));

        $app->get('/api/v1/admin/product-categories', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/product-categories', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-categories/reorder', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/product-categories/{categoryPublicId}', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/product-categories/{categoryPublicId}', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-categories/{categoryPublicId}/activate', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-categories/{categoryPublicId}/deactivate', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-categories/{categoryPublicId}/archive', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-categories/{categoryPublicId}/restore', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/product-categories/{categoryPublicId}/usage', ProductCategoriesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));

        $app->get('/api/v1/admin/product-series', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/product-series', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-series/reorder', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/product-series/{seriesPublicId}', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/product-series/{seriesPublicId}', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-series/{seriesPublicId}/activate', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-series/{seriesPublicId}/deactivate', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-series/{seriesPublicId}/archive', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/product-series/{seriesPublicId}/restore', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/product-series/{seriesPublicId}/usage', ProductSeriesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));

        $app->get('/api/v1/admin/catalogue-tabs', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/catalogue-tabs', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-tabs/reorder', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/catalogue-tabs/{tabPublicId}', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/catalogue-tabs/{tabPublicId}', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-tabs/{tabPublicId}/activate', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-tabs/{tabPublicId}/deactivate', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-tabs/{tabPublicId}/archive', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-tabs/{tabPublicId}/restore', CatalogueTabsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));

        $app->get('/api/v1/admin/catalogue-nodes', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/catalogue-nodes/tree', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/catalogue-nodes', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-nodes/reorder', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/catalogue-nodes/{nodePublicId}', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/catalogue-nodes/{nodePublicId}', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-nodes/{nodePublicId}/move', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-nodes/{nodePublicId}/activate', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-nodes/{nodePublicId}/deactivate', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-nodes/{nodePublicId}/archive', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->post('/api/v1/admin/catalogue-nodes/{nodePublicId}/restore', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/catalogue-nodes/{nodePublicId}/usage', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/catalogue-nodes/{nodePublicId}/products', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/catalogue-nodes/{nodePublicId}/products', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));

        $app->get('/api/v1/admin/products/{productPublicId}/catalogue-placements', ProductCataloguePlacementsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/products/{productPublicId}/catalogue-placements', ProductCataloguePlacementsAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));

        $app->get('/api/v1/admin/specification-groups', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/specification-groups', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-groups/reorder', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-groups/{groupPublicId}', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/specification-groups/{groupPublicId}', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-groups/{groupPublicId}/activate', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-groups/{groupPublicId}/deactivate', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-groups/{groupPublicId}/archive', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-groups/{groupPublicId}/restore', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-groups/{groupPublicId}/usage', SpecificationGroupsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));

        $app->get('/api/v1/admin/specification-units', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/specification-units', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-units/reorder', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-units/{unitPublicId}', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/specification-units/{unitPublicId}', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-units/{unitPublicId}/activate', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-units/{unitPublicId}/deactivate', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-units/{unitPublicId}/archive', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-units/{unitPublicId}/restore', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-units/{unitPublicId}/usage', SpecificationUnitsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));

        $app->get('/api/v1/admin/specification-definitions', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/specification-definitions/export', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/specification-definitions/import', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-definitions', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-definitions/reorder', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-definitions/{definitionPublicId}', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/specification-definitions/{definitionPublicId}', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-definitions/{definitionPublicId}/activate', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-definitions/{definitionPublicId}/deactivate', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-definitions/{definitionPublicId}/archive', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-definitions/{definitionPublicId}/restore', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-definitions/{definitionPublicId}/usage', SpecificationDefinitionsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));

        $app->get('/api/v1/admin/specification-options', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/specification-options', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-options/reorder', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-options/{optionPublicId}', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/specification-options/{optionPublicId}', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-options/{optionPublicId}/activate', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-options/{optionPublicId}/deactivate', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-options/{optionPublicId}/archive', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->post('/api/v1/admin/specification-options/{optionPublicId}/restore', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.SPECIFICATIONS.MANAGE'));
        $app->get('/api/v1/admin/specification-options/{optionPublicId}/usage', SpecificationOptionsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));




        $app->get('/api/v1/admin/product-migration/readiness', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/inspect', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batches', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batches/{batchPublicId}', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/extract', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/normalize', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/validate', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/dry-run', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/execute', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/cancel', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/retry', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batches/{batchPublicId}/rollback', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batches/{batchPublicId}/issues', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batches/{batchPublicId}/reconciliation', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/review', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/review/{issuePublicId}/resolve', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/mappings/classifications', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->put('/api/v1/admin/product-migration/mappings/classifications/{mappingPublicId}', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/mappings/specifications', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->put('/api/v1/admin/product-migration/mappings/specifications/{mappingPublicId}', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/mappings/options', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->put('/api/v1/admin/product-migration/mappings/options/{mappingPublicId}', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));

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
        $app->post('/api/v1/admin/product-media/{mediaPublicId}/role', UpdateProductMediaRoleAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));

        $app->post('/api/v1/admin/products/{productPublicId}/360-sets', CreateProduct360SetAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/ar-models/upload-session', InitiateArModelUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->get('/api/v1/admin/products/{productPublicId}/3d-models', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/products/{productPublicId}/3d/specification-source', GetProduct3DSpecificationSourceAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/upload-session', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->get('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->patch('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/publish', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/unpublish', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/set-primary', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/soft-delete', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/preview-upload-session', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/preview-complete', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/analyse', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->get('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/analysis', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/optimise', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/assets/{assetPublicId}/activate', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/3d-models/{modelPublicId}/assets/revert-to-source', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-3d-models/{modelPublicId}/complete-upload', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-360-sets/{setPublicId}/frames/upload-session', Initiate360FrameUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-360-sets/{setPublicId}/activate', ActivateProduct360SetAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-360-frames/{framePublicId}/complete-upload', Complete360FrameUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-ar-models/{arPublicId}/complete-upload', CompleteArModelUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-media/{mediaPublicId}/complete-upload', CompleteProductMediaUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-media/{mediaPublicId}/replace-upload', ReplaceProductMediaUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->delete('/api/v1/admin/product-media/{mediaPublicId}/file', DeleteProductMediaFileAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-media/{mediaPublicId}/soft-delete', SoftDeleteProductMediaAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));

        $app->post('/api/v1/admin/product-documents/{documentPublicId}/withdraw', WithdrawProductDocumentAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));
        $app->post('/api/v1/admin/product-documents/{documentPublicId}/complete-upload', CompleteProductDocumentUploadAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));
        $app->post('/api/v1/admin/product-documents/{documentPublicId}/replace-upload', ReplaceProductDocumentUploadAction::class)
            ->add(($require)('PRODUCT.DOCUMENTS.MANAGE'));
        $app->delete('/api/v1/admin/product-documents/{documentPublicId}/file', DeleteProductDocumentFileAction::class)
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

        $app->post('/api/v1/documents/pdf', GenerateDocumentPdfAction::class);

    }
}
