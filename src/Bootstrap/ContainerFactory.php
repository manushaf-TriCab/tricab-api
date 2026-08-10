<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use DI\Container;
use DI\ContainerBuilder;
use InvalidArgumentException;
use PDO;
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
use TriCab\Api\Http\Action\Admin\GetProductSpecificationsAction;
use TriCab\Api\Http\Action\Admin\AssignRolePermissionAction;
use TriCab\Api\Http\Action\Admin\AssignUserRoleAction;
use TriCab\Api\Http\Action\Admin\AssignVariantSpecificationsAction;
use TriCab\Api\Http\Action\Admin\ChangeCustomerContactStatusAction;
use TriCab\Api\Http\Action\Admin\ChangeCustomerPortalAccessAction;
use TriCab\Api\Http\Action\Admin\ChangeCustomerStatusAction;
use TriCab\Api\Http\Action\Admin\ChangeProductLifecycleAction;
use TriCab\Api\Http\Action\Admin\ChangeProductSlugAction;
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
use TriCab\Api\Site\Persistence\AzureSqlSiteConfigurationRepository;
use TriCab\Api\Site\Persistence\InMemory\InMemorySiteConfigurationRepository;
use TriCab\Core\Site\Application\Service\CustomerViewThemeService;
use TriCab\Core\Site\Contract\SiteConfigurationRepository;
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

use TriCab\Api\DocumentGeneration\Rendering\DompdfPdfRenderer;

use TriCab\Api\Http\Action\DatabaseHealthAction;
use TriCab\Api\Http\Action\GenerateDocumentPdfAction;
use TriCab\Api\Http\Action\GetAuthenticatedUserAction;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Action\LogoutGuestAction;
use TriCab\Api\Http\Action\RequestGuestOtpAction;
use TriCab\Api\Http\Action\VerifyGuestOtpAction;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\GuestAuthRateLimiter;
use TriCab\Api\Http\Auth\NoOpGuestAuthRateLimiter;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Middleware\BearerTokenAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\CorsMiddleware;
use TriCab\Api\Http\Middleware\DevelopmentAuthenticationMiddleware;
use TriCab\Api\Http\Middleware\JsonExceptionMiddleware;
use TriCab\Api\Customer\Persistence\AzureSqlCustomerContactRepository;
use TriCab\Api\Customer\Persistence\AzureSqlCustomerContactUserRepository;
use TriCab\Api\Customer\Persistence\AzureSqlCustomerRepository;
use TriCab\Api\Customer\Persistence\InMemory\InMemoryCustomerStore;
use TriCab\Api\Customer\Persistence\UserManagementCustomerPortalUserProvisioner;
use TriCab\Api\Product\Http\ProductSubResourceResolver;
use TriCab\Api\Product\Persistence\AzureSqlLegacyProductMappingRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductAdminQueryRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductAuditRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductClassificationRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductCodeRuleRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductDocumentRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductIntegrationRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductLookupRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductMarketRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductMediaRepository;
use TriCab\Api\Product\Persistence\AzureSqlProduct360Repository;
use TriCab\Api\Product\Persistence\AzureSqlProductArModelRepository;
use TriCab\Api\Product\Storage\AzureProductBlobStore;
use TriCab\Api\Product\Storage\DisabledProductBlobStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Api\Http\Action\Dev\DevProductFileDeliveryAction;
use TriCab\Api\Product\Persistence\AzureSqlProductPricingRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductCustomerViewRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductPublicQueryRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductRelationshipRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductSearchRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductSeoRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductSpecificationRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductVariantRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryLegacyProductMappingRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAdminQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAuditRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductClassificationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCodeRuleRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductDocumentRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductIntegrationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductLookupRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductMarketRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductMediaRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProduct360Repository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductArModelRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPricingRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCustomerViewRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPublicQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductRelationshipRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductSearchRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductSeoRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductSpecificationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductVariantRepository;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\CompositeBearerTokenValidator;
use TriCab\Api\Identity\Authentication\EntraJwksClient;
use TriCab\Api\Identity\Authentication\EntraJwksProvider;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;
use TriCab\Api\Identity\Authentication\EntraTokenValidator;
use TriCab\Api\Identity\Authentication\GuestJwtIssuer;
use TriCab\Api\Identity\Authentication\GuestJwtValidator;
use TriCab\Api\Identity\Authentication\MicrosoftEntraJwtValidator;
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Identity\Email\MicrosoftGraphGuestOtpEmailSender;
use TriCab\Api\Identity\Email\PostmarkGuestOtpEmailSender;
use TriCab\Api\Identity\Persistence\AzureSqlEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\AzureSqlGuestLoginEligibilityLookup;
use TriCab\Api\Identity\Persistence\AzureSqlGuestSessionRepository;
use TriCab\Api\Identity\Persistence\AzureSqlPermissionManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlRoleManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementAuditRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserProvisioningRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\InMemoryGuestSessionRepository;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Database\AzureSqlConnectionFactory;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Api\Shared\Persistence\NoOpApplicationTransaction;
use TriCab\Api\Shared\Persistence\SqlApplicationTransaction;
use TriCab\Core\Customer\Application\Service\ChangeCustomerContactStatusService;
use TriCab\Core\Customer\Application\Service\ChangeCustomerStatusService;
use TriCab\Core\Customer\Application\Service\CreateCustomerContactService;
use TriCab\Core\Customer\Application\Service\CreateCustomerPortalUserService;
use TriCab\Core\Customer\Application\Service\CreateCustomerService;
use TriCab\Core\Customer\Application\Service\DisableCustomerPortalAccessService;
use TriCab\Core\Customer\Application\Service\EnableCustomerPortalAccessService;
use TriCab\Core\Customer\Application\Service\GetCustomerContactService;
use TriCab\Core\Customer\Application\Service\GetCustomerService;
use TriCab\Core\Customer\Application\Service\LinkCustomerContactToUserService;
use TriCab\Core\Customer\Application\Service\ListCustomerContactsService;
use TriCab\Core\Customer\Application\Service\ListCustomersService;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Customer\Application\Service\UnlinkCustomerContactFromUserService;
use TriCab\Core\Customer\Application\Service\UpdateCustomerContactService;
use TriCab\Core\Customer\Application\Service\UpdateCustomerService;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerPortalUserProvisioner;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Application\Service\CompositeGuestLoginEligibilityLookup;
use TriCab\Core\Identity\Application\Service\CreateGuestSessionService;
use TriCab\Core\Identity\Application\Service\GuestLoginEligibilityService;
use TriCab\Core\Identity\Application\Service\GuestUserProvisioningService;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;
use TriCab\Core\Identity\Application\Service\RequestAndSendGuestOtpService;
use TriCab\Core\Identity\Application\Service\RequestGuestOtpService;
use TriCab\Core\Identity\Application\Service\RevokeGuestSessionService;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignPermissionToRoleService;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignRoleToUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\ChangeRoleStatusService;
use TriCab\Core\Identity\Application\Service\UserManagement\ChangeUserStatusService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreatePermissionService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateRoleService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\GetRoleDetailsService;
use TriCab\Core\Identity\Application\Service\UserManagement\GetUserDetailsService;
use TriCab\Core\Identity\Application\Service\UserManagement\GetUserManagementAuditHistoryService;
use TriCab\Core\Identity\Application\Service\UserManagement\ListPermissionsService;
use TriCab\Core\Identity\Application\Service\UserManagement\ListRolesService;
use TriCab\Core\Identity\Application\Service\UserManagement\ListUsersService;
use TriCab\Core\Identity\Application\Service\UserManagement\RemovePermissionFromRoleService;
use TriCab\Core\Identity\Application\Service\UserManagement\RemoveRoleFromUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\UpdatePermissionService;
use TriCab\Core\Identity\Application\Service\UserManagement\UpdateRoleService;
use TriCab\Core\Identity\Application\Service\UserManagement\UpdateUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\UserManagementAuditRecorder;
use TriCab\Core\Identity\Application\Service\VerifyGuestOtpService;
use TriCab\Core\Identity\Contract\EmailOtpChallengeRepository;
use TriCab\Core\Identity\Contract\GuestAccessTokenIssuer;
use TriCab\Core\Identity\Contract\GuestAccessTokenValidator;
use TriCab\Core\Identity\Contract\GuestLoginEligibilityLookup;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Contract\OtpGenerator;
use TriCab\Core\Identity\Contract\OtpHasher;
use TriCab\Core\Identity\Contract\PermissionManagementRepository;
use TriCab\Core\Identity\Contract\RoleManagementRepository;
use TriCab\Core\Identity\Contract\SessionTokenHasher;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;

use TriCab\Core\Identity\Domain\Service\PasswordOtpHasher;
use TriCab\Core\Identity\Domain\Service\SecureOtpGenerator;
use TriCab\Core\Identity\Domain\Service\Sha256SessionTokenHasher;
use TriCab\Core\Product\Application\Service\AddProductDocumentService;
use TriCab\Core\Product\Application\Service\AddProductMediaService;
use TriCab\Api\Http\Action\Admin\ProductSeriesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductCategoriesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductFamiliesAdminAction;
use TriCab\Core\Product\Application\Service\ManageProductClassificationService;
use TriCab\Core\Product\Application\Service\SpecificationDefinitionService;
use TriCab\Core\Product\Application\Service\SpecificationGroupService;
use TriCab\Core\Product\Application\Service\SpecificationOptionService;
use TriCab\Core\Product\Application\Service\SpecificationUnitService;
use TriCab\Api\Http\Action\Admin\SpecificationDefinitionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationGroupsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationOptionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationUnitsAdminAction;
use TriCab\Api\Http\Action\Admin\Migration\ProductMigrationAdminAction;
use TriCab\Api\Product\Persistence\AzureSqlMigrationBatchRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryMigrationBatchRepository;
use TriCab\Core\Product\Migration\Application\Service\GetCatalogueReadinessService;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationService;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Contract\MigrationLookupIndex;
use TriCab\Core\Product\Migration\Identity\EmptyMigrationLookupIndex;
use TriCab\Core\Product\Migration\Identity\ProductIdentityMatcher;
use TriCab\Core\Product\Migration\Identity\ProductVariantClassifier;
use TriCab\Api\Product\Migration\MysqlLegacyTcRepository;
use TriCab\Api\Product\Migration\MysqlLegacyWordPressRepository;
use TriCab\Api\Product\Migration\MysqlLiveInventoryProbe;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Application\Service\InspectLegacyCatalogueService;
use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\AzureSqlMigrationSchemaProbe;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LiveInventoryProbe;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Live\MemoryMigrationSchemaProbe;
use TriCab\Core\Product\Migration\Live\MigrationSchemaProbe;
use TriCab\Core\Product\Migration\Live\NullLiveInventoryProbe;
use TriCab\Core\Product\Migration\Live\UnavailableLegacyTcRepository;
use TriCab\Core\Product\Migration\Live\UnavailableLegacyWordPressRepository;
use TriCab\Core\Product\Migration\Source\LiveCombinedLegacyProductSource;
use TriCab\Core\Product\Migration\Mapping\OptionAliasResolver;
use TriCab\Core\Product\Migration\Source\FixtureLegacyProductSource;
use TriCab\Core\Product\Migration\Source\LegacyProductSourceInterface;
use TriCab\Core\Product\Migration\Transform\SpecificationValueTransformer;

use TriCab\Core\Product\Application\Service\ArchiveProductService;
use TriCab\Core\Product\Application\Service\ArchiveProductVariantService;
use TriCab\Core\Product\Application\Service\AssignProductApplicationsService;
use TriCab\Core\Product\Application\Service\AssignProductCodeService;
use TriCab\Core\Product\Application\Service\AssignProductIndustriesService;
use TriCab\Core\Product\Application\Service\AssignProductMarketsService;
use TriCab\Core\Product\Application\Service\AssignProductSpecificationsService;
use TriCab\Core\Product\Application\Service\AssignVariantSpecificationsService;
use TriCab\Core\Product\Application\Service\ChangeProductLifecycleService;
use TriCab\Core\Product\Application\Service\ChangeProductSlugService;
use TriCab\Core\Product\Application\Service\CreateProductService;
use TriCab\Core\Product\Application\Service\CreateProductVariantService;
use TriCab\Core\Product\Application\Service\CrossAggregateProductPublishReadinessEvaluator;
use TriCab\Core\Product\Application\Service\GetAdminProductCustomerViewService;
use TriCab\Core\Product\Application\Service\GetAdminProductService;
use TriCab\Core\Product\Application\Service\GetProductAuditHistoryService;
use TriCab\Core\Product\Application\Service\GetProductLookupsService;
use TriCab\Core\Product\Application\Service\GetProductPricingService;
use TriCab\Core\Product\Application\Service\GetProductPublishReadinessService;
use TriCab\Core\Product\Application\Service\GetProductVariantsService;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Application\Service\ListAdminProductsService;
use TriCab\Core\Product\Application\Service\PreviewProductCodeService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\PublishProductService;
use TriCab\Core\Product\Application\Service\ReorderProductMediaService;
use TriCab\Core\Product\Application\Service\ReplaceProductRelationshipsService;
use TriCab\Core\Product\Application\Service\RestoreArchivedProductService;
use TriCab\Core\Product\Application\Service\RestoreDeletedProductService;
use TriCab\Core\Product\Application\Service\RestoreProductVariantService;
use TriCab\Core\Product\Application\Service\SearchProductsService;
use TriCab\Core\Product\Application\Service\SetPrimaryProductImageService;
use TriCab\Core\Product\Application\Service\UpdateProductMediaRoleService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductDocumentService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductMediaService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductVariantService;
use TriCab\Core\Product\Application\Service\UnpublishProductService;
use TriCab\Core\Product\Application\Service\UpdateProductSeoService;
use TriCab\Core\Product\Application\Service\UpdateProductService;
use TriCab\Core\Product\Application\Service\UpdateProductVariantService;
use TriCab\Core\Product\Application\Service\UpsertProductPricingService;
use TriCab\Core\Product\Application\Service\WithdrawProductDocumentService;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductAuditRepository;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Contract\ProductCodeRuleRepository;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Contract\ProductIntegrationRepository;
use TriCab\Core\Product\Contract\ProductLookupRepository;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Contract\ProductImageMetadataExtractor;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Application\Service\ConfigurableProductFileScanPolicy;
use TriCab\Core\Product\Application\Service\GdProductImageMetadataExtractor;
use TriCab\Core\Product\Application\Service\NullProductImageMetadataExtractor;
use TriCab\Core\Product\Application\Service\InitiateProductMediaUploadService;
use TriCab\Api\Http\Action\Admin\CompleteArModelUploadAction;
use TriCab\Api\Http\Action\Admin\InitiateArModelUploadAction;
use TriCab\Api\Http\Action\Admin\ActivateProduct360SetAction;
use TriCab\Api\Http\Action\Admin\Complete360FrameUploadAction;
use TriCab\Api\Http\Action\Admin\Initiate360FrameUploadAction;
use TriCab\Api\Http\Action\Admin\CreateProduct360SetAction;
use TriCab\Core\Product\Application\Service\CompleteArModelUploadService;
use TriCab\Core\Product\Application\Service\InitiateArModelUploadService;
use TriCab\Core\Product\Application\Service\ActivateProduct360SetService;
use TriCab\Core\Product\Application\Service\Complete360FrameUploadService;
use TriCab\Core\Product\Application\Service\Initiate360FrameUploadService;
use TriCab\Core\Product\Application\Service\CreateProduct360SetService;
use TriCab\Core\Product\Application\Service\CompleteProductMediaUploadService;
use TriCab\Core\Product\Application\Service\ReplaceProductMediaUploadService;
use TriCab\Core\Product\Application\Service\DeleteProductMediaFileService;
use TriCab\Core\Product\Application\Service\InitiateProductDocumentUploadService;
use TriCab\Core\Product\Application\Service\CompleteProductDocumentUploadService;
use TriCab\Core\Product\Application\Service\ReplaceProductDocumentUploadService;
use TriCab\Core\Product\Application\Service\DeleteProductDocumentFileService;
use TriCab\Core\Product\Application\Service\GetRestrictedDocumentDownloadService;
use TriCab\Api\Http\Action\Admin\InitiateProductMediaUploadAction;
use TriCab\Api\Http\Action\Admin\CompleteProductMediaUploadAction;
use TriCab\Api\Http\Action\Admin\ReplaceProductMediaUploadAction;
use TriCab\Api\Http\Action\Admin\DeleteProductMediaFileAction;
use TriCab\Api\Http\Action\Admin\InitiateProductDocumentUploadAction;
use TriCab\Api\Http\Action\Admin\CompleteProductDocumentUploadAction;
use TriCab\Api\Http\Action\Admin\ReplaceProductDocumentUploadAction;
use TriCab\Api\Http\Action\Admin\DeleteProductDocumentFileAction;
use TriCab\Api\Http\Action\PublicProduct\DownloadProductDocumentAction;
use TriCab\Core\Product\Contract\ProductCustomerViewQueryRepository;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Contract\ProductPublicQueryRepository;
use TriCab\Core\Product\Contract\ProductPublishReadinessEvaluator;
use TriCab\Core\Product\Contract\ProductRelationshipRepository;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Contract\ProductSearchRepository;
use TriCab\Core\Product\Contract\ProductSeoRepository;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

use TriCab\Core\DocumentGeneration\Application\Handler\GenerateOcrPdfHandler;
use TriCab\Core\DocumentGeneration\Contract\PdfRenderer;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStreamParser;


final class ContainerFactory
{
    public static function create(Settings $settings): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $documentResources = dirname(__DIR__, 2) . '/resources/document-generation';

        $definitions = [
            Settings::class => $settings,
            JsonResponder::class => \DI\create(JsonResponder::class),
            EntraJwksProvider::class => \DI\autowire(EntraJwksClient::class),
            EntraJwksClient::class => \DI\autowire(EntraJwksClient::class),
            EntraJwtValidationDebugLogger::class => \DI\autowire(EntraJwtValidationDebugLogger::class),
            EntraTokenValidator::class => \DI\autowire(MicrosoftEntraJwtValidator::class),
            ResolveAuthenticatedUserHandler::class => \DI\autowire(ResolveAuthenticatedUserHandler::class),
            LegacyStreamParser::class => \DI\autowire(LegacyStreamParser::class),
            DompdfPdfRenderer::class => \DI\create(DompdfPdfRenderer::class)
                ->constructor($documentResources . '/templates', $documentResources . '/assets'),
            PdfRenderer::class => \DI\get(DompdfPdfRenderer::class),
            GenerateOcrPdfHandler::class => \DI\autowire(GenerateOcrPdfHandler::class),
            HealthAction::class => \DI\autowire(HealthAction::class),
            DatabaseHealthAction::class => \DI\autowire(DatabaseHealthAction::class),
            GetAuthenticatedUserAction::class => \DI\autowire(GetAuthenticatedUserAction::class),
            GenerateDocumentPdfAction::class => \DI\autowire(GenerateDocumentPdfAction::class),
            DevelopmentAuthenticationMiddleware::class => \DI\autowire(DevelopmentAuthenticationMiddleware::class),
            BearerTokenAuthenticationMiddleware::class => \DI\autowire(BearerTokenAuthenticationMiddleware::class),
            CorsMiddleware::class => \DI\autowire(CorsMiddleware::class),
            JsonExceptionMiddleware::class => \DI\autowire(JsonExceptionMiddleware::class),
            AzureCliAccessTokenProvider::class => \DI\create(AzureCliAccessTokenProvider::class)
                ->constructor($settings->azureCliBinary),
            AzureSqlPdoFactory::class => \DI\autowire(AzureSqlPdoFactory::class),
            AzureSqlConnectionFactory::class => \DI\autowire(AzureSqlConnectionFactory::class),
            JsonHttpClient::class => \DI\autowire(StreamJsonHttpClient::class),
            SessionTokenHasher::class => \DI\autowire(Sha256SessionTokenHasher::class),
            Clock::class => \DI\autowire(SystemClock::class),
            GuestAuthEventLogger::class => \DI\create(GuestAuthEventLogger::class),
            GuestAuthErrorMapper::class => \DI\autowire(GuestAuthErrorMapper::class),
            GuestAuthRateLimiter::class => \DI\autowire(NoOpGuestAuthRateLimiter::class),
            GuestOtpEmailSender::class => static function (Container $container) use ($settings): GuestOtpEmailSender {
                return self::createGuestOtpEmailSender($settings, $container);
            },
            CompositeBearerTokenValidator::class => static function (Container $container) use ($settings): CompositeBearerTokenValidator {
                $guestFactory = null;
                if ($settings->isGuestJwtConfigured()) {
                    $guestFactory = static function () use ($container): GuestAccessTokenValidator {
                        return self::get($container, GuestAccessTokenValidator::class);
                    };
                }

                return new CompositeBearerTokenValidator(
                    entraTokenValidator: self::get($container, EntraTokenValidator::class),
                    guestTokenValidatorFactory: $guestFactory,
                    settings: $settings,
                );
            },
            UserManagementAuditRecorder::class => \DI\autowire(UserManagementAuditRecorder::class),
            ListUsersService::class => \DI\autowire(ListUsersService::class),
            GetUserDetailsService::class => \DI\autowire(GetUserDetailsService::class),
            CreateUserService::class => \DI\autowire(CreateUserService::class),
            UpdateUserService::class => \DI\autowire(UpdateUserService::class),
            ChangeUserStatusService::class => \DI\autowire(ChangeUserStatusService::class),
            AssignRoleToUserService::class => \DI\autowire(AssignRoleToUserService::class),
            RemoveRoleFromUserService::class => \DI\autowire(RemoveRoleFromUserService::class),
            ListRolesService::class => \DI\autowire(ListRolesService::class),
            GetRoleDetailsService::class => \DI\autowire(GetRoleDetailsService::class),
            CreateRoleService::class => \DI\autowire(CreateRoleService::class),
            UpdateRoleService::class => \DI\autowire(UpdateRoleService::class),
            ChangeRoleStatusService::class => \DI\autowire(ChangeRoleStatusService::class),
            AssignPermissionToRoleService::class => \DI\autowire(AssignPermissionToRoleService::class),
            RemovePermissionFromRoleService::class => \DI\autowire(RemovePermissionFromRoleService::class),
            ListPermissionsService::class => \DI\autowire(ListPermissionsService::class),
            CreatePermissionService::class => \DI\autowire(CreatePermissionService::class),
            UpdatePermissionService::class => \DI\autowire(UpdatePermissionService::class),
            GetUserManagementAuditHistoryService::class => \DI\autowire(GetUserManagementAuditHistoryService::class),
            UserManagementErrorMapper::class => \DI\autowire(UserManagementErrorMapper::class),
            ListUsersAction::class => \DI\autowire(ListUsersAction::class),
            GetUserAction::class => \DI\autowire(GetUserAction::class),
            CreateUserAction::class => \DI\autowire(CreateUserAction::class),
            UpdateUserAction::class => \DI\autowire(UpdateUserAction::class),
            ChangeUserStatusAction::class => \DI\autowire(ChangeUserStatusAction::class),
            AssignUserRoleAction::class => \DI\autowire(AssignUserRoleAction::class),
            RemoveUserRoleAction::class => \DI\autowire(RemoveUserRoleAction::class),
            ListRolesAction::class => \DI\autowire(ListRolesAction::class),
            GetRoleAction::class => \DI\autowire(GetRoleAction::class),
            CreateRoleAction::class => \DI\autowire(CreateRoleAction::class),
            UpdateRoleAction::class => \DI\autowire(UpdateRoleAction::class),
            ChangeRoleStatusAction::class => \DI\autowire(ChangeRoleStatusAction::class),
            AssignRolePermissionAction::class => \DI\autowire(AssignRolePermissionAction::class),
            RemoveRolePermissionAction::class => \DI\autowire(RemoveRolePermissionAction::class),
            ListPermissionsAction::class => \DI\autowire(ListPermissionsAction::class),
            CreatePermissionAction::class => \DI\autowire(CreatePermissionAction::class),
            UpdatePermissionAction::class => \DI\autowire(UpdatePermissionAction::class),
            ListAuditAction::class => \DI\autowire(ListAuditAction::class),
            ListCustomersService::class => \DI\autowire(ListCustomersService::class),
            GetCustomerService::class => \DI\autowire(GetCustomerService::class),
            CreateCustomerService::class => \DI\autowire(CreateCustomerService::class),
            UpdateCustomerService::class => \DI\autowire(UpdateCustomerService::class),
            ChangeCustomerStatusService::class => \DI\autowire(ChangeCustomerStatusService::class),
            ListCustomerContactsService::class => \DI\autowire(ListCustomerContactsService::class),
            GetCustomerContactService::class => \DI\autowire(GetCustomerContactService::class),
            CreateCustomerContactService::class => \DI\autowire(CreateCustomerContactService::class),
            UpdateCustomerContactService::class => \DI\autowire(UpdateCustomerContactService::class),
            ChangeCustomerContactStatusService::class => \DI\autowire(ChangeCustomerContactStatusService::class),
            LinkCustomerContactToUserService::class => \DI\autowire(LinkCustomerContactToUserService::class),
            UnlinkCustomerContactFromUserService::class => \DI\autowire(UnlinkCustomerContactFromUserService::class),
            CreateCustomerPortalUserService::class => \DI\autowire(CreateCustomerPortalUserService::class),
            ResolveAuthenticatedCustomerContextService::class => \DI\autowire(ResolveAuthenticatedCustomerContextService::class),
            EnableCustomerPortalAccessService::class => \DI\autowire(EnableCustomerPortalAccessService::class),
            DisableCustomerPortalAccessService::class => \DI\autowire(DisableCustomerPortalAccessService::class),
            CustomerManagementErrorMapper::class => \DI\autowire(CustomerManagementErrorMapper::class),
            ListCustomersAction::class => \DI\autowire(ListCustomersAction::class),
            GetCustomerAction::class => \DI\autowire(GetCustomerAction::class),
            CreateCustomerAction::class => \DI\autowire(CreateCustomerAction::class),
            UpdateCustomerAction::class => \DI\autowire(UpdateCustomerAction::class),
            ChangeCustomerStatusAction::class => \DI\autowire(ChangeCustomerStatusAction::class),
            ListCustomerContactsAction::class => \DI\autowire(ListCustomerContactsAction::class),
            GetCustomerContactAction::class => \DI\autowire(GetCustomerContactAction::class),
            CreateCustomerContactAction::class => \DI\autowire(CreateCustomerContactAction::class),
            UpdateCustomerContactAction::class => \DI\autowire(UpdateCustomerContactAction::class),
            ChangeCustomerContactStatusAction::class => \DI\autowire(ChangeCustomerContactStatusAction::class),
            CreateCustomerPortalUserAction::class => \DI\autowire(CreateCustomerPortalUserAction::class),
            LinkCustomerContactUserAction::class => \DI\autowire(LinkCustomerContactUserAction::class),
            UnlinkCustomerContactUserAction::class => \DI\autowire(UnlinkCustomerContactUserAction::class),
            ChangeCustomerPortalAccessAction::class => \DI\autowire(ChangeCustomerPortalAccessAction::class),
            ListCustomerPortalUsersAction::class => \DI\autowire(ListCustomerPortalUsersAction::class),
            ProductManagementErrorMapper::class => \DI\autowire(ProductManagementErrorMapper::class),
            ListProductsAction::class => \DI\autowire(ListProductsAction::class),
            GetProductAction::class => \DI\autowire(GetProductAction::class),
            GetProductCustomerViewAction::class => \DI\autowire(GetProductCustomerViewAction::class),
            GetCustomerViewThemeAction::class => \DI\autowire(GetCustomerViewThemeAction::class),
            UpdateCustomerViewThemeAction::class => \DI\autowire(UpdateCustomerViewThemeAction::class),
            CustomerViewThemeService::class => \DI\autowire(CustomerViewThemeService::class),
            CreateProductAction::class => \DI\autowire(CreateProductAction::class),
            UpdateProductAction::class => \DI\autowire(UpdateProductAction::class),
            PublishProductAction::class => \DI\autowire(PublishProductAction::class),
            UnpublishProductAction::class => \DI\autowire(UnpublishProductAction::class),
            ArchiveProductAction::class => \DI\autowire(ArchiveProductAction::class),
            RestoreArchivedProductAction::class => \DI\autowire(RestoreArchivedProductAction::class),
            SoftDeleteProductAction::class => \DI\autowire(SoftDeleteProductAction::class),
            RestoreDeletedProductAction::class => \DI\autowire(RestoreDeletedProductAction::class),
            ChangeProductLifecycleAction::class => \DI\autowire(ChangeProductLifecycleAction::class),
            GetProductPublicationReadinessAction::class => \DI\autowire(GetProductPublicationReadinessAction::class),
            GetProductLookupsAction::class => \DI\autowire(GetProductLookupsAction::class),
            GetProductAuditAction::class => \DI\autowire(GetProductAuditAction::class),
            ListProductVariantsAction::class => \DI\autowire(ListProductVariantsAction::class),
            CreateProductVariantAction::class => \DI\autowire(CreateProductVariantAction::class),
            UpdateProductVariantAction::class => \DI\autowire(UpdateProductVariantAction::class),
            ArchiveProductVariantAction::class => \DI\autowire(ArchiveProductVariantAction::class),
            SoftDeleteProductVariantAction::class => \DI\autowire(SoftDeleteProductVariantAction::class),
            RestoreProductVariantAction::class => \DI\autowire(RestoreProductVariantAction::class),
            PreviewProductCodeAction::class => \DI\autowire(PreviewProductCodeAction::class),
            AssignProductCodeAction::class => \DI\autowire(AssignProductCodeAction::class),
            AssignProductSpecificationsAction::class => \DI\autowire(AssignProductSpecificationsAction::class),
            GetProductSpecificationsAction::class => \DI\autowire(GetProductSpecificationsAction::class),
            AssignVariantSpecificationsAction::class => \DI\autowire(AssignVariantSpecificationsAction::class),
            ListProductMediaAction::class => \DI\autowire(ListProductMediaAction::class),
            AddProductMediaAction::class => \DI\autowire(AddProductMediaAction::class),
            ReorderProductMediaAction::class => \DI\autowire(ReorderProductMediaAction::class),
            SetPrimaryProductImageAction::class => \DI\autowire(SetPrimaryProductImageAction::class),
            UpdateProductMediaRoleAction::class => \DI\autowire(UpdateProductMediaRoleAction::class),
            SoftDeleteProductMediaAction::class => \DI\autowire(SoftDeleteProductMediaAction::class),
            ListProductDocumentsAction::class => \DI\autowire(ListProductDocumentsAction::class),
            AddProductDocumentAction::class => \DI\autowire(AddProductDocumentAction::class),
            WithdrawProductDocumentAction::class => \DI\autowire(WithdrawProductDocumentAction::class),
            SoftDeleteProductDocumentAction::class => \DI\autowire(SoftDeleteProductDocumentAction::class),
            AssignProductMarketsAction::class => \DI\autowire(AssignProductMarketsAction::class),
            AssignProductIndustriesAction::class => \DI\autowire(AssignProductIndustriesAction::class),
            AssignProductApplicationsAction::class => \DI\autowire(AssignProductApplicationsAction::class),
            ReplaceProductRelationshipsAction::class => \DI\autowire(ReplaceProductRelationshipsAction::class),
            GetProductSeoAction::class => \DI\autowire(GetProductSeoAction::class),
            UpdateProductSeoAction::class => \DI\autowire(UpdateProductSeoAction::class),
            ChangeProductSlugAction::class => \DI\autowire(ChangeProductSlugAction::class),
            GetProductPricingAction::class => \DI\autowire(GetProductPricingAction::class),
            UpsertProductPricingAction::class => \DI\autowire(UpsertProductPricingAction::class),
            GetPublicProductBySlugAction::class => \DI\autowire(GetPublicProductBySlugAction::class),
            GetPublicProductVariantsAction::class => \DI\autowire(GetPublicProductVariantsAction::class),
            GetPublicProductMediaAction::class => \DI\autowire(GetPublicProductMediaAction::class),
            GetPublicProductDocumentsAction::class => \DI\autowire(GetPublicProductDocumentsAction::class),
            GetPublicProductRelationshipsAction::class => \DI\autowire(GetPublicProductRelationshipsAction::class),
            GetPublicProductSpecificationsAction::class => \DI\autowire(GetPublicProductSpecificationsAction::class),
            SearchPublicProductsAction::class => \DI\autowire(SearchPublicProductsAction::class),
            ProductPublishReadinessEvaluator::class => \DI\autowire(CrossAggregateProductPublishReadinessEvaluator::class),
            ProductAuditRecorder::class => \DI\autowire(ProductAuditRecorder::class),
            CreateProductService::class => \DI\autowire(CreateProductService::class),
            UpdateProductService::class => \DI\autowire(UpdateProductService::class),
            PublishProductService::class => \DI\autowire(PublishProductService::class),
            UnpublishProductService::class => \DI\autowire(UnpublishProductService::class),
            ArchiveProductService::class => \DI\autowire(ArchiveProductService::class),
            RestoreArchivedProductService::class => \DI\autowire(RestoreArchivedProductService::class),
            SoftDeleteProductService::class => \DI\autowire(SoftDeleteProductService::class),
            RestoreDeletedProductService::class => \DI\autowire(RestoreDeletedProductService::class),
            ChangeProductLifecycleService::class => \DI\autowire(ChangeProductLifecycleService::class),
            CreateProductVariantService::class => \DI\autowire(CreateProductVariantService::class),
            UpdateProductVariantService::class => \DI\autowire(UpdateProductVariantService::class),
            ArchiveProductVariantService::class => \DI\autowire(ArchiveProductVariantService::class),
            SoftDeleteProductVariantService::class => \DI\autowire(SoftDeleteProductVariantService::class),
            RestoreProductVariantService::class => \DI\autowire(RestoreProductVariantService::class),
            PreviewProductCodeService::class => \DI\autowire(PreviewProductCodeService::class),
            AssignProductCodeService::class => \DI\autowire(AssignProductCodeService::class),
            AssignProductSpecificationsService::class => \DI\autowire(AssignProductSpecificationsService::class),
            AssignVariantSpecificationsService::class => \DI\autowire(AssignVariantSpecificationsService::class),
            AddProductMediaService::class => \DI\autowire(AddProductMediaService::class),
            ReorderProductMediaService::class => \DI\autowire(ReorderProductMediaService::class),
            SetPrimaryProductImageService::class => \DI\autowire(SetPrimaryProductImageService::class),
            UpdateProductMediaRoleService::class => \DI\autowire(UpdateProductMediaRoleService::class),
            SoftDeleteProductMediaService::class => \DI\autowire(SoftDeleteProductMediaService::class),
            ProductFilePathBuilder::class => \DI\autowire(ProductFilePathBuilder::class),
            ProductFileValidationPolicy::class => \DI\autowire(ProductFileValidationPolicy::class),
            ProductFileScanPolicy::class => static function () use ($settings): ProductFileScanPolicy {
                return new ConfigurableProductFileScanPolicy($settings->productFileScanAutoCleans());
            },
            ProductImageMetadataExtractor::class => static function (): ProductImageMetadataExtractor {
                return function_exists('getimagesizefromstring')
                    ? new GdProductImageMetadataExtractor()
                    : new NullProductImageMetadataExtractor();
            },
            ProductBlobStore::class => static function () use ($settings): ProductBlobStore {
                if ($settings->usesMemoryProductBlobStore()) {
                    return new InMemoryProductBlobStore(
                        cdnBaseUrl: $settings->blobCdnBaseUrl,
                        defaultContainer: $settings->blobContainerProducts,
                    );
                }
                if ($settings->blobAuthMode === Settings::BLOB_AUTH_MODE_CONNECTION_STRING) {
                    return new AzureProductBlobStore($settings);
                }

                return new DisabledProductBlobStore();
            },
            ProductDevMediaUrlResolver::class => \DI\autowire(ProductDevMediaUrlResolver::class),
            DevProductFileDeliveryAction::class => \DI\autowire(DevProductFileDeliveryAction::class),
            InitiateProductMediaUploadService::class => \DI\autowire(InitiateProductMediaUploadService::class),
            CompleteProductMediaUploadService::class => \DI\autowire(CompleteProductMediaUploadService::class),
            ReplaceProductMediaUploadService::class => \DI\autowire(ReplaceProductMediaUploadService::class),
            DeleteProductMediaFileService::class => \DI\autowire(DeleteProductMediaFileService::class),
            InitiateProductDocumentUploadService::class => \DI\autowire(InitiateProductDocumentUploadService::class),
            CompleteProductDocumentUploadService::class => \DI\autowire(CompleteProductDocumentUploadService::class),
            ReplaceProductDocumentUploadService::class => \DI\autowire(ReplaceProductDocumentUploadService::class),
            DeleteProductDocumentFileService::class => \DI\autowire(DeleteProductDocumentFileService::class),
            GetRestrictedDocumentDownloadService::class => \DI\autowire(GetRestrictedDocumentDownloadService::class),
            InitiateProductMediaUploadAction::class => \DI\autowire(InitiateProductMediaUploadAction::class),
            CompleteProductMediaUploadAction::class => \DI\autowire(CompleteProductMediaUploadAction::class),
            ReplaceProductMediaUploadAction::class => \DI\autowire(ReplaceProductMediaUploadAction::class),
            DeleteProductMediaFileAction::class => \DI\autowire(DeleteProductMediaFileAction::class),
            InitiateProductDocumentUploadAction::class => \DI\autowire(InitiateProductDocumentUploadAction::class),
            CompleteProductDocumentUploadAction::class => \DI\autowire(CompleteProductDocumentUploadAction::class),
            ReplaceProductDocumentUploadAction::class => \DI\autowire(ReplaceProductDocumentUploadAction::class),
            DeleteProductDocumentFileAction::class => \DI\autowire(DeleteProductDocumentFileAction::class),
            DownloadProductDocumentAction::class => \DI\autowire(DownloadProductDocumentAction::class),

            CreateProduct360SetService::class => \DI\autowire(CreateProduct360SetService::class),
            Initiate360FrameUploadService::class => \DI\autowire(Initiate360FrameUploadService::class),
            Complete360FrameUploadService::class => \DI\autowire(Complete360FrameUploadService::class),
            ActivateProduct360SetService::class => \DI\autowire(ActivateProduct360SetService::class),
            InitiateArModelUploadService::class => \DI\autowire(InitiateArModelUploadService::class),
            CompleteArModelUploadService::class => \DI\autowire(CompleteArModelUploadService::class),
            CreateProduct360SetAction::class => \DI\autowire(CreateProduct360SetAction::class),
            Initiate360FrameUploadAction::class => \DI\autowire(Initiate360FrameUploadAction::class),
            Complete360FrameUploadAction::class => \DI\autowire(Complete360FrameUploadAction::class),
            ActivateProduct360SetAction::class => \DI\autowire(ActivateProduct360SetAction::class),
            InitiateArModelUploadAction::class => \DI\autowire(InitiateArModelUploadAction::class),
            CompleteArModelUploadAction::class => \DI\autowire(CompleteArModelUploadAction::class),
            AddProductDocumentService::class => \DI\autowire(AddProductDocumentService::class),
            WithdrawProductDocumentService::class => \DI\autowire(WithdrawProductDocumentService::class),
            SoftDeleteProductDocumentService::class => \DI\autowire(SoftDeleteProductDocumentService::class),
            AssignProductMarketsService::class => \DI\autowire(AssignProductMarketsService::class),
            AssignProductIndustriesService::class => \DI\autowire(AssignProductIndustriesService::class),
            AssignProductApplicationsService::class => \DI\autowire(AssignProductApplicationsService::class),
            ReplaceProductRelationshipsService::class => \DI\autowire(ReplaceProductRelationshipsService::class),
            UpdateProductSeoService::class => \DI\autowire(UpdateProductSeoService::class),
            ChangeProductSlugService::class => \DI\autowire(ChangeProductSlugService::class),
            UpsertProductPricingService::class => \DI\autowire(UpsertProductPricingService::class),
            ListAdminProductsService::class => \DI\autowire(ListAdminProductsService::class),
            GetAdminProductService::class => \DI\autowire(GetAdminProductService::class),
            GetAdminProductCustomerViewService::class => \DI\autowire(GetAdminProductCustomerViewService::class),
            GetPublicProductService::class => \DI\autowire(GetPublicProductService::class),
            SearchProductsService::class => \DI\autowire(SearchProductsService::class),

            ManageProductClassificationService::class => \DI\autowire(ManageProductClassificationService::class),
            SpecificationGroupService::class => \DI\autowire(SpecificationGroupService::class),
            SpecificationDefinitionService::class => \DI\autowire(SpecificationDefinitionService::class),
            SpecificationOptionService::class => \DI\autowire(SpecificationOptionService::class),
            SpecificationUnitService::class => \DI\autowire(SpecificationUnitService::class),
            ProductMigrationService::class => \DI\autowire(ProductMigrationService::class),
            ProductMigrationAdminAction::class => \DI\autowire(ProductMigrationAdminAction::class),
            GetCatalogueReadinessService::class => static function (Container $container) use ($settings): GetCatalogueReadinessService {
                $azure = $settings->usesAzureSqlUserRepository();
                return new GetCatalogueReadinessService(
                    sourceConfig: self::get($container, LiveLegacySourceConfig::class),
                    inventoryProbe: self::get($container, LiveInventoryProbe::class),
                    schemaProbe: self::get($container, MigrationSchemaProbe::class),
                    batches: self::get($container, MigrationBatchRepository::class),
                    azureSqlMode: $azure,
                    blobEnabled: $settings->isProductBlobEnabled(),
                    persistenceMode: $azure ? 'azure-sql' : 'in-memory',
                );
            },
            LiveLegacySourceConfig::class => static function (): LiveLegacySourceConfig {
                /** @var array<string, string|null> $env */
                $env = [];
                foreach ([
                    'LEGACY_WP_DSN', 'LEGACY_WP_HOST', 'LEGACY_WP_PORT', 'LEGACY_WP_DATABASE',
                    'LEGACY_WP_USER', 'LEGACY_WP_PASSWORD', 'LEGACY_WP_TABLE_PREFIX', 'LEGACY_WP_SSL_MODE',
                    'LEGACY_SOURCE_WAIVE_WP',
                    'LEGACY_TC_DSN', 'LEGACY_TC_HOST', 'LEGACY_TC_PORT', 'LEGACY_TC_DATABASE',
                    'LEGACY_TC_USER', 'LEGACY_TC_PASSWORD', 'LEGACY_TC_SSL_MODE',
                    'LEGACY_SOURCE_WAIVE_TC', 'LEGACY_FILES_ROOT', 'LEGACY_SOURCE_WAIVE_FILES',
                    'PHASE12_PILOT_APPROVED',
                ] as $key) {
                    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
                    $env[$key] = ($value === false || $value === null) ? null : (string) $value;
                }
                return LiveLegacySourceConfig::fromEnvironment($env);
            },
            LegacyReadOnlySqlGuard::class => \DI\autowire(LegacyReadOnlySqlGuard::class),
            LegacySqlStatementLogger::class => \DI\autowire(InMemoryLegacySqlStatementLogger::class),
            ReadOnlyMysqlConnectionFactory::class => \DI\autowire(ReadOnlyMysqlConnectionFactory::class),
            LegacyWordPressRepository::class => static function (Container $container): LegacyWordPressRepository {
                $config = self::get($container, LiveLegacySourceConfig::class);
                if (!$config->wordpressConfigured()) {
                    return new UnavailableLegacyWordPressRepository();
                }
                $factory = self::get($container, ReadOnlyMysqlConnectionFactory::class);
                $conn = $factory->create(
                    (string) $config->wordpressDsn,
                    (string) $config->wordpressUser,
                    (string) $config->wordpressPassword,
                    'wordpress',
                    $config->wordpressSslMode,
                );
                return new MysqlLegacyWordPressRepository($conn, $config->wordpressTablePrefix);
            },
            LegacyTcRepository::class => static function (Container $container): LegacyTcRepository {
                $config = self::get($container, LiveLegacySourceConfig::class);
                if (!$config->tcConfigured()) {
                    return new UnavailableLegacyTcRepository();
                }
                $factory = self::get($container, ReadOnlyMysqlConnectionFactory::class);
                $conn = $factory->create(
                    (string) $config->tcDsn,
                    (string) $config->tcUser,
                    (string) $config->tcPassword,
                    'tc',
                    $config->tcSslMode,
                );
                return new MysqlLegacyTcRepository($conn);
            },
            LiveInventoryProbe::class => static function (Container $container): LiveInventoryProbe {
                $config = self::get($container, LiveLegacySourceConfig::class);
                if (!$config->wordpressConfigured() && !$config->tcConfigured()) {
                    return new NullLiveInventoryProbe();
                }
                return new MysqlLiveInventoryProbe(
                    self::get($container, LegacyWordPressRepository::class),
                    self::get($container, LegacyTcRepository::class),
                );
            },
            InspectLegacyCatalogueService::class => \DI\autowire(InspectLegacyCatalogueService::class),
            MigrationSchemaProbe::class => \DI\autowire(MemoryMigrationSchemaProbe::class),
            ProductIdentityMatcher::class => \DI\autowire(ProductIdentityMatcher::class),
            ProductVariantClassifier::class => \DI\autowire(ProductVariantClassifier::class),
            SpecificationValueTransformer::class => \DI\autowire(SpecificationValueTransformer::class),
            OptionAliasResolver::class => \DI\autowire(OptionAliasResolver::class),
            MigrationLookupIndex::class => \DI\autowire(EmptyMigrationLookupIndex::class),
            MigrationBatchRepository::class => \DI\autowire(InMemoryMigrationBatchRepository::class),
            LegacyProductSourceInterface::class => static function (Container $container): LegacyProductSourceInterface {
                $config = self::get($container, LiveLegacySourceConfig::class);
                if ($config->wordpressConfigured() || $config->tcConfigured()) {
                    return new LiveCombinedLegacyProductSource(
                        self::get($container, LegacyWordPressRepository::class),
                        self::get($container, LegacyTcRepository::class),
                    );
                }
                return FixtureLegacyProductSource::pilotSample();
            },

            ProductFamiliesAdminAction::class => \DI\autowire(ProductFamiliesAdminAction::class),
            ProductCategoriesAdminAction::class => \DI\autowire(ProductCategoriesAdminAction::class),
            ProductSeriesAdminAction::class => \DI\autowire(ProductSeriesAdminAction::class),
            SpecificationGroupsAdminAction::class => \DI\autowire(SpecificationGroupsAdminAction::class),
            SpecificationDefinitionsAdminAction::class => \DI\autowire(SpecificationDefinitionsAdminAction::class),
            SpecificationOptionsAdminAction::class => \DI\autowire(SpecificationOptionsAdminAction::class),
            SpecificationUnitsAdminAction::class => \DI\autowire(SpecificationUnitsAdminAction::class),
            GetProductLookupsService::class => \DI\autowire(GetProductLookupsService::class),
            GetProductPublishReadinessService::class => \DI\autowire(GetProductPublishReadinessService::class),
            GetProductAuditHistoryService::class => \DI\autowire(GetProductAuditHistoryService::class),
            GetProductPricingService::class => \DI\autowire(GetProductPricingService::class),
            GetProductVariantsService::class => \DI\autowire(GetProductVariantsService::class),
        ];

        if ($settings->usesAzureSqlUserRepository()) {
            if (!$settings->isGuestJwtConfigured()) {
                throw new InvalidArgumentException(
                    'GUEST_JWT_ISSUER, GUEST_JWT_AUDIENCE, and GUEST_JWT_SECRET (min 32 bytes) are required when USER_REPOSITORY=azure-sql.',
                );
            }

            $definitions[PDO::class] = static function (Container $container): PDO {
                $factory = self::get($container, AzureSqlPdoFactory::class);

                return $factory->create();
            };
            $definitions[SqlConnection::class] = \DI\autowire(PdoSqlConnection::class);
            $definitions[ApplicationTransaction::class] = \DI\autowire(SqlApplicationTransaction::class);
            $definitions[UserRepository::class] = \DI\autowire(AzureSqlUserRepository::class);
            $definitions[EmailOtpChallengeRepository::class] = \DI\autowire(AzureSqlEmailOtpChallengeRepository::class);
            $definitions[GuestSessionRepository::class] = \DI\autowire(AzureSqlGuestSessionRepository::class);
            $definitions[UserProvisioningRepository::class] = \DI\autowire(AzureSqlUserProvisioningRepository::class);
            $definitions[UserManagementRepository::class] = \DI\autowire(AzureSqlUserManagementRepository::class);
            $definitions[RoleManagementRepository::class] = \DI\autowire(AzureSqlRoleManagementRepository::class);
            $definitions[PermissionManagementRepository::class] = \DI\autowire(AzureSqlPermissionManagementRepository::class);
            $definitions[UserManagementAuditRepository::class] = \DI\autowire(AzureSqlUserManagementAuditRepository::class);
            $definitions[AdminMutationTransaction::class] = \DI\autowire(AdminMutationTransaction::class);
            $definitions[CustomerRepository::class] = \DI\autowire(AzureSqlCustomerRepository::class);
            $definitions[CustomerContactRepository::class] = \DI\autowire(AzureSqlCustomerContactRepository::class);
            $definitions[CustomerContactUserRepository::class] = \DI\autowire(AzureSqlCustomerContactUserRepository::class);
            $definitions[CustomerPortalUserProvisioner::class] = \DI\autowire(UserManagementCustomerPortalUserProvisioner::class);
            $definitions[GuestLoginEligibilityLookup::class] = \DI\autowire(AzureSqlGuestLoginEligibilityLookup::class);
            $definitions[ProductRepository::class] = \DI\autowire(AzureSqlProductRepository::class);
            $definitions[ProductVariantRepository::class] = \DI\autowire(AzureSqlProductVariantRepository::class);
            $definitions[ProductAdminQueryRepository::class] = \DI\autowire(AzureSqlProductAdminQueryRepository::class);
            $definitions[ProductPublicQueryRepository::class] = \DI\autowire(AzureSqlProductPublicQueryRepository::class);
            $definitions[ProductCustomerViewQueryRepository::class] = \DI\autowire(AzureSqlProductCustomerViewRepository::class);
            $definitions[ProductSearchRepository::class] = \DI\autowire(AzureSqlProductSearchRepository::class);
            $definitions[ProductLookupRepository::class] = \DI\autowire(AzureSqlProductLookupRepository::class);
            $definitions[ProductClassificationRepository::class] = \DI\autowire(AzureSqlProductClassificationRepository::class);
            $definitions[ProductSpecificationRepository::class] = \DI\autowire(AzureSqlProductSpecificationRepository::class);
            $definitions[ProductMediaRepository::class] = \DI\autowire(AzureSqlProductMediaRepository::class);
            $definitions[ProductDocumentRepository::class] = \DI\autowire(AzureSqlProductDocumentRepository::class);
            $definitions[Product360Repository::class] = \DI\autowire(AzureSqlProduct360Repository::class);
            $definitions[ProductArModelRepository::class] = \DI\autowire(AzureSqlProductArModelRepository::class);
            $definitions[ProductMarketRepository::class] = \DI\autowire(AzureSqlProductMarketRepository::class);
            $definitions[ProductRelationshipRepository::class] = \DI\autowire(AzureSqlProductRelationshipRepository::class);
            $definitions[ProductSeoRepository::class] = \DI\autowire(AzureSqlProductSeoRepository::class);
            $definitions[SiteConfigurationRepository::class] = \DI\autowire(AzureSqlSiteConfigurationRepository::class);
            $definitions[ProductCodeRuleRepository::class] = \DI\autowire(AzureSqlProductCodeRuleRepository::class);
            $definitions[ProductPricingRepository::class] = \DI\autowire(AzureSqlProductPricingRepository::class);
            $definitions[ProductAuditRepository::class] = \DI\autowire(AzureSqlProductAuditRepository::class);
            $definitions[ProductIntegrationRepository::class] = \DI\autowire(AzureSqlProductIntegrationRepository::class);
            $definitions[LegacyProductMappingRepository::class] = \DI\autowire(AzureSqlLegacyProductMappingRepository::class);
            $definitions[MigrationBatchRepository::class] = \DI\autowire(AzureSqlMigrationBatchRepository::class);
            $definitions[MigrationSchemaProbe::class] = static function (Container $container): MigrationSchemaProbe {
                $connection = self::get($container, SqlConnection::class);
                return new AzureSqlMigrationSchemaProbe(static function (string $qualified) use ($connection): bool {
                    $parts = explode('.', $qualified, 2);
                    if (count($parts) !== 2) {
                        return false;
                    }
                    $rows = $connection->fetchAll(
                        <<<'SQL'
SELECT 1 AS [ExistsFlag]
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = :schemaName AND TABLE_NAME = :tableName
SQL,
                        [
                            ':schemaName' => $parts[0],
                            ':tableName' => $parts[1],
                        ],
                    );
                    return $rows !== [];
                });
            };
            $definitions[ProductSubResourceResolver::class] = static function (Container $container): ProductSubResourceResolver {
                return new ProductSubResourceResolver(null, self::get($container, SqlConnection::class));
            };
            self::addGuestAuthDefinitions($definitions, $settings);
        } else {
            $definitions[UserRepository::class] = \DI\autowire(InMemoryApiUserRepository::class);
            $definitions[UserProvisioningRepository::class] = static function (Container $container): UserProvisioningRepository {
                $repository = self::get($container, UserRepository::class);
                if (!$repository instanceof UserProvisioningRepository) {
                    throw new InvalidArgumentException('In-memory UserRepository must also implement UserProvisioningRepository.');
                }

                return $repository;
            };

            if ($settings->isGuestJwtConfigured()) {
                $definitions[EmailOtpChallengeRepository::class] = \DI\autowire(InMemoryEmailOtpChallengeRepository::class);
                $definitions[GuestSessionRepository::class] = \DI\autowire(InMemoryGuestSessionRepository::class);
                self::addGuestAuthDefinitions($definitions, $settings);
            }

            $definitions[ApplicationTransaction::class] = \DI\autowire(NoOpApplicationTransaction::class);
            $definitions[InMemoryUserManagementStore::class] = \DI\autowire(InMemoryUserManagementStore::class);
            $definitions[AdminMutationTransaction::class] = static function (): AdminMutationTransaction {
                return new AdminMutationTransaction(null);
            };
            $definitions[UserManagementRepository::class] = static function (Container $container): UserManagementRepository {
                return self::get($container, InMemoryUserManagementStore::class)->users();
            };
            $definitions[RoleManagementRepository::class] = static function (Container $container): RoleManagementRepository {
                return self::get($container, InMemoryUserManagementStore::class)->roles();
            };
            $definitions[PermissionManagementRepository::class] = static function (Container $container): PermissionManagementRepository {
                return self::get($container, InMemoryUserManagementStore::class)->permissions();
            };
            $definitions[UserManagementAuditRepository::class] = static function (Container $container): UserManagementAuditRepository {
                return self::get($container, InMemoryUserManagementStore::class)->audit();
            };

            $definitions[InMemoryCustomerStore::class] = \DI\autowire(InMemoryCustomerStore::class);
            $definitions[CustomerRepository::class] = static function (Container $container): CustomerRepository {
                return self::get($container, InMemoryCustomerStore::class)->customers();
            };
            $definitions[CustomerContactRepository::class] = static function (Container $container): CustomerContactRepository {
                return self::get($container, InMemoryCustomerStore::class)->contacts();
            };
            $definitions[CustomerContactUserRepository::class] = static function (Container $container): CustomerContactUserRepository {
                return self::get($container, InMemoryCustomerStore::class)->links();
            };
            $definitions[CustomerPortalUserProvisioner::class] = \DI\autowire(UserManagementCustomerPortalUserProvisioner::class);
            $definitions[GuestLoginEligibilityLookup::class] = \DI\autowire(CompositeGuestLoginEligibilityLookup::class);
            $definitions[InMemoryProductStore::class] = \DI\autowire(InMemoryProductStore::class);
            $definitions[ProductRepository::class] = \DI\autowire(InMemoryProductRepository::class);
            $definitions[ProductVariantRepository::class] = \DI\autowire(InMemoryProductVariantRepository::class);
            $definitions[ProductAdminQueryRepository::class] = \DI\autowire(InMemoryProductAdminQueryRepository::class);
            $definitions[ProductPublicQueryRepository::class] = \DI\autowire(InMemoryProductPublicQueryRepository::class);
            $definitions[ProductCustomerViewQueryRepository::class] = \DI\autowire(InMemoryProductCustomerViewRepository::class);
            $definitions[ProductSearchRepository::class] = \DI\autowire(InMemoryProductSearchRepository::class);
            $definitions[ProductLookupRepository::class] = \DI\autowire(InMemoryProductLookupRepository::class);
            $definitions[ProductClassificationRepository::class] = \DI\autowire(InMemoryProductClassificationRepository::class);
            $definitions[ProductSpecificationRepository::class] = \DI\autowire(InMemoryProductSpecificationRepository::class);
            $definitions[ProductMediaRepository::class] = \DI\autowire(InMemoryProductMediaRepository::class);
            $definitions[ProductDocumentRepository::class] = \DI\autowire(InMemoryProductDocumentRepository::class);
            $definitions[Product360Repository::class] = \DI\autowire(InMemoryProduct360Repository::class);
            $definitions[ProductArModelRepository::class] = \DI\autowire(InMemoryProductArModelRepository::class);
            $definitions[ProductMarketRepository::class] = \DI\autowire(InMemoryProductMarketRepository::class);
            $definitions[ProductRelationshipRepository::class] = \DI\autowire(InMemoryProductRelationshipRepository::class);
            $definitions[ProductSeoRepository::class] = \DI\autowire(InMemoryProductSeoRepository::class);
            $definitions[SiteConfigurationRepository::class] = \DI\autowire(InMemorySiteConfigurationRepository::class);
            $definitions[ProductCodeRuleRepository::class] = \DI\autowire(InMemoryProductCodeRuleRepository::class);
            $definitions[ProductPricingRepository::class] = \DI\autowire(InMemoryProductPricingRepository::class);
            $definitions[ProductAuditRepository::class] = \DI\autowire(InMemoryProductAuditRepository::class);
            $definitions[ProductIntegrationRepository::class] = \DI\autowire(InMemoryProductIntegrationRepository::class);
            $definitions[LegacyProductMappingRepository::class] = \DI\autowire(InMemoryLegacyProductMappingRepository::class);
            $definitions[ProductSubResourceResolver::class] = static function (Container $container): ProductSubResourceResolver {
                return new ProductSubResourceResolver(self::get($container, InMemoryProductStore::class), null);
            };
        }

        $builder->addDefinitions($definitions);

        return $builder->build();
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private static function addGuestAuthDefinitions(array &$definitions, Settings $settings): void
    {
        $definitions[OtpGenerator::class] = \DI\autowire(SecureOtpGenerator::class);
        $definitions[OtpHasher::class] = \DI\autowire(PasswordOtpHasher::class);
        $definitions[GuestAccessTokenIssuer::class] = static function () use ($settings): GuestAccessTokenIssuer {
            return new GuestJwtIssuer(
                issuer: $settings->guestJwtIssuer,
                audience: $settings->guestJwtAudience,
                secret: $settings->guestJwtSecret,
            );
        };
        $definitions[GuestAccessTokenValidator::class] = static function (Container $container) use ($settings): GuestAccessTokenValidator {
            return new GuestJwtValidator(
                issuer: $settings->guestJwtIssuer,
                audience: $settings->guestJwtAudience,
                secret: $settings->guestJwtSecret,
                ttlSeconds: $settings->guestJwtTtlSeconds,
                clockSkewSeconds: $settings->guestJwtClockSkewSeconds,
                sessions: self::get($container, GuestSessionRepository::class),
                tokenHasher: self::get($container, SessionTokenHasher::class),
                users: self::get($container, UserRepository::class),
                clock: self::get($container, Clock::class),
            );
        };
        $definitions[GuestUserProvisioningService::class] = \DI\autowire(GuestUserProvisioningService::class);
        $definitions[GuestLoginEligibilityService::class] = \DI\autowire(GuestLoginEligibilityService::class);
        $definitions[RequestGuestOtpService::class] = \DI\autowire(RequestGuestOtpService::class);
        $definitions[VerifyGuestOtpService::class] = \DI\autowire(VerifyGuestOtpService::class);
        $definitions[CreateGuestSessionService::class] = static function (Container $container) use ($settings): CreateGuestSessionService {
            return new CreateGuestSessionService(
                sessions: self::get($container, GuestSessionRepository::class),
                clock: self::get($container, Clock::class),
                ttl: 'PT' . $settings->guestJwtTtlSeconds . 'S',
            );
        };
        $definitions[IssueGuestSessionService::class] = static function (Container $container) use ($settings): IssueGuestSessionService {
            return new IssueGuestSessionService(
                createGuestSession: self::get($container, CreateGuestSessionService::class),
                tokenIssuer: self::get($container, GuestAccessTokenIssuer::class),
                tokenHasher: self::get($container, SessionTokenHasher::class),
                clock: self::get($container, Clock::class),
                ttlSeconds: $settings->guestJwtTtlSeconds,
            );
        };
        $definitions[RevokeGuestSessionService::class] = \DI\autowire(RevokeGuestSessionService::class);
        $definitions[RequestAndSendGuestOtpService::class] = \DI\autowire(RequestAndSendGuestOtpService::class);
        $definitions[RequestGuestOtpAction::class] = \DI\autowire(RequestGuestOtpAction::class);
        $definitions[VerifyGuestOtpAction::class] = \DI\autowire(VerifyGuestOtpAction::class);
        $definitions[LogoutGuestAction::class] = \DI\autowire(LogoutGuestAction::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function get(Container $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new InvalidArgumentException(sprintf('Container service "%s" has unexpected type.', $id));
        }

        return $service;
    }

    private static function createGuestOtpEmailSender(Settings $settings, Container $container): GuestOtpEmailSender
    {
        if ($settings->usesDevelopmentGuestEmail()) {
            return new DevelopmentGuestOtpEmailSender(
                revealEnabled: $settings->guestOtpDevReveal,
                isProduction: $settings->isProduction(),
            );
        }

        $http = self::get($container, JsonHttpClient::class);

        if ($settings->usesMicrosoftGraphGuestEmail()) {
            if (
                $settings->graphTenantId === ''
                || $settings->graphClientId === ''
                || $settings->graphClientSecret === ''
                || $settings->rfqMailbox === ''
            ) {
                throw new InvalidArgumentException(
                    'GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, and RFQ_MAILBOX are required when GUEST_EMAIL_PROVIDER=microsoft-graph.',
                );
            }

            return new MicrosoftGraphGuestOtpEmailSender(
                http: $http,
                tenantId: $settings->graphTenantId,
                clientId: $settings->graphClientId,
                clientSecret: $settings->graphClientSecret,
                mailbox: $settings->rfqMailbox,
                fromDisplayName: 'TriCab',
                logoUrl: $settings->guestOtpEmailLogoUrl !== '' ? $settings->guestOtpEmailLogoUrl : null,
            );
        }

        if ($settings->postmarkServerToken === '' || $settings->postmarkFromEmail === '') {
            throw new InvalidArgumentException(
                'POSTMARK_SERVER_TOKEN and POSTMARK_FROM_EMAIL are required when GUEST_EMAIL_PROVIDER=postmark.',
            );
        }

        return new PostmarkGuestOtpEmailSender(
            http: $http,
            serverToken: $settings->postmarkServerToken,
            fromEmail: $settings->postmarkFromEmail,
            fromName: $settings->postmarkFromName,
            messageStream: $settings->postmarkMessageStream,
        );
    }
}
