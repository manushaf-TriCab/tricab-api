<?php

declare(strict_types=1);

namespace TriCab\Api\Bootstrap;

use DI\Container;
use DI\ContainerBuilder;
use InvalidArgumentException;
use PDO;
use TriCab\Api\Assistant\Application\AssistantQueryInterpreter;
use TriCab\Api\Assistant\Application\AssistantResponseProvider;
use TriCab\Api\Assistant\Application\AssistantTurnEvents;
use TriCab\Api\Assistant\Application\AssistantTurnTrace;
use TriCab\Api\Assistant\Application\AssistantConversationRepository;
use TriCab\Api\Assistant\Application\AssistantProductGrounder;
use TriCab\Api\Assistant\Application\AssistantCodeProductLookup;
use TriCab\Api\Assistant\Application\AssistantStyleOptionsProvider;
use TriCab\Api\Assistant\Application\AssistantToolExecutor;
use TriCab\Api\Assistant\Application\ProductAssistantService;
use TriCab\Api\Assistant\Infrastructure\AgentAssistantQueryInterpreter;
use TriCab\Api\Assistant\Infrastructure\AzureSqlAssistantConversationRepository;
use TriCab\Api\Assistant\Infrastructure\AzureSqlAssistantCodeProductLookup;
use TriCab\Api\Assistant\Infrastructure\AzureSqlAssistantStyleOptionsProvider;
use TriCab\Api\Assistant\Infrastructure\DeterministicAssistantQueryInterpreter;
use TriCab\Api\Assistant\Infrastructure\DeterministicAssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\FallbackAssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\ClaudeFoundryAgentProvider;
use TriCab\Api\Assistant\Infrastructure\Foundry\ManagedIdentityFoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\InMemoryAssistantConversationRepository;
use TriCab\Api\Assistant\Infrastructure\InMemoryAssistantCodeProductLookup;
use TriCab\Api\Assistant\Infrastructure\InMemoryAssistantStyleOptionsProvider;
use TriCab\Api\Assistant\Infrastructure\ProductAssistantToolExecutor;
use TriCab\Api\Assistant\Infrastructure\SearchGroundedDeterministicAssistantResponseProvider;
use TriCab\Api\Assistant\Infrastructure\Prompt\MarkdownPromptRepository;
use TriCab\Api\Config\Settings;

use TriCab\Api\Http\Action\Admin\AddProductDocumentAction;
use TriCab\Api\Http\Action\Admin\AddProductMediaAction;
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
use TriCab\Api\Http\Action\Admin\ClearProductPresentationMediaRoleAction;
use TriCab\Api\Http\Action\Admin\UpdateProductMediaRoleAction;
use TriCab\Api\Site\Persistence\AzureSqlSiteConfigurationRepository;
use TriCab\Api\Site\Persistence\InMemory\InMemorySiteConfigurationRepository;
use TriCab\Core\Site\Application\Service\CustomerViewThemeService;
use TriCab\Core\Site\Contract\SiteConfigurationRepository;
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
use TriCab\Api\Http\Action\PublicRegionAction;
use TriCab\Api\Http\Action\PublicContent\GetPublicAboutAction;
use TriCab\Api\Http\Action\PublicContent\GetPublicContactAction;
use TriCab\Api\Content\PublicContentRepository;
use TriCab\Api\Http\Action\Technical\GetTechnicalArticleAction;
use TriCab\Api\Http\Action\Technical\GetTechnicalProductCodingAction;
use TriCab\Api\Http\Action\Technical\GetTechnicalTableAction;
use TriCab\Api\Http\Action\Technical\ListTechnicalDocumentsAction;
use TriCab\Api\Http\Action\Technical\ListTechnicalSectionsAction;
use TriCab\Api\Technical\TechnicalContentRepository;
use TriCab\Api\Technical\Document\TechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\Document\EmptyTechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\Document\InMemoryTechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\Document\ResolveTechnicalDocumentsQuery;
use TriCab\Api\Technical\Document\TechnicalDocumentClientUrlResolver;
use TriCab\Api\Technical\Persistence\AzureSqlTechnicalDocumentLibraryRepository;
use TriCab\Api\Http\Action\Admin\GetAdminDashboardAction;
use TriCab\Api\Http\Action\Admin\GetAdminDashboardProductActivityAction;
use TriCab\Api\Http\Action\Admin\PreviewProductCodeAction;
use TriCab\Api\Admin\Dashboard\AdminDashboardQueryRepository;
use TriCab\Api\Admin\Dashboard\AzureSqlAdminDashboardQueryRepository;
use TriCab\Api\Admin\Dashboard\InMemoryAdminDashboardQueryRepository;
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
use TriCab\Api\Http\Action\Admin\UpdateProductPageContentAction;
use TriCab\Api\Http\Action\Admin\UpdateProductPageMediaLayoutAction;
use TriCab\Api\Http\Action\Admin\UpdateProductThumbnailPresentationAction;
use TriCab\Api\Http\Action\Admin\UpdateProductVariantAction;
use TriCab\Api\Http\Action\Admin\UpsertProductPricingAction;
use TriCab\Api\Http\Action\Admin\CompareLegacyBaseCostAction;
use TriCab\Api\Http\Action\Admin\VariantLegacyPricingIdentityAdminAction;
use TriCab\Api\Http\Action\Admin\SetProductPricingActiveAction;
use TriCab\Api\Http\Action\Admin\WithdrawProductDocumentAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductBySlugAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductCustomerViewAction;
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
use TriCab\Api\Http\Action\TrustedDevice\ExchangeTrustedDeviceSessionAction;
use TriCab\Api\Http\Action\TrustedDevice\RegisterTrustedDeviceAction;
use TriCab\Api\Http\Action\TrustedDevice\RevokeTrustedDeviceAction;
use TriCab\Api\Http\Action\Passkey\ListPasskeysAction;
use TriCab\Api\Http\Action\Passkey\PasskeyAuthenticationCompleteAction;
use TriCab\Api\Http\Action\Passkey\PasskeyAuthenticationOptionsAction;
use TriCab\Api\Http\Action\Passkey\PasskeyRegistrationCompleteAction;
use TriCab\Api\Http\Action\Passkey\PasskeyRegistrationOptionsAction;
use TriCab\Api\Http\Action\Passkey\RenamePasskeyAction;
use TriCab\Api\Http\Action\Passkey\RequestPasskeySetupLinkAction;
use TriCab\Api\Http\Action\Passkey\RevokePasskeyAction;
use TriCab\Api\Http\Action\Passkey\VerifyPasskeySetupLinkAction;
use TriCab\Api\Http\Action\RequestGuestOtpAction;
use TriCab\Api\Http\Action\AuthenticateGoogleAction;
use TriCab\Api\Http\Action\AppleAuthChallengeAction;
use TriCab\Api\Http\Action\AuthenticateAppleAction;
use TriCab\Api\Http\Action\AuthenticateAppleNativeAction;
use TriCab\Api\Http\Action\VerifyGuestOtpAction;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\CustomerManagementErrorMapper;
use TriCab\Api\Http\Auth\ProductManagementErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthErrorMapper;
use TriCab\Api\Http\Auth\TrustedDeviceAuthErrorMapper;
use TriCab\Api\Http\Auth\GoogleAuthErrorMapper;
use TriCab\Api\Http\Auth\AppleAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\GuestAuthRateLimiter;
use TriCab\Api\Http\Auth\GuestAuthRateLimited;
use TriCab\Api\Http\Auth\NoOpGuestAuthRateLimiter;
use TriCab\Api\Http\Auth\WindowedGuestAuthRateLimiter;
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
use TriCab\Api\Product\Persistence\AzureSqlProductStyleRepository;
use TriCab\Api\Product\Persistence\AzureSqlCatalogueNavigationRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductCodeRuleRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductCodeSearchRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductDocumentRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductIntegrationRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductLookupRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductMarketRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductMediaRepository;
use TriCab\Api\Product\Persistence\AzureSqlProduct360Repository;
use TriCab\Api\Product\Persistence\AzureSqlProductArModelRepository;
use TriCab\Api\Product\Persistence\AzureSqlProduct3DAssetRepository;
use TriCab\Api\Product\Persistence\AzureSqlProduct3DModelRepository;
use TriCab\Api\Product\Generation\DisabledProduct3DGenerationProvider;
use TriCab\Api\Product\Generation\Img2ThreeJs\Img2ThreeJsGenerationProvider;
use TriCab\Api\Product\Generation\ProceduralCable3DGenerationProvider;
use TriCab\Api\Product\Processing\ProcOpenProduct3DAssetProcessRunner;
use TriCab\Api\Product\Storage\AzureProductBlobStore;
use TriCab\Api\Product\Storage\DisabledProductBlobStore;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Product\Storage\ProductDevMediaUrlResolver;
use TriCab\Api\Http\Action\Dev\DevProductFileDeliveryAction;
use TriCab\Api\Product\Persistence\AzureSqlPriceListRepository;
use TriCab\Api\Product\Persistence\AzureSqlVariantLegacyPricingIdentityRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductPricingRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductCustomerViewRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductPublicQueryRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductRelationshipRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductSearchRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductSeoRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductPageContentRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductPageMediaLayoutRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductThumbnailPresentationRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductSpecificationRepository;
use TriCab\Api\Product\Persistence\AzureSqlProductVariantRepository;
use TriCab\Api\Product\Persistence\AzureSqlRegionRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryLegacyProductMappingRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAdminQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductAuditRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductClassificationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryCatalogueNavigationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCodeRuleRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCodeSearchRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductDocumentRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductIntegrationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductLookupRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductMarketRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductMediaRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProduct360Repository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductArModelRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProduct3DAssetRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProduct3DModelRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryPriceListRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryVariantLegacyPricingIdentityRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPricingRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductCustomerViewRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPublicQueryRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductRelationshipRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductSearchRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductSeoRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPageContentRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductPageMediaLayoutRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductThumbnailPresentationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductSpecificationRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductVariantRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryRegionRepository;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Product\Region\RegionPreferenceResolver;
use TriCab\Api\Http\Action\AssistantMessageAction;
use TriCab\Api\Http\Auth\PasskeyAuthErrorMapper;
use TriCab\Api\Http\Auth\WindowedPasskeyAuthRateLimiter;
use TriCab\Api\Identity\Authentication\CompositeBearerTokenValidator;
use TriCab\Api\Identity\Authentication\EntraJwksClient;
use TriCab\Api\Identity\Authentication\EntraJwksProvider;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;
use TriCab\Api\Identity\Authentication\EntraTokenValidator;
use TriCab\Api\Identity\Authentication\GoogleJwksClient;
use TriCab\Api\Identity\Authentication\GoogleOidcIdTokenValidator;
use TriCab\Api\Identity\Authentication\AppleJwksClient;
use TriCab\Api\Identity\Authentication\AppleOidcIdTokenValidator;
use TriCab\Api\Identity\Authentication\AppleClientSecretGenerator;
use TriCab\Api\Identity\Authentication\AppleAuthorizationCodeHttpExchanger;
use TriCab\Api\Identity\Authentication\AppleAuthorizationChallengeService;
use TriCab\Api\Identity\Authentication\GuestJwtIssuer;
use TriCab\Api\Identity\Authentication\GuestJwtValidator;
use TriCab\Api\Identity\Authentication\MicrosoftEntraJwtValidator;
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Identity\Email\DevelopmentGuestPasskeyEmailSender;
use TriCab\Api\Identity\Email\MicrosoftGraphGuestOtpEmailSender;
use TriCab\Api\Identity\Email\MicrosoftGraphGuestPasskeyEmailSender;
use TriCab\Api\Identity\Email\PostmarkGuestOtpEmailSender;
use TriCab\Api\Identity\Email\PostmarkGuestPasskeyEmailSender;
use TriCab\Api\Identity\Passkey\FakePasskeyCeremony;
use TriCab\Api\Identity\Passkey\WebauthnLibPasskeyCeremony;
use TriCab\Api\Identity\Persistence\AzureSqlEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\AzureSqlGuestPasskeySetupTokenRepository;
use TriCab\Api\Identity\Persistence\AzureSqlPasskeyChallengeRepository;
use TriCab\Api\Identity\Persistence\AzureSqlPasskeyRegistrationSessionRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserPasskeyRepository;
use TriCab\Api\Identity\Persistence\AzureSqlGuestLoginEligibilityLookup;
use TriCab\Api\Identity\Persistence\AzureSqlGuestSessionRepository;
use TriCab\Api\Identity\Persistence\AzureSqlTrustedDeviceRepository;
use TriCab\Api\Identity\Persistence\AzureSqlPermissionManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlRoleManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementAuditRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserManagementRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserProvisioningRepository;
use TriCab\Api\Identity\Persistence\AzureSqlUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Identity\Persistence\InMemoryEmailOtpChallengeRepository;
use TriCab\Api\Identity\Persistence\InMemoryGuestPasskeySetupTokenRepository;
use TriCab\Api\Identity\Persistence\InMemoryGuestSessionRepository;
use TriCab\Api\Identity\Persistence\InMemoryTrustedDeviceRepository;
use TriCab\Api\Identity\Persistence\InMemoryPasskeyChallengeRepository;
use TriCab\Api\Identity\Persistence\InMemoryPasskeyRegistrationSessionRepository;
use TriCab\Api\Identity\Persistence\InMemoryUserPasskeyRepository;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Api\Http\Middleware\ReferenceDataCacheInvalidationMiddleware;
use TriCab\Api\Infrastructure\Authentication\AzureCliAccessTokenProvider;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentitySqlAccessTokenProvider;
use TriCab\Api\Infrastructure\Authentication\ManagedIdentityTokenClient;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Api\Infrastructure\Cache\ApplicationCacheFactory;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;
use TriCab\Api\Infrastructure\Database\AzureSqlConnectionFactory;
use TriCab\Api\Infrastructure\Database\AzureSqlPdoFactory;
use TriCab\Api\Infrastructure\Database\LazySqlConnection;
use TriCab\Api\Infrastructure\Database\NativePdoConnector;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMiddleware;
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
use TriCab\Api\Technical\ResolveTechnicalAccessoriesAccessService;
use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedMarketCodesService;
use TriCab\Core\Customer\Application\Service\UnlinkCustomerContactFromUserService;
use TriCab\Core\Customer\Application\Service\UpdateCustomerContactService;
use TriCab\Core\Customer\Application\Service\UpdateCustomerService;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerContactUserRepository;
use TriCab\Core\Customer\Contract\CustomerPortalUserProvisioner;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Application\Service\IdentityResolutionService;
use TriCab\Core\Identity\Application\Service\AuthenticateWithGoogleService;
use TriCab\Core\Identity\Application\Service\AuthenticateWithAppleService;
use TriCab\Core\Identity\Application\Service\AuthenticateWithAppleNativeService;
use TriCab\Core\Identity\Application\Service\CompositeGuestLoginEligibilityLookup;
use TriCab\Core\Identity\Application\Service\CreateGuestSessionService;
use TriCab\Core\Identity\Application\Service\GuestLoginEligibilityService;
use TriCab\Core\Identity\Application\Service\GuestUserProvisioningService;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;
use TriCab\Core\Identity\Application\Service\ExchangeTrustedDeviceSessionService;
use TriCab\Core\Identity\Application\Service\RegisterTrustedDeviceService;
use TriCab\Core\Identity\Application\Service\RevokeTrustedDeviceService;
use TriCab\Core\Identity\Application\Service\ManageUserPasskeysService;
use TriCab\Core\Identity\Application\Service\CompletePasskeyAuthenticationService;
use TriCab\Core\Identity\Application\Service\CompletePasskeyRegistrationService;
use TriCab\Core\Identity\Application\Service\RequestGuestPasskeySetupLinkService;
use TriCab\Core\Identity\Application\Service\StartPasskeyAuthenticationService;
use TriCab\Core\Identity\Application\Service\StartPasskeyRegistrationService;
use TriCab\Core\Identity\Application\Service\VerifyGuestPasskeySetupTokenService;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
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
use TriCab\Core\Identity\Contract\GoogleIdTokenValidator;
use TriCab\Core\Identity\Contract\AppleIdTokenValidator;
use TriCab\Core\Identity\Contract\AppleNativeIdTokenValidator;
use TriCab\Core\Identity\Contract\AppleAuthorizationCodeExchanger;
use TriCab\Core\Identity\Contract\GuestAccessTokenIssuer;
use TriCab\Core\Identity\Contract\GuestAccessTokenValidator;
use TriCab\Core\Identity\Contract\GuestLoginEligibilityLookup;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Contract\GuestPasskeyEmailSender;
use TriCab\Core\Identity\Contract\GuestPasskeySetupTokenRepository;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Contract\TrustedDeviceRepository;
use TriCab\Core\Identity\Contract\PasskeyAuthRateLimiter;
use TriCab\Core\Identity\Contract\PasskeyCeremony;
use TriCab\Core\Identity\Contract\PasskeyChallengeRepository;
use TriCab\Core\Identity\Contract\PasskeyRegistrationSessionRepository;
use TriCab\Core\Identity\Contract\UserPasskeyRepository;
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
use TriCab\Core\Identity\Domain\Service\TrustedDeviceCredentialGenerator;
use TriCab\Core\Product\Application\Service\AddProductDocumentService;
use TriCab\Core\Product\Application\Service\AddProductMediaService;
use TriCab\Api\Http\Action\Admin\ProductSeriesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductFamiliesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductCategoriesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductPriceListsAdminAction;
use TriCab\Api\Http\Action\Admin\CatalogueTabsAdminAction;
use TriCab\Api\Http\Action\Admin\CatalogueNodesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductCataloguePlacementsAdminAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCataloguePanelAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCatalogueTabsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCatalogueTabTreeAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicCatalogueNodeAction;
use TriCab\Api\Http\Action\CableCalculator\PostCableCalculatorCalculateAction;
use TriCab\Api\CableCalculator\LegacyCliTriCalcEngine;
use TriCab\Core\CableCalculator\Application\Service\CalculateCableService;
use TriCab\Core\CableCalculator\Contract\CableCalculatorEngine;
use TriCab\Core\CableCalculator\Engine\NativeCableCalculatorEngine;
use TriCab\Core\CableCalculator\Application\Service\ResolveCableCalculationProductsService;
use TriCab\Core\CableCalculator\Application\ProductResolution\NullCableCalculatorProductResolver;
use TriCab\Core\CableCalculator\Contract\CableCalculatorProductResolver;
use TriCab\Api\CableCalculator\AzureSqlCableCalculatorProductResolver;
use TriCab\Api\Http\Action\ProductFinder\FindProductAction;
use TriCab\Api\ProductFinder\HttpProductFinderClient;
use TriCab\Core\ProductFinder\Application\Client\NullProductFinderClient;
use TriCab\Core\ProductFinder\Contract\ProductFinderClient;
use TriCab\Core\Product\Application\Service\ManagePriceListService;
use TriCab\Core\Product\Application\Service\ManageProductClassificationService;
use TriCab\Core\Product\Application\Service\ManageProductStyleService;
use TriCab\Core\Product\Application\Service\MakeLocalCopyStyleResourceService;
use TriCab\Core\Product\Application\Service\ResolveEffectiveStyleResourcesService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleHierarchyService;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleInventoryService;
use TriCab\Core\Product\Application\Service\CreateMissingProductStylesFromInventoryService;
use TriCab\Core\Product\Application\Service\ReviewLegacyStyleHierarchyEvidenceService;
use TriCab\Core\Product\Application\Service\DiscoverExistingProductStyleMappingService;
use TriCab\Core\Product\Application\Service\DiscoverProductCodeStyleMappingService;
use TriCab\Core\Product\Application\Service\DiscoverCatalogueProductStyleMappingService;
use TriCab\Core\Product\Application\Service\DiscoverStyleSharedDataGeneralisationService;
use TriCab\Core\Product\Application\Service\StyleSharedDataClassifier;
use TriCab\Core\Product\Application\Service\ResolveCatalogueStyleFamilyProductsService;
use TriCab\Core\Product\Contract\CatalogueProductStyleEvidenceSource;
use TriCab\Core\Product\Persistence\EmptyCatalogueProductStyleEvidenceSource;
use TriCab\Api\Product\Migration\AzureSqlCatalogueProductStyleEvidenceSource;
use TriCab\Core\Product\Application\Service\ApplyProductCodeStyleMappingService;
use TriCab\Core\Product\Application\Service\ApplyApprovedCatalogueNodeProductStylesService;
use TriCab\Core\Product\Application\Service\ApplyApprovedStyleFamilyTreeAndMappingService;
use TriCab\Core\Product\Application\Service\CatalogueNodeEffectiveProductsService;
use TriCab\Core\Product\Contract\LegacyStyleOccurrenceSource;
use TriCab\Core\Product\Contract\ProductStyleMappingIdentitySource;
use TriCab\Core\Product\Contract\ProductStyleFamilyEvidenceSource;
use TriCab\Core\Product\Contract\StyleSharedDataEvidenceSource;
use TriCab\Core\Product\Application\Service\AssignProductStyleService;
use TriCab\Core\Product\Application\Service\EnrichPublicProductWithStyleInheritanceService;
use TriCab\Core\Product\Application\Service\InitiateStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\CompleteStyleMediaUploadService;
use TriCab\Core\Product\Application\Service\DeleteStyleMediaAssetService;
use TriCab\Core\Product\Persistence\InMemoryProductStyleRepository;
use TriCab\Core\Product\Persistence\EmptyProductStyleMappingIdentitySource;
use TriCab\Core\Product\Persistence\EmptyProductStyleFamilyEvidenceSource;
use TriCab\Core\Product\Persistence\EmptyStyleSharedDataEvidenceSource;
use TriCab\Api\Product\Migration\AzureSqlProductStyleMappingIdentitySource;
use TriCab\Api\Product\Migration\AzureSqlProductStyleFamilyEvidenceSource;
use TriCab\Api\Product\Persistence\AzureSqlStyleSharedDataEvidenceSource;
use TriCab\Core\Product\Application\Service\VerifyLegacyPriceContextService;
use TriCab\Core\Product\Application\Service\ManageCatalogueNavigationService;
use TriCab\Core\Product\Application\Service\GetPublicCatalogueService;
use TriCab\Core\Product\Application\Service\SpecificationDefinitionService;
use TriCab\Core\Product\Application\Service\SpecificationGroupService;
use TriCab\Core\Product\Application\Service\SpecificationOptionService;
use TriCab\Core\Product\Application\Service\SpecificationUnitService;
use TriCab\Api\Http\Action\Admin\SpecificationDefinitionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationGroupsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationOptionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationUnitsAdminAction;
use TriCab\Api\Http\Action\Admin\Migration\LegacyMigrationAdminAction;
use TriCab\Api\Http\Action\Admin\Migration\ProductMigrationAdminAction;
use TriCab\Api\Product\Persistence\AzureSqlMigrationBatchRepository;
use TriCab\Api\Product\Persistence\InMemory\InMemoryMigrationBatchRepository;
use TriCab\Core\Product\Migration\Application\Service\GetCatalogueReadinessService;
use TriCab\Api\LegacyMigration\Application\Service\LegacyStaffReadOnlyAnalysisService;
use TriCab\Api\LegacyMigration\Application\Service\StaffEntraLinkDryRunService;
use TriCab\Api\LegacyMigration\Application\Service\StaffEntraLinkExecuteService;
use TriCab\Api\LegacyMigration\Application\Service\StaffUserMigrationDryRunService;
use TriCab\Api\LegacyMigration\Application\Service\StaffUserMigrationExecuteService;
use TriCab\Api\LegacyMigration\Application\Service\StaffMigrationPreparationService;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlLegacyStaffUserMappingRepository;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlStaffIdentityUserCreator;
use TriCab\Api\LegacyMigration\Persistence\DisabledLegacyStaffUserMappingRepository;
use TriCab\Api\LegacyMigration\Persistence\DisabledStaffIdentityUserCreator;
use TriCab\Api\LegacyMigration\Persistence\DisabledStaffMigrationWriteUnit;
use TriCab\Api\LegacyMigration\Persistence\DisabledStaffEntraLinkWriteUnit;
use TriCab\Api\LegacyMigration\Infrastructure\MicrosoftGraphEntraDirectoryReader;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlStaffEntraLinkWriteUnit;
use TriCab\Api\LegacyMigration\Persistence\InMemoryLegacyMigrationStore;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlLegacyMigrationStore;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlExistingNormalizedEmailLookup;
use TriCab\Api\LegacyMigration\LiveLegacyIdentityHealthProbe;
use TriCab\Core\LegacyMigration\Application\Service\LegacyMigrationEngine;
use TriCab\Core\LegacyMigration\Application\Service\MigrationAuditService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationBatchService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationConflictService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationExecutionService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationReportingService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationRollbackService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationSettingsService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationSourceService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationStatisticsService;
use TriCab\Core\LegacyMigration\Application\Service\MigrationValidationService;
use TriCab\Core\LegacyMigration\Contract\EntraDirectoryReader;
use TriCab\Core\LegacyMigration\Contract\ExistingNormalizedEmailLookup;
use TriCab\Core\LegacyMigration\Contract\StaffEntraLinkWriteUnit;
use TriCab\Core\LegacyMigration\Contract\StaffIdentityUserCreator;
use TriCab\Core\LegacyMigration\Contract\StaffMigrationWriteUnit;
use TriCab\Api\LegacyMigration\Persistence\AzureSqlStaffMigrationWriteUnit;
use TriCab\Core\LegacyMigration\Contract\LegacyStaffUserMappingRepository;
use TriCab\Core\LegacyMigration\StaffEntraLink\StaffEntraLinkExecutor;
use TriCab\Core\LegacyMigration\StaffMigration\StaffUserMigrationExecutor;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationModuleRegistry;
use TriCab\Core\LegacyMigration\Contract\LegacyMigrationStore;
use TriCab\Core\LegacyMigration\Contract\LegacySourceHealthProbe;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceConfig;
use TriCab\Core\LegacyMigration\Live\LegacyIdentitySourceHealth;
use TriCab\Core\LegacyMigration\Module\DefaultModuleRegistry;
use TriCab\Core\LegacyMigration\Module\FrameworkModule;
use TriCab\Core\LegacyMigration\Plugin\LegacyWordPressUserExtractor;
use TriCab\Core\LegacyMigration\Plugin\NullExistingNormalizedEmailLookup;
use TriCab\Core\Product\Migration\Application\Service\ProductMigrationService;
use TriCab\Core\Product\Migration\Contract\MigrationBatchRepository;
use TriCab\Core\Product\Migration\Contract\MigrationLookupIndex;
use TriCab\Core\Product\Migration\Identity\EmptyMigrationLookupIndex;
use TriCab\Core\Product\Migration\Identity\ProductIdentityMatcher;
use TriCab\Core\Product\Migration\Identity\ProductVariantClassifier;
use TriCab\Api\Product\Migration\MysqlLegacyStyleOccurrenceSource;
use TriCab\Api\Product\Migration\MysqlLegacyTcRepository;
use TriCab\Api\Product\Migration\MysqlLegacyWordPressRepository;
use TriCab\Api\Product\Migration\MysqlLiveInventoryProbe;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Application\Service\InspectLegacyCatalogueService;
use TriCab\Core\Product\Migration\Application\Service\DiscoverLegacyMasterDataService;
use TriCab\Core\Product\Migration\Application\Service\BuildCatalogueMappingReadinessService;
use TriCab\Core\Product\Migration\Readiness\StyleCodeNormalizer;
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
use TriCab\Core\Product\Application\Service\AssignProductRegionsService;
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
use TriCab\Core\Product\Application\Service\GetProductPricingOverviewService;
use TriCab\Core\Product\Application\Service\GetProductPricingMatrixService;
use TriCab\Core\Product\Application\Service\GetProductBaseCostHistoryService;
use TriCab\Core\Product\Application\Service\CompareLegacyBaseCostService;
use TriCab\Core\Product\Application\Service\ResolveLegacyVariantPricingIdentityService;
use TriCab\Core\Product\Application\Service\ManageVariantLegacyPricingIdentityService;
use TriCab\Core\Product\Application\Service\AuditLegacyVariantPricingIdentityService;
use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;
use TriCab\Core\Product\Application\Service\SetProductDefaultPricingUnitService;
use TriCab\Core\Product\Application\Service\SetProductPricingActiveService;
use TriCab\Core\Product\Application\Service\GetProductPublishReadinessService;
use TriCab\Core\Product\Application\Service\GetProductRegionsService;
use TriCab\Core\Product\Application\Service\GetProductVariantsService;
use TriCab\Core\Product\Application\Service\GetPublicProductService;
use TriCab\Core\Product\Application\Service\GetRegionsService;
use TriCab\Core\Product\Application\Service\ManageRegionService;
use TriCab\Core\Product\Application\Service\ListAdminProductsService;
use TriCab\Core\Product\Application\Service\PreviewProductCodeService;
use TriCab\Core\Product\Application\Service\ProductAuditRecorder;
use TriCab\Core\Product\Application\Service\PublishProductService;
use TriCab\Core\Product\Application\Service\ReorderProductMediaService;
use TriCab\Core\Product\Application\Service\ReplaceProductRelationshipsService;
use TriCab\Core\Product\Application\Service\RestoreArchivedProductService;
use TriCab\Core\Product\Application\Service\RestoreDeletedProductService;
use TriCab\Core\Product\Application\Service\RestoreProductVariantService;
use TriCab\Core\Product\Application\Service\SearchProductCodesService;
use TriCab\Core\Product\Application\Service\SearchProductsService;
use TriCab\Core\Product\Application\Service\ClearProductPresentationMediaRoleService;
use TriCab\Core\Product\Application\Service\SetPrimaryProductImageService;
use TriCab\Core\Product\Application\Service\UpdateProductMediaRoleService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductDocumentService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductMediaService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductService;
use TriCab\Core\Product\Application\Service\SoftDeleteProductVariantService;
use TriCab\Core\Product\Application\Service\UnpublishProductService;
use TriCab\Core\Product\Application\Service\UpdateProductSeoService;
use TriCab\Core\Product\Application\Service\UpdateProductPageContentService;
use TriCab\Core\Product\Application\Service\UpdateProductPageMediaLayoutService;
use TriCab\Core\Product\Application\Service\UpdateProductThumbnailPresentationService;
use TriCab\Core\Product\Application\Service\UpdateProductService;
use TriCab\Core\Product\Application\Service\UpdateProductVariantService;
use TriCab\Core\Product\Application\Service\UpsertProductPricingService;
use TriCab\Core\Product\Application\Service\WithdrawProductDocumentService;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductAuditRepository;
use TriCab\Core\Product\Contract\PriceListRepository;
use TriCab\Core\Product\Contract\VariantLegacyPricingIdentityRepository;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;
use TriCab\Core\Product\Contract\ProductCodeRuleRepository;
use TriCab\Core\Product\Contract\ProductCodeSearchRepository;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Contract\ProductIntegrationRepository;
use TriCab\Core\Product\Contract\ProductLookupRepository;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductFilePathBuilder;
use TriCab\Core\Product\Contract\ProductFileScanPolicy;
use TriCab\Core\Product\Contract\ProductImageMetadataExtractor;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Contract\Product3DGenerationProvider;
use TriCab\Core\Product\Contract\Product3DAssetProcessRunner;
use TriCab\Core\Product\Contract\Product3DAssetRepository;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\Service\ProductFileValidationPolicy;
use TriCab\Core\Product\Application\Service\ConfigurableProductFileScanPolicy;
use TriCab\Core\Product\Application\Service\GdProductImageMetadataExtractor;
use TriCab\Core\Product\Application\Service\NullProductImageMetadataExtractor;
use TriCab\Core\Product\Application\Service\InitiateProductMediaUploadService;
use TriCab\Api\Http\Action\Admin\CompleteArModelUploadAction;
use TriCab\Api\Http\Action\Admin\InitiateArModelUploadAction;
use TriCab\Api\Http\Action\Admin\Product3DModelsAdminAction;
use TriCab\Api\Http\Action\Admin\GetProduct3DSpecificationSourceAction;
use TriCab\Api\Http\Action\Admin\ActivateProduct360SetAction;
use TriCab\Api\Http\Action\Admin\Complete360FrameUploadAction;
use TriCab\Api\Http\Action\Admin\Initiate360FrameUploadAction;
use TriCab\Api\Http\Action\Admin\CreateProduct360SetAction;
use TriCab\Core\Product\Application\Service\CompleteArModelUploadService;
use TriCab\Core\Product\Application\Service\InitiateArModelUploadService;
use TriCab\Core\Product\Application\Service\CompleteProduct3DModelUploadService;
use TriCab\Core\Product\Application\Service\CompleteProduct3DPreviewUploadService;
use TriCab\Core\Product\Application\Service\GetProduct3DSpecificationSourceService;
use TriCab\Core\Product\Application\Service\InitiateProduct3DModelUploadService;
use TriCab\Core\Product\Application\Service\InitiateProduct3DPreviewUploadService;
use TriCab\Core\Product\Application\Service\ProcessProduct3DAssetService;
use TriCab\Core\Product\Application\Service\ManageProduct3DModelService;
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
use TriCab\Core\Product\Contract\ProductPageContentRepository;
use TriCab\Core\Product\Contract\ProductPageMediaLayoutRepository;
use TriCab\Core\Product\Contract\ProductThumbnailPresentationRepository;
use TriCab\Core\Product\Contract\ProductSpecificationRepository;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Contract\RegionRepository;
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
        $prompts = new MarkdownPromptRepository(dirname(__DIR__, 2) . '/PROMPTS');

        $definitions = [
            Settings::class => $settings,
            JsonResponder::class => \DI\create(JsonResponder::class),

            // Tri Expense Stage 2/3/4 — memory defaults; azure-sql branch overrides persistence below.
            \TriCab\Core\Expense\Contract\ExpenseReceiptRepository::class => \DI\autowire(\TriCab\Api\Expense\Persistence\InMemoryExpenseReceiptRepository::class),
            \TriCab\Core\Expense\Contract\ExpenseProfileRepository::class => \DI\autowire(\TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository::class),
            \TriCab\Core\Expense\Contract\ExpenseBankFeedRepository::class => \DI\autowire(\TriCab\Api\Expense\Persistence\InMemoryExpenseBankFeedRepository::class),
            \TriCab\Core\Expense\Contract\ExpensePeriodRepository::class => \DI\autowire(\TriCab\Api\Expense\Persistence\InMemoryExpensePeriodRepository::class),
            \TriCab\Core\Expense\Contract\ExpenseReminderLogRepository::class => \DI\autowire(\TriCab\Api\Expense\Persistence\InMemoryExpenseReminderLogRepository::class),

            // RFQ. In-memory by default; the azure-sql branch below reads the
            // store's tables (plan 2.4). Empty rather than invented either way.
            // Branch membership has no source (owner question Q7), so it answers
            // "not available".
            \TriCab\Core\Rfq\Contract\RfqRepository::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository::class),
            \TriCab\Core\Rfq\Contract\RfqBranchDirectory::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqBranchDirectory::class),
            \TriCab\Core\Rfq\Contract\RfqDraftStore::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqDraftStore::class),
            // Parity plan E6: the workflow panel's writes and its owner picker.
            \TriCab\Core\Rfq\Contract\RfqWorkflowStore::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqWorkflowStore::class),
            \TriCab\Core\Rfq\Contract\RfqPeopleDirectory::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqPeopleDirectory::class),
            // Parity plan E7.3: Sync to 4js. The ledger is the draft store's; the gateway is
            // deliberately unwired in every environment (no live 4js adapter exists), and the
            // whole path is off unless RFQ_SYNC_ENABLED is exactly `true`.
            \TriCab\Core\Rfq\Contract\RfqSyncStore::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqSyncStore::class),
            \TriCab\Core\Rfq\Contract\RfqQuoteGateway::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnwiredRfqQuoteGateway::class),
            \TriCab\Core\Rfq\Application\Service\RfqSyncService::class => \DI\autowire()
                ->constructorParameter('margins', \DI\get(\TriCab\Core\Rfq\Contract\RfqDefaultMargins::class))
                ->constructorParameter('enabled', $settings->rfqSyncEnabled),
            // The parser: loopback HTTP when RFQ_PARSER_ENABLED and a token are set,
            // otherwise a client that refuses every parse as `unavailable`.
            \TriCab\Core\Rfq\Contract\RfqParserClient::class => static function () use ($settings): \TriCab\Core\Rfq\Contract\RfqParserClient {
                if (!$settings->isRfqParserEnabled()) {
                    return new \TriCab\Core\Rfq\Infrastructure\UndeployedRfqParserClient();
                }

                return new \TriCab\Api\Rfq\Parser\HttpRfqParserClient(
                    new StreamJsonHttpClient(
                        (\TriCab\Core\Rfq\Parser\RfqServiceLimits::INTERACTIVE_PARSE_DEADLINE_MS + \TriCab\Core\Rfq\Parser\RfqServiceLimits::TRANSPORT_GRACE_MS) / 1000,
                        2.0,
                    ),
                    $settings->rfqParserBaseUrl,
                    $settings->rfqParserInternalToken,
                );
            },
            \TriCab\Core\Rfq\Contract\RfqReaderJobStore::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqReaderJobStore::class),
            \TriCab\Core\Rfq\Contract\RfqDocumentStore::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqDocumentStore::class),
            // Finance mail (F1.4). In memory by default, so tests and a database-less run
            // leave a ledger and send nothing; the azure-sql branch below swaps in the
            // tables. The transport is Graph only when FINANCE_MAIL_ENABLED is true and
            // the finance Graph registration is complete; otherwise it refuses, and the
            // ledger records a failed send without a network call.
            \TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry::class
                => \DI\autowire(\TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry::class),
            \TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class
                => \DI\autowire(\TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger::class),
            \TriCab\Core\Finance\Shared\Mail\FinanceMailTransport::class
                => static function () use ($settings): \TriCab\Core\Finance\Shared\Mail\FinanceMailTransport {
                    if (!$settings->isFinanceMailSendingEnabled()) {
                        return new \TriCab\Api\Finance\Mail\DisabledFinanceMailTransport();
                    }

                    return new \TriCab\Api\Finance\Mail\GraphFinanceMailTransport(
                        new StreamJsonHttpClient(20.0, 5.0),
                        $settings->financeGraphTenantId,
                        $settings->financeGraphClientId,
                        $settings->financeGraphClientSecret,
                        $settings->financeMailTestRecipientOrNull(),
                    );
                },
            \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class
                => \DI\autowire(\TriCab\Core\Finance\Shared\Mail\FinanceMailService::class),
            // Mailbox reading (F1.11): the platform's reader, shared with RFQ, and finance's rules
            // in front of it. Graph only when FINANCE_MAILBOX_READ_ENABLED is true AND the finance
            // Graph registration is complete; otherwise every read refuses and touches no network.
            \TriCab\Core\Shared\Mailbox\MailboxReader::class
                => static function () use ($settings): \TriCab\Core\Shared\Mailbox\MailboxReader {
                    if (!$settings->isFinanceMailboxReadingEnabled()) {
                        return new \TriCab\Api\Shared\Mailbox\DisabledMailboxReader();
                    }

                    return new \TriCab\Api\Shared\Mailbox\GraphMailboxReader(
                        new StreamJsonHttpClient(20.0, 5.0),
                        $settings->financeGraphTenantId,
                        $settings->financeGraphClientId,
                        $settings->financeGraphClientSecret,
                    );
                },
            \TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader::class
                => \DI\autowire(\TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService::class),
            // Odoo reference lists (F1.12). The client is real only with an https ODOO_URL, a key
            // and at least one company id; the stores are in memory here and the tables in the
            // azure-sql branch below.
            \TriCab\Core\Finance\Shared\Odoo\OdooClient::class
                => static function () use ($settings): \TriCab\Core\Finance\Shared\Odoo\OdooClient {
                    $url = $settings->odooBaseUrlOrNull();
                    if (!$settings->isOdooConfigured() || $url === null) {
                        return new \TriCab\Api\Finance\Odoo\UndeployedOdooClient();
                    }

                    return new \TriCab\Api\Finance\Odoo\HttpOdooClient(
                        new StreamJsonHttpClient(60.0, 5.0),
                        $url,
                        $settings->odooApiKey,
                        $settings->odooCompanyIdList(),
                    );
                },
            \TriCab\Core\Finance\Shared\Odoo\OdooRecordStore::class
                => \DI\autowire(\TriCab\Api\Finance\Odoo\InMemoryOdooRecordStore::class),
            \TriCab\Core\Finance\Shared\Odoo\OdooSyncRuns::class
                => \DI\autowire(\TriCab\Api\Finance\Odoo\InMemoryOdooSyncRuns::class),
            \TriCab\Core\Finance\Shared\Odoo\OdooSyncConfig::class
                => static fn (): \TriCab\Core\Finance\Shared\Odoo\OdooSyncConfig => new \TriCab\Core\Finance\Shared\Odoo\OdooSyncConfig(
                    $settings->odooCompanyIdList(),
                    $settings->odooApiKeyExpires,
                ),
            \TriCab\Core\Finance\Shared\Odoo\OdooSyncService::class
                => \DI\autowire(\TriCab\Core\Finance\Shared\Odoo\OdooSyncService::class),
            // Finance files (F1.9). In memory by default; the azure-sql branch below swaps in
            // the table and the private `BLOB_CONTAINER_FINANCE` container. There is no route
            // here: each app serves its own files through the service, in its own phase.
            \TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore::class
                => \DI\autowire(\TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore::class),
            \TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService::class
                => \DI\autowire(\TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService::class),
            // Finance scopes and the portal user map (F1.10). In memory by default (nobody is
            // scoped, nobody is mapped); the azure-sql branch below swaps in the tables and
            // the identity lookup the mapping script uses.
            \TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader::class
                => \DI\autowire(\TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader::class),
            \TriCab\Core\Finance\Shared\Access\FinancePortalUserMap::class
                => \DI\autowire(\TriCab\Api\Finance\Access\InMemoryFinancePortalUserMap::class),
            \TriCab\Core\Finance\Shared\Access\FinancePlatformUserLookup::class
                => \DI\autowire(\TriCab\Api\Finance\Access\InMemoryFinancePlatformUserLookup::class),
            \TriCab\Core\Finance\Shared\Access\FinancePortalUserMatcher::class
                => \DI\autowire(\TriCab\Core\Finance\Shared\Access\FinancePortalUserMatcher::class),
            // Finance jobs and audit (F1.8). In memory by default; the azure-sql branch below
            // swaps in the tables. No job is registered in Phase 1: each app's phase adds
            // its own to the list below, in this one place.
            \TriCab\Core\Finance\Shared\Audit\FinanceAuditLog::class
                => \DI\autowire(\TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog::class),
            \TriCab\Core\Finance\Shared\Jobs\FinanceJobClaims::class
                => \DI\autowire(\TriCab\Api\Finance\Jobs\InMemoryFinanceJobClaims::class),
            \TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry::class
                => static function (Container $container) use ($settings): \TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry {
                    $jobs = [];
                    // Odoo reference lists (F1.12): once a day, and only where Odoo is configured.
                    // An unconfigured run would fail every night and fill the audit trail.
                    if ($settings->isOdooConfigured()) {
                        $jobs[] = new \TriCab\Core\Finance\Shared\Odoo\OdooSyncJob(
                            self::get($container, \TriCab\Core\Finance\Shared\Odoo\OdooSyncService::class),
                        );
                    }

                    // Tri-Task's 08:00 summary (T2.9): the registry's first app job, only while Tri-Task is on.
                    if ($settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_TASK)) {
                        $jobs[] = self::get($container, \TriCab\Core\Finance\Task\Service\TaskDailySummaryJob::class);
                        // Each preparer's own 08:00 email (12 Oct 2026), behind its own switch so it starts when the team is told.
                        if ($settings->financeTaskPreparerDigestEnabled) {
                            $jobs[] = self::get($container, \TriCab\Core\Finance\Task\Service\TaskPreparerDigestJob::class);
                        }
                    }

                    // Tri-AR and Tri-AP keep files (T5.5, T6.5): uploads never put on a record are swept once a day. One job
                    // sweeps every app's uploads, so it is registered once, whichever of the two is on.
                    if ($settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_AR) || $settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_AP)) {
                        $jobs[] = new \TriCab\Core\Finance\Shared\Attachments\FinanceUnattachedAttachmentsJob(
                            self::get($container, \TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore::class),
                        );
                    }

                    // Tri-AR's 08:00 mailbox scan (T5.7, D44): needs the app and mailbox reading, so a half-configured host never fails nightly.
                    if ($settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_AR) && $settings->isFinanceMailboxReadingEnabled()) {
                        $jobs[] = self::get($container, \TriCab\Core\Finance\Ar\Service\ArMailboxScanJob::class);
                    }

                    // Tri-AR's overdue reminders (T5.11, D44, D61): they are mail, so the app, its own sending switch and a working
                    // mail service must all be on; otherwise every morning would leave suppressed rows or fail.
                    if (
                        $settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_AR)
                        && $settings->financeArSendingEnabled
                        && $settings->isFinanceMailSendingEnabled()
                    ) {
                        $jobs[] = self::get($container, \TriCab\Core\Finance\Ar\Service\ArOverdueRemindersJob::class);
                    }

                    return new \TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry($jobs);
                },
            \TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner::class
                => \DI\autowire(\TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner::class),
            // Finance AI (F1.5): the loopback client when FINANCE_AI_ENABLED is true with a
            // token; otherwise one that refuses every call as `unavailable`.
            \TriCab\Core\Finance\Shared\Ai\FinanceAiClient::class
                => static function () use ($settings): \TriCab\Core\Finance\Shared\Ai\FinanceAiClient {
                    if (!$settings->isFinanceAiEnabled()) {
                        return new \TriCab\Api\Finance\Ai\UndeployedFinanceAiClient();
                    }

                    return new \TriCab\Api\Finance\Ai\HttpFinanceAiClient(
                        new StreamJsonHttpClient(
                            // The longest deadline any purpose asks for (Tri-Task's statement areas take up to 90 s, Tri-AR's remittance reading 120 s) plus the grace.
                            (max(\TriCab\Core\Finance\Shared\Ai\FinanceAiContract::DEFAULT_DEADLINE_MS, \TriCab\Core\Finance\Task\Service\TaskAiService::LONG_DEADLINE_MS, \TriCab\Core\Finance\Ar\Service\ArAiService::longestDeadlineMs()) + \TriCab\Core\Finance\Shared\Ai\FinanceAiContract::TRANSPORT_GRACE_MS) / 1000,
                            2.0,
                        ),
                        $settings->financeAiBaseUrl,
                        $settings->financeAiInternalToken,
                    );
                },
            // Tri-Task (Phase 2). Everything is in memory by default; the azure-sql branch below
            // swaps in the `finance.task_*` tables and the identity-backed member directory. The
            // routes are registered only when FINANCE_TASK_ENABLED is on (AppBuilder).
            \TriCab\Core\Finance\Task\Service\TaskTime::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Service\TaskTime
                    => new \TriCab\Core\Finance\Task\Service\TaskTime(
                        self::get($container, Clock::class),
                        $settings->financeTaskTimezone,
                    ),
            \TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory::class
                => \DI\autowire(\TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory::class),
            \TriCab\Core\Finance\Task\Service\TaskMembersService::class
                => \DI\autowire(\TriCab\Core\Finance\Task\Service\TaskMembersService::class),
            // One in-memory object serves both task contracts, so the project, area and entity counts see the tasks.
            \TriCab\Api\Finance\Task\InMemoryTaskStore::class
                => \DI\autowire(\TriCab\Api\Finance\Task\InMemoryTaskStore::class),
            \TriCab\Core\Finance\Task\Contract\TaskSetupStore::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Contract\TaskSetupStore
                    => self::get($container, \TriCab\Api\Finance\Task\InMemoryTaskStore::class),
            \TriCab\Core\Finance\Task\Contract\TaskStore::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Contract\TaskStore
                    => self::get($container, \TriCab\Api\Finance\Task\InMemoryTaskStore::class),
            // The project board's groups and project templates (13 Oct 2026); the azure-sql branch swaps in the tables.
            \TriCab\Core\Finance\Task\Contract\TaskBoardStore::class
                => \DI\autowire(\TriCab\Api\Finance\Task\InMemoryTaskBoardStore::class),
            \TriCab\Core\Finance\Task\Service\TaskService::class
                => \DI\autowire(\TriCab\Core\Finance\Task\Service\TaskService::class)
                    ->constructorParameter('board', \DI\get(\TriCab\Core\Finance\Task\Contract\TaskBoardStore::class)),
            \TriCab\Core\Finance\Task\Service\TaskBoardService::class
                => \DI\autowire(\TriCab\Core\Finance\Task\Service\TaskBoardService::class),
            // Task mail (T2.6): the links to the shared ledger and the daily-summary recipients.
            \TriCab\Core\Finance\Task\Contract\TaskMailLinks::class
                => \DI\autowire(\TriCab\Api\Finance\Task\InMemoryTaskMailLinks::class),
            \TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore::class
                => \DI\autowire(\TriCab\Api\Finance\Task\InMemoryTaskReportRecipientStore::class),
            // Report recipients and the daily summary job (T2.9). The job is registered below, with the others,
            // when FINANCE_TASK_ENABLED is on.
            \TriCab\Core\Finance\Task\Service\TaskReportRecipientService::class
                => \DI\autowire(\TriCab\Core\Finance\Task\Service\TaskReportRecipientService::class),
            \TriCab\Core\Finance\Task\Service\TaskDailySummaryJob::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Service\TaskDailySummaryJob
                    => new \TriCab\Core\Finance\Task\Service\TaskDailySummaryJob(
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskStore::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskMailLinks::class),
                        self::get($container, \TriCab\Core\Finance\Task\Service\TaskTime::class),
                        $settings->financeTaskMailFrom,
                        $settings->financeTaskTimezone,
                    ),
            \TriCab\Core\Finance\Task\Service\TaskDueDateRequestNotifier::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Service\TaskDueDateRequestNotifier
                    => new \TriCab\Core\Finance\Task\Service\TaskDueDateRequestNotifier(
                        self::get($container, \TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskMailLinks::class),
                        self::get($container, \TriCab\Core\Finance\Task\Service\TaskTime::class),
                        $settings->financeTaskMailFrom,
                    ),
            \TriCab\Core\Finance\Task\Service\TaskReviewRequestNotifier::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Service\TaskReviewRequestNotifier
                    => new \TriCab\Core\Finance\Task\Service\TaskReviewRequestNotifier(
                        self::get($container, \TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskMailLinks::class),
                        self::get($container, \TriCab\Core\Finance\Task\Service\TaskTime::class),
                        $settings->financeTaskMailFrom,
                        $settings->financeTaskSendAsUser,
                    ),
            \TriCab\Core\Finance\Task\Service\TaskPreparerDigestJob::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Service\TaskPreparerDigestJob
                    => new \TriCab\Core\Finance\Task\Service\TaskPreparerDigestJob(
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskStore::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskMailLinks::class),
                        self::get($container, \TriCab\Core\Finance\Task\Service\TaskTime::class),
                        $settings->financeTaskMailFrom,
                        $settings->financeTaskTimezone,
                    ),
            // The spreadsheet import (T2.8): CSV and Excel readers (openspout; Excel needs the zip extension).
            \TriCab\Core\Finance\Task\Contract\SpreadsheetReader::class
                => static fn (): \TriCab\Core\Finance\Task\Contract\SpreadsheetReader
                    => new \TriCab\Api\Finance\Task\FormatSpreadsheetReader(
                        new \TriCab\Api\Finance\Task\CsvSpreadsheetReader(),
                        new \TriCab\Api\Finance\Task\OpenSpoutXlsxReader(),
                    ),
            \TriCab\Core\Finance\Task\Service\TaskImportService::class
                => \DI\autowire(\TriCab\Core\Finance\Task\Service\TaskImportService::class),
            // The five drafting routes over finance_ai (T2.7). The service client is the shared one below.
            \TriCab\Core\Finance\Task\Service\TaskAiService::class
                => \DI\autowire(\TriCab\Core\Finance\Task\Service\TaskAiService::class),
            \TriCab\Core\Finance\Task\Service\TaskMailService::class
                => static fn (Container $container): \TriCab\Core\Finance\Task\Service\TaskMailService
                    => new \TriCab\Core\Finance\Task\Service\TaskMailService(
                        self::get($container, \TriCab\Core\Finance\Task\Service\TaskService::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader::class),
                        self::get($container, \TriCab\Core\Finance\Task\Contract\TaskMailLinks::class),
                        self::get($container, \TriCab\Core\Finance\Task\Service\TaskTime::class),
                        $settings->financeTaskMailFrom,
                        $settings->financeTaskSendAsUser,
                    ),
            \TriCab\Core\Finance\Task\Service\TaskSetupService::class
                => \DI\autowire(\TriCab\Core\Finance\Task\Service\TaskSetupService::class),
            // Tri-AR (Phase 5). Everything is in memory by default; the azure-sql branch below swaps in
            // the `finance.ar_*` tables and the identity-backed assignee directory. The routes are
            // registered only when FINANCE_AR_ENABLED is on (AppBuilder).
            \TriCab\Core\Finance\Ar\Service\ArTime::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArTime
                    => new \TriCab\Core\Finance\Ar\Service\ArTime(
                        self::get($container, Clock::class),
                        $settings->financeArTimezone,
                    ),
            \TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory::class
                => \DI\autowire(\TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory::class),
            // One in-memory object serves both the option lists and the records, so a usage count sees the records.
            \TriCab\Api\Finance\Ar\InMemoryArStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ar\InMemoryArStore::class),
            \TriCab\Core\Finance\Ar\Contract\ArOptionStore::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Contract\ArOptionStore
                    => self::get($container, \TriCab\Api\Finance\Ar\InMemoryArStore::class),
            \TriCab\Core\Finance\Ar\Contract\ArRecordStore::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Contract\ArRecordStore
                    => self::get($container, \TriCab\Api\Finance\Ar\InMemoryArStore::class),
            \TriCab\Core\Finance\Ar\Service\ArOptionService::class
                => \DI\autowire(\TriCab\Core\Finance\Ar\Service\ArOptionService::class),
            \TriCab\Core\Finance\Ar\Service\ArRecordService::class
                => \DI\autowire(\TriCab\Core\Finance\Ar\Service\ArRecordService::class),
            // The four model calls (T5.8) and the reply composer's draft, over the shared finance_ai client.
            \TriCab\Core\Finance\Ar\Service\ArAiService::class
                => \DI\autowire(\TriCab\Core\Finance\Ar\Service\ArAiService::class),
            \TriCab\Core\Finance\Ar\Service\ArDraftReplyService::class
                => \DI\autowire(\TriCab\Core\Finance\Ar\Service\ArDraftReplyService::class),
            // The original message (T5.6): read through the finance mailbox rules, the record's own mailbox first.
            \TriCab\Core\Finance\Ar\Service\ArOriginalMessageService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArOriginalMessageService
                    => new \TriCab\Core\Finance\Ar\Service\ArOriginalMessageService(
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArRecordStore::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader::class),
                        $settings->financeArMailbox,
                    ),
            // Files on records (T5.5): the guard answers for the file store, the service keeps and serves.
            \TriCab\Core\Finance\Ar\Service\ArRecordGuard::class
                => \DI\autowire(\TriCab\Core\Finance\Ar\Service\ArRecordGuard::class),
            \TriCab\Core\Finance\Ar\Service\ArAttachmentService::class
                => \DI\autowire(\TriCab\Core\Finance\Ar\Service\ArAttachmentService::class),
            // The reply composer (T5.9): sent replies are links to the shared mail ledger, kept with their body. The same
            // in-memory object serves the record and outbound contracts, so a permanent delete removes the rows.
            \TriCab\Core\Finance\Ar\Contract\ArOutboundStore::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Contract\ArOutboundStore
                    => self::get($container, \TriCab\Api\Finance\Ar\InMemoryArStore::class),
            \TriCab\Core\Finance\Ar\Service\ArMailService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArMailService
                    => new \TriCab\Core\Finance\Ar\Service\ArMailService(
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArRecordStore::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArOutboundStore::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArTime::class),
                        $settings->financeArMailbox,
                        $settings->financeArSendingEnabled,
                    ),
            // Credit-note requests and the public form (T5.10). The same in-memory object serves the record and request contracts.
            \TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore
                    => self::get($container, \TriCab\Api\Finance\Ar\InMemoryArStore::class),
            \TriCab\Core\Finance\Ar\Contract\ArRateLimiter::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Contract\ArRateLimiter
                    => new \TriCab\Api\Finance\Ar\CacheArRateLimiter(self::get($container, ApplicationCache::class)),
            \TriCab\Core\Finance\Ar\Service\ArCreditNoteService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArCreditNoteService
                    => new \TriCab\Core\Finance\Ar\Service\ArCreditNoteService(
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArRecordStore::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArTime::class),
                        $settings->financeArMailbox,
                        $settings->financeArPublicOrigin,
                        $settings->financeArFormLinkHours,
                        $settings->financeArSendingEnabled,
                    ),
            \TriCab\Core\Finance\Ar\Service\ArPublicFormService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArPublicFormService
                    => new \TriCab\Core\Finance\Ar\Service\ArPublicFormService(
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArRecordStore::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArRateLimiter::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArTime::class),
                        $settings->financeArMailbox,
                        $settings->financeArSendingEnabled,
                    ),
            // Scanning the mailbox (T5.7): the runs and their claim, the service, and the daily job (registered below,
            // only when the app and mailbox reading are both on).
            \TriCab\Core\Finance\Ar\Contract\ArScanStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ar\InMemoryArScanStore::class),
            \TriCab\Core\Finance\Ar\Service\ArScanService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArScanService
                    => new \TriCab\Core\Finance\Ar\Service\ArScanService(
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArRecordStore::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArScanStore::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArAiService::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArAttachmentService::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArTime::class),
                        $settings->financeArMailbox,
                    ),
            \TriCab\Core\Finance\Ar\Service\ArMailboxScanJob::class
                => \DI\autowire(\TriCab\Core\Finance\Ar\Service\ArMailboxScanJob::class),
            // The overdue reminders (T5.11): sent from the AR mailbox, registered below only when sending is on.
            \TriCab\Core\Finance\Ar\Service\ArReminderService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArReminderService
                    => new \TriCab\Core\Finance\Ar\Service\ArReminderService(
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArRecordStore::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArTime::class),
                        $settings->financeArMailbox,
                    ),
            \TriCab\Core\Finance\Ar\Service\ArOverdueRemindersJob::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArOverdueRemindersJob
                    => new \TriCab\Core\Finance\Ar\Service\ArOverdueRemindersJob(
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArReminderService::class),
                        $settings->financeArTimezone,
                    ),
            \TriCab\Core\Finance\Ar\Service\ArMeService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ar\Service\ArMeService
                    => new \TriCab\Core\Finance\Ar\Service\ArMeService(
                        self::get($container, \TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Ar\Service\ArTime::class),
                        $settings->financeArMailbox,
                        $settings->financeArCreditNotesUrl,
                        $settings->financeArSendingEnabled,
                    ),
            // Tri-AP (Phase 6). In memory by default; the azure-sql branch below swaps in the `finance.invoice_*`
            // tables. The routes are registered only when FINANCE_AP_ENABLED is on (AppBuilder).
            \TriCab\Core\Finance\Ap\Service\ApTime::class
                => static fn (Container $container): \TriCab\Core\Finance\Ap\Service\ApTime
                    => new \TriCab\Core\Finance\Ap\Service\ApTime(
                        self::get($container, Clock::class),
                        $settings->financeApTimezone,
                    ),
            \TriCab\Core\Finance\Ap\Contract\ApReferenceStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApReferenceStore::class),
            \TriCab\Core\Finance\Ap\Contract\ApSigningLimitStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApSigningLimitStore::class),
            \TriCab\Core\Finance\Ap\Service\ApReferenceService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApReferenceService::class),
            // Tri-AP: vendors and extraction (T6.8, T6.9). The model is the shared `FinanceAiClient` (undeployed until the AI service
            // is on); the vendors are an empty in-memory list until the azure-sql branch reads `finance.odoo_records`.
            \TriCab\Core\Finance\Ap\ApAiService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ap\ApAiService
                    => new \TriCab\Core\Finance\Ap\ApAiService(
                        self::get($container, \TriCab\Core\Finance\Shared\Ai\FinanceAiClient::class),
                        $settings->financeApSuggestReplies,
                        $settings->financeApConfidenceThreshold,
                    ),
            \TriCab\Core\Finance\Ap\Contract\ApVendorStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApVendorStore::class),
            \TriCab\Core\Finance\Ap\Service\ApVendorService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApVendorService::class),
            \TriCab\Core\Finance\Ap\Service\ApInvoiceVendorService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApInvoiceVendorService::class),
            \TriCab\Core\Finance\Ap\Service\ApExtractService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApExtractService::class),
            // Tri-AP: scan (T6.7). No job: a person runs it (Q6).
            \TriCab\Core\Finance\Ap\Contract\ApScanStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApScanStore::class),
            \TriCab\Core\Finance\Ap\Service\ApScanService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApScanService::class)
                    ->constructorParameter('minAttachmentBytes', $settings->financeApMinAttachmentBytes)
                    ->constructorParameter('maxScanMessages', $settings->financeApMaxScanMessages)
                    ->constructorParameter('internalDomains', $settings->financeApInternalEmailDomains),
            // The register and its files (T6.4, T6.5), and the audit trail every later Tri-AP task writes to.
            \TriCab\Core\Finance\Ap\Contract\ApInvoiceStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApInvoiceStore::class),
            \TriCab\Core\Finance\Ap\Contract\ApAuditStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApAuditStore::class),
            \TriCab\Core\Finance\Ap\Service\ApInvoiceGuard::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApInvoiceGuard::class),
            \TriCab\Core\Finance\Ap\Service\ApAttachmentService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApAttachmentService::class),
            \TriCab\Core\Finance\Ap\Service\ApInvoiceService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApInvoiceService::class)
                    ->constructorParameter('confidenceThreshold', $settings->financeApConfidenceThreshold)
                    ->constructorParameter('vendors', \DI\get(\TriCab\Core\Finance\Ap\Service\ApVendorService::class)),
            \TriCab\Core\Finance\Ap\Service\ApInvoiceDownloadService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApInvoiceDownloadService::class),
            \TriCab\Core\Finance\Ap\Service\ApMailboxService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApMailboxService::class),
            \TriCab\Core\Finance\Ap\Service\ApSigningAuthorityService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApSigningAuthorityService::class),
            \TriCab\Core\Finance\Ap\Service\ApMeService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ap\Service\ApMeService
                    => new \TriCab\Core\Finance\Ap\Service\ApMeService($settings->financeApSendingEnabled),
            // Tri-AP: AP Mailbox (T6.6)
            \TriCab\Core\Finance\Ap\Contract\ApEmailStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApEmailStore::class),
            \TriCab\Core\Finance\Ap\Service\ApEmailService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApEmailService::class),
            \TriCab\Core\Finance\Ap\Service\ApMessageService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApMessageService::class),
            // Tri-AP: mail (T6.10). The sent-mail links are in memory here and `finance.invoice_outbound_messages` in the azure-sql
            // branch below. The sending switch is FINANCE_AP_SENDING_ENABLED (decision D79) and the default
            // send-from address is FINANCE_AP_MAILBOX (the source's `AP_MAILBOX`).
            \TriCab\Core\Finance\Ap\Contract\ApOutboundStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApOutboundStore::class),
            \TriCab\Core\Finance\Ap\Service\ApMailService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ap\Service\ApMailService
                    => new \TriCab\Core\Finance\Ap\Service\ApMailService(
                        self::get($container, \TriCab\Core\Finance\Ap\Contract\ApInvoiceStore::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Service\ApEmailService::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Service\ApAttachmentService::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Contract\ApOutboundStore::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Contract\ApAuditStore::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailSender::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class),
                        self::get($container, \TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Service\ApTime::class),
                        $settings->financeApMailbox,
                        $settings->financeApSendingEnabled,
                    ),
            \TriCab\Core\Finance\Ap\Service\ApDraftMailService::class
                => \DI\autowire(\TriCab\Core\Finance\Ap\Service\ApDraftMailService::class),
            // Tri-AP: approvals (T6.11). The batches are in memory here and `finance.invoice_approval_*` in the azure-sql branch below;
            // the approvers are nobody until the azure-sql branch reads `[identity]`. The link in the mail is built on the public origin
            // of the web app (`PASSKEY_PUBLIC_APP_BASE_URL`): `Settings` has no Tri-AP origin of its own yet.
            \TriCab\Core\Finance\Ap\Contract\ApApprovalStore::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApApprovalStore::class),
            \TriCab\Core\Finance\Ap\Contract\ApApproverDirectory::class
                => \DI\autowire(\TriCab\Api\Finance\Ap\InMemoryApApproverDirectory::class),
            \TriCab\Core\Finance\Ap\Service\ApApprovalService::class
                => static fn (Container $container): \TriCab\Core\Finance\Ap\Service\ApApprovalService
                    => new \TriCab\Core\Finance\Ap\Service\ApApprovalService(
                        self::get($container, \TriCab\Core\Finance\Ap\Contract\ApApprovalStore::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Contract\ApInvoiceStore::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Contract\ApApproverDirectory::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Contract\ApAuditStore::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Service\ApMailService::class),
                        self::get($container, \TriCab\Core\Finance\Ap\Service\ApTime::class),
                        $settings->financeApPublicOrigin !== '' ? $settings->financeApPublicOrigin : $settings->passkeyPublicAppBaseUrl,
                    ),
            // Parity plan E5.5: correction memory and the review log (azure-sql below).
            \TriCab\Core\Rfq\Contract\RfqCorrectionMemory::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqCorrectionMemory::class),
            // Plans 3.3/3.5: uploads and the background reader. Its own parser client,
            // because a read may take the background deadline (600 s) and the shared
            // client's socket gives up after the interactive one.
            \TriCab\Core\Rfq\Application\Service\RfqReaderService::class => static function (Container $container) use ($settings): \TriCab\Core\Rfq\Application\Service\RfqReaderService {
                $parser = $settings->isRfqParserEnabled()
                    ? new \TriCab\Api\Rfq\Parser\HttpRfqParserClient(
                        new StreamJsonHttpClient(
                            (\TriCab\Core\Rfq\Parser\RfqServiceLimits::BACKGROUND_READ_DEADLINE_MS + \TriCab\Core\Rfq\Parser\RfqServiceLimits::TRANSPORT_GRACE_MS) / 1000,
                            2.0,
                        ),
                        $settings->rfqParserBaseUrl,
                        $settings->rfqParserInternalToken,
                    )
                    : new \TriCab\Core\Rfq\Infrastructure\UndeployedRfqParserClient();

                return new \TriCab\Core\Rfq\Application\Service\RfqReaderService(
                    $parser,
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqDraftStore::class),
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqReaderJobStore::class),
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqDocumentStore::class),
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqRepository::class),
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqOperatorDirectory::class),
                    self::get($container, Clock::class),
                    $settings->rfqParserAllowModel,
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqCorrectionMemory::class),
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqCustomerDirectory::class),
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqReaderWake::class),
                );
            },
            // An upload that queues a read wakes the reader WebJob, whose idle passes
            // then leave the serverless database alone (bin/run-rfq-reader-jobs.php).
            \TriCab\Core\Rfq\Contract\RfqReaderWake::class
                => static fn (): \TriCab\Api\Rfq\Reader\FileRfqReaderWake => \TriCab\Api\Rfq\Reader\FileRfqReaderWake::fromSettings($settings),
            // Parity plan D3: Match Text's answer is sealed under a key derived from the
            // parser token, so there is no seal (and no parse) without the parser.
            \TriCab\Core\Rfq\Application\Service\RfqReviewService::class => \DI\autowire()
                ->constructorParameter('seal', $settings->isRfqParserEnabled()
                    ? new \TriCab\Core\Rfq\Parser\ParseSeal($settings->rfqParserInternalToken)
                    : null)
                ->constructorParameter('allowModel', $settings->rfqParserAllowModel)
                ->constructorParameter('memory', \DI\get(\TriCab\Core\Rfq\Contract\RfqCorrectionMemory::class))
                ->constructorParameter('accessories', \DI\get(\TriCab\Core\Rfq\Application\Service\RfqAccessoryMatcher::class)),
            // Parity plan E7.2: Match Accessories. The engine is tc-web's rules over the legacy `tc`
            // database, which is not ported: until it is, the engine answers "not available", as the source does.
            \TriCab\Core\Rfq\Contract\RfqAccessoryEngine::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnavailableRfqAccessoryEngine::class),
            \TriCab\Core\Rfq\Application\Service\RfqAccessoryMatcher::class => static fn (Container $container): \TriCab\Core\Rfq\Application\Service\RfqAccessoryMatcher
                => new \TriCab\Core\Rfq\Application\Service\RfqAccessoryMatcher(
                    self::get($container, \TriCab\Core\Rfq\Contract\RfqAccessoryEngine::class),
                    $settings->isRfqParserEnabled() ? new \TriCab\Core\Rfq\Parser\AccessorySeal($settings->rfqParserInternalToken) : null,
                ),
            // Parity plan E1: the quote header's lookups. Customers and contacts come
            // from the synced customer book (the azure-sql branch below); pay terms
            // from the legacy `descript` table, read only and cached for an hour.
            \TriCab\Core\Rfq\Contract\RfqCustomerDirectory::class
                => \DI\autowire(\TriCab\Core\Rfq\Infrastructure\UnconfiguredRfqCustomerDirectory::class),
            \TriCab\Core\Rfq\Contract\RfqPayTermsDirectory::class => static function (Container $container): \TriCab\Core\Rfq\Contract\RfqPayTermsDirectory {
                try {
                    return new \TriCab\Api\Rfq\Legacy\DescriptRfqPayTermsDirectory(
                        self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class),
                        self::get($container, ApplicationCache::class),
                    );
                } catch (\Throwable) {
                    return new \TriCab\Core\Rfq\Infrastructure\UnavailableRfqPayTermsDirectory();
                }
            },
            // Parity plan E5.3: the customer's default margin per product, read only from the
            // legacy mirror; without it lines keep the margin they have.
            \TriCab\Core\Rfq\Contract\RfqDefaultMargins::class => static function (Container $container): \TriCab\Core\Rfq\Contract\RfqDefaultMargins {
                try {
                    return new \TriCab\Api\Rfq\Legacy\LegacyRfqDefaultMargins(
                        self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class),
                        self::get($container, ApplicationCache::class),
                    );
                } catch (\Throwable) {
                    return new \TriCab\Core\Rfq\Infrastructure\UnavailableRfqDefaultMargins();
                }
            },
            \TriCab\Core\Rfq\Application\Service\RfqLookupService::class => \DI\autowire(\TriCab\Core\Rfq\Application\Service\RfqLookupService::class)
                ->constructorParameter('defaultPayTermsCode', $settings->rfqDefaultPayTermsCode),
            // The rep's 4js operator code, for the quote's author (SalesRepAttribution).
            // Read only; falls back to "nobody confirmed" when the legacy database is absent.
            \TriCab\Core\Rfq\Contract\RfqOperatorDirectory::class => static function (Container $container) use ($settings): \TriCab\Core\Rfq\Contract\RfqOperatorDirectory {
                try {
                    return new \TriCab\Api\Rfq\Legacy\DescriptRfqOperatorDirectory(
                        self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class),
                        $settings->usesAzureSqlUserRepository() ? self::get($container, SqlConnection::class) : null,
                    );
                } catch (\Throwable) {
                    return new \TriCab\Core\Rfq\Infrastructure\UnavailableRfqOperatorDirectory();
                }
            },

            // Onboarding (Phase 3.1–3.3). In-memory by default, as Expense is:
            // the azure-sql branch below swaps in the real adapters. The doubles
            // are not merely test scaffolding — they are what `USER_REPOSITORY=memory`
            // runs against, so they enforce the same ownership keying the SQL does.
            \TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingSelfServiceRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingTrainingRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPolicyRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingFormRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingWriteIdempotencyStore::class),
            // Phase 4 — HR administration.
            \TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog::class),
            \TriCab\Core\Onboarding\Contract\OnboardingReviewRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReviewRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAccountDirectory::class),
            \TriCab\Core\Onboarding\Contract\OnboardingContentRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingContentRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingReportRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReportRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingReminderRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReminderRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingRetentionRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingRetentionRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormContributionRepository::class),
            \TriCab\Core\Onboarding\Contract\OnboardingCompletionFeedRepository::class
                => \DI\autowire(\TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingCompletionFeedRepository::class),
            // Optional constructor parameters are not autowired, so these two are
            // named: without them a form's HR and manager parts would be neither
            // shown to the starter nor required before a reviewer accepts it.
            \TriCab\Core\Onboarding\Application\Service\DecideReviewService::class
                => \DI\autowire()
                    ->constructorParameter('contributions', \DI\get(\TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository::class))
                    ->constructorParameter('documents', \DI\get(\TriCab\Core\Onboarding\Application\Service\GenerateFormSubmissionDocumentService::class))
                    ->constructorParameter('assessmentSheets', \DI\get(\TriCab\Core\Onboarding\Application\Service\GenerateAssessmentDocumentService::class)),
            \TriCab\Core\Onboarding\Application\Service\GenerateFormSubmissionDocumentService::class
                => \DI\autowire()->constructorParameter('contributions', \DI\get(\TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository::class)),
            \TriCab\Core\Onboarding\Application\Service\ContributeToFormService::class
                => \DI\autowire()->constructorParameter('documents', \DI\get(\TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository::class)),
            \TriCab\Core\Onboarding\Application\Service\ReadOnboardingItemService::class
                => \DI\autowire()->constructorParameter('contributions', \DI\get(\TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository::class)),
            // Phase 5.4 — generated documents. The in-memory repository writes
            // into the in-memory read side, so a generated copy shows on the list.
            \TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository::class
                => static function (Container $container): \TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository {
                    $read = self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository::class);

                    return new \TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentGenerationRepository(
                        $read instanceof \TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingDocumentRepository ? $read : null,
                    );
                },
            \TriCab\Core\Onboarding\Contract\OnboardingFormDocumentRenderer::class
                => static function (Container $container): \TriCab\Core\Onboarding\Contract\OnboardingFormDocumentRenderer {
                    // Photos are read back from the private container for the
                    // vehicle report's copy — found through the person's own
                    // documents, so only that person's photos can be drawn.
                    $documents = self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository::class);
                    $blobs = self::get($container, ProductBlobStore::class);

                    return \TriCab\Api\Onboarding\Document\TemplateOnboardingFormDocumentRenderer::withTriCabTemplates(
                        dirname(__DIR__, 2),
                        static function (int $userId, string $documentPublicId) use ($documents, $blobs): ?string {
                            $photo = $documents->findDocument($userId, strtolower($documentPublicId));
                            if ($photo === null || $photo->documentKind !== 'photo') {
                                return null;
                            }

                            return $blobs->getObjectBinary($photo->blobContainer, $photo->blobPath);
                        },
                    );
                },
            \TriCab\Core\Onboarding\Contract\OnboardingDocumentStore::class
                => static fn (Container $container): \TriCab\Core\Onboarding\Contract\OnboardingDocumentStore
                    => new \TriCab\Api\Onboarding\Document\BlobOnboardingDocumentStore(
                        self::get($container, ProductBlobStore::class),
                        $settings->blobContainerOnboarding,
                    ),
            \TriCab\Core\Onboarding\Contract\OnboardingAssessmentDocumentRenderer::class
                => static fn (): \TriCab\Core\Onboarding\Contract\OnboardingAssessmentDocumentRenderer
                    => new \TriCab\Api\Onboarding\Document\AssessmentPdfMapper(dirname(__DIR__, 2) . '/resources/onboarding/form-templates'),
            \TriCab\Core\Onboarding\Application\Service\SubmitAssessmentAttemptService::class
                => static fn (Container $container): \TriCab\Core\Onboarding\Application\Service\SubmitAssessmentAttemptService
                    => new \TriCab\Core\Onboarding\Application\Service\SubmitAssessmentAttemptService(
                        self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\OnboardingSubmissionGate::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\OnboardingIdempotentWrite::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\GenerateAssessmentDocumentService::class),
                    ),
            \TriCab\Core\Onboarding\Contract\OnboardingPolicyDocumentRenderer::class
                => static fn (): \TriCab\Core\Onboarding\Contract\OnboardingPolicyDocumentRenderer
                    => \TriCab\Api\Onboarding\Document\PolicyDeclarationPdfMapper::withTriCabTemplates(dirname(__DIR__, 2)),
            \TriCab\Core\Onboarding\Application\Service\AcknowledgePolicyService::class
                => static fn (Container $container): \TriCab\Core\Onboarding\Application\Service\AcknowledgePolicyService
                    => new \TriCab\Core\Onboarding\Application\Service\AcknowledgePolicyService(
                        self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\OnboardingSubmissionGate::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\OnboardingIdempotentWrite::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\GeneratePolicyDocumentService::class),
                    ),
            \TriCab\Core\Onboarding\Application\Service\SubmitFormService::class
                => static fn (Container $container): \TriCab\Core\Onboarding\Application\Service\SubmitFormService
                    => new \TriCab\Core\Onboarding\Application\Service\SubmitFormService(
                        self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingFormRepository::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\OnboardingSubmissionGate::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\OnboardingIdempotentWrite::class),
                        self::get($container, \TriCab\Core\Onboarding\Application\Service\GenerateFormSubmissionDocumentService::class),
                    ),
            \TriCab\Core\Expense\Contract\ExpensePdfRenderer::class => \DI\autowire(\TriCab\Api\Expense\Persistence\ExpenseDompdfRenderer::class),
            \TriCab\Core\Expense\Contract\ExpenseGraphFinanceGroupChecker::class => \DI\autowire(\TriCab\Api\Expense\Persistence\NullExpenseGraphFinanceGroupChecker::class),
            // Profile-only by design: ExpenseProfile.IsFinance is authoritative at
            // request time and the Graph Tricab-Finance group is diagnostic/sync
            // only. See ExpenseAccessService for the full rationale.
            \TriCab\Core\Expense\Contract\ExpenseFinanceCapabilityResolver::class => \DI\autowire(\TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver::class),
            \TriCab\Core\Expense\Contract\ExpenseManagerResolver::class => \DI\autowire(\TriCab\Api\Expense\Persistence\ProfileManagerResolver::class),
            \TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery::class => \DI\autowire(\TriCab\Api\Expense\Persistence\InMemoryExpenseProfileAdminQuery::class),
            \TriCab\Core\Expense\Contract\ExpenseNotificationPort::class => static function (Container $container) use ($settings): \TriCab\Core\Expense\Contract\ExpenseNotificationPort {
                return self::createExpenseNotificationPort($settings, $container);
            },
            \TriCab\Core\Expense\Contract\ExpenseAuditRecorder::class => \DI\autowire(\TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder::class),
            \TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort::class => static function (Container $container) use ($settings): \TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort {
                return self::createExpenseCaptureAssistPort($settings, $container);
            },
            \TriCab\Api\Expense\Email\ExpenseEmailTemplates::class => static function () use ($settings): \TriCab\Api\Expense\Email\ExpenseEmailTemplates {
                return new \TriCab\Api\Expense\Email\ExpenseEmailTemplates(
                    $settings->triexpenseAppBaseUrl !== '' ? $settings->triexpenseAppBaseUrl : null,
                );
            },
            \TriCab\Api\Expense\Observability\ExpenseEventLogger::class => \DI\create(\TriCab\Api\Expense\Observability\ExpenseEventLogger::class),
            \TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags::class => static function () use ($settings): \TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags {
                return new \TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags(
                    ocr: $settings->isTriexpenseOcrConfigured(),
                    emailNotifications: $settings->isTriexpenseEmailSendingEnabled(),
                    legacyRead: $settings->triexpenseLegacyReadEnabled,
                );
            },
            \TriCab\Core\Expense\Application\Service\LegacyExpenseIdentityResolver::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\LegacyExpenseIdentityResolver::class),
            \TriCab\Core\Expense\Application\Service\LegacyExpenseReceiptAssembler::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\LegacyExpenseReceiptAssembler::class),
            \TriCab\Core\Expense\Contract\LegacyExpenseReadRepository::class => static function (Container $container) use ($settings): \TriCab\Core\Expense\Contract\LegacyExpenseReadRepository {
                if (!$settings->triexpenseLegacyReadEnabled) {
                    return new \TriCab\Api\Expense\Legacy\NullLegacyExpenseReadRepository();
                }
                try {
                    $connection = self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class);
                } catch (\Throwable) {
                    return new \TriCab\Api\Expense\Legacy\NullLegacyExpenseReadRepository();
                }

                return new \TriCab\Api\Expense\Legacy\MysqlLegacyExpenseReadRepository(
                    $connection,
                    new \TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard(),
                );
            },
            \TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository::class => static function (Container $container) use ($settings): \TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository {
                if (!$settings->triexpenseLegacyReadEnabled) {
                    return new \TriCab\Api\Expense\Legacy\NullLegacyExpenseFinanceReadRepository();
                }
                try {
                    $connection = self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class);
                } catch (\Throwable) {
                    return new \TriCab\Api\Expense\Legacy\NullLegacyExpenseFinanceReadRepository();
                }

                return new \TriCab\Api\Expense\Legacy\MysqlLegacyExpenseFinanceReadRepository(
                    $connection,
                    new \TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard(),
                );
            },
            \TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository::class => static function (Container $container) use ($settings): \TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository {
                if (!$settings->triexpenseLegacyReadEnabled) {
                    return new \TriCab\Api\Expense\Legacy\NullLegacyExpenseFinanceBankReadRepository();
                }
                try {
                    $connection = self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class);
                } catch (\Throwable) {
                    return new \TriCab\Api\Expense\Legacy\NullLegacyExpenseFinanceBankReadRepository();
                }

                return new \TriCab\Api\Expense\Legacy\MysqlLegacyExpenseFinanceBankReadRepository(
                    $connection,
                    new \TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard(),
                );
            },
            \TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceReceiptAssembler::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceReceiptAssembler::class),
            \TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceBankAssembler::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\LegacyExpenseFinanceBankAssembler::class),
            \TriCab\Core\Expense\Application\Service\LegacyExpenseFinancePeriodAssembler::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\LegacyExpenseFinancePeriodAssembler::class),
            // Cost-centre selector options: same legacy dept+office source as
            // te_get_cost_centre_options(). Available whenever LEGACY_TC can connect;
            // independent of TRIEXPENSE_LEGACY_READ_ENABLED (receipt dual-read).
            \TriCab\Core\Expense\Contract\ExpenseCostCentreOptionsPort::class => static function (Container $container): \TriCab\Core\Expense\Contract\ExpenseCostCentreOptionsPort {
                try {
                    $connection = self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class);
                } catch (\Throwable) {
                    return new \TriCab\Api\Expense\Legacy\UnavailableExpenseCostCentreOptionsPort();
                }

                return new \TriCab\Api\Expense\Legacy\MysqlLegacyExpenseCostCentreOptionsPort(
                    $connection,
                    new \TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard(),
                );
            },
            \TriCab\Core\Expense\Contract\ExpenseAttachmentStore::class => \DI\autowire(\TriCab\Api\Expense\Persistence\AzureBlobExpenseAttachmentStore::class),
            \TriCab\Api\Http\Auth\ExpenseErrorMapper::class => \DI\autowire(\TriCab\Api\Http\Auth\ExpenseErrorMapper::class),
            \TriCab\Core\Expense\Application\ExpensePermissionMode::class => static fn () => \TriCab\Core\Expense\Application\ExpensePermissionMode::fromString($settings->triexpensePermissionMode),
            \TriCab\Core\Expense\Application\Service\ExpenseAccessService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ExpenseAccessService::class)
                ->constructorParameter('permissionMode', \DI\get(\TriCab\Core\Expense\Application\ExpensePermissionMode::class)),
            \TriCab\Core\Expense\Application\Service\ExpenseReceiptAssembler::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ExpenseReceiptAssembler::class),
            \TriCab\Core\Expense\Application\Service\CreateExpenseReceiptService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\CreateExpenseReceiptService::class),
            \TriCab\Core\Expense\Application\Service\UpdateExpenseReceiptService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\UpdateExpenseReceiptService::class),
            \TriCab\Core\Expense\Application\Service\SubmitExpenseReceiptService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\SubmitExpenseReceiptService::class),
            \TriCab\Core\Expense\Application\Service\ExpenseApprovalService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ExpenseApprovalService::class),
            \TriCab\Core\Expense\Application\Service\ListMyExpenseReceiptsService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListMyExpenseReceiptsService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\GetExpenseReceiptService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseReceiptService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\ListExpenseApprovalsService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListExpenseApprovalsService::class),
            \TriCab\Core\Expense\Application\Service\SoftDeleteExpenseReceiptService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\SoftDeleteExpenseReceiptService::class),
            \TriCab\Core\Expense\Application\Service\RestoreExpenseReceiptService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\RestoreExpenseReceiptService::class),
            \TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService::class),
            \TriCab\Core\Expense\Application\Service\CheckExpenseDuplicateService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\CheckExpenseDuplicateService::class),
            \TriCab\Core\Expense\Application\Service\GetExpenseConfigService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseConfigService::class)
                ->constructorParameter('features', \DI\get(\TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags::class))
                ->constructorParameter('managers', \DI\get(\TriCab\Core\Expense\Contract\ExpenseManagerResolver::class))
                ->constructorParameter('users', \DI\get(\TriCab\Core\Identity\Contract\UserRepository::class)),
            \TriCab\Core\Expense\Application\Service\ListExpenseProfilesAdminService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListExpenseProfilesAdminService::class),
            \TriCab\Core\Expense\Application\Service\UpdateExpenseProfileApproverService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\UpdateExpenseProfileApproverService::class),
            \TriCab\Core\Expense\Application\Service\LinkLegacyExpenseApproversService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\LinkLegacyExpenseApproversService::class),
            \TriCab\Core\Expense\Application\Service\ListExpenseAdminUsersService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListExpenseAdminUsersService::class),
            \TriCab\Core\Expense\Application\Service\SetExpenseUserTeRolesService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\SetExpenseUserTeRolesService::class),
            \TriCab\Core\Expense\Application\Service\SearchExpenseAdminEmployeesService::class => static function (Container $container): \TriCab\Core\Expense\Application\Service\SearchExpenseAdminEmployeesService {
                $tel = null;
                try {
                    $reader = self::get($container, \TriCab\Core\TelDirectory\Contract\TelDirectoryGraphReader::class);
                    if ($reader->isConfigured()) {
                        $tel = $reader;
                    }
                } catch (\Throwable) {
                    $tel = null;
                }

                return new \TriCab\Core\Expense\Application\Service\SearchExpenseAdminEmployeesService(
                    self::get($container, \TriCab\Core\Expense\Application\Service\ExpenseAccessService::class),
                    self::get($container, UserManagementRepository::class),
                    self::get($container, \TriCab\Core\Expense\Contract\ExpenseProfileRepository::class),
                    $tel,
                );
            },
            \TriCab\Core\Expense\Application\Service\GetExpenseAdminOverviewService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseAdminOverviewService::class),
            \TriCab\Core\Expense\Application\Service\DryCheckExpenseLegacyApproverMappingService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\DryCheckExpenseLegacyApproverMappingService::class),
            \TriCab\Core\Expense\Contract\ExpenseLegacyTeUserDirectoryPort::class => static function (Container $container): \TriCab\Core\Expense\Contract\ExpenseLegacyTeUserDirectoryPort {
                try {
                    $connection = self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class);
                } catch (\Throwable) {
                    return new \TriCab\Api\Expense\Persistence\UnavailableExpenseLegacyTeUserDirectoryPort();
                }

                return new \TriCab\Api\Expense\Legacy\MysqlLegacyExpenseTeUserDirectoryPort(
                    $connection,
                    new \TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard(),
                );
            },
            \TriCab\Core\Expense\Application\Service\RunExpenseReceiptOcrService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\RunExpenseReceiptOcrService::class),
            \TriCab\Core\Expense\Application\Service\ExtractExpenseReceiptDraftService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ExtractExpenseReceiptDraftService::class),
            \TriCab\Core\Expense\Application\Service\MonthlyManagerDigestService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\MonthlyManagerDigestService::class),
            \TriCab\Core\Expense\Application\Service\InitiateExpenseAttachmentService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\InitiateExpenseAttachmentService::class),
            \TriCab\Core\Expense\Application\Service\CompleteExpenseAttachmentService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\CompleteExpenseAttachmentService::class),
            \TriCab\Core\Expense\Application\Service\GetExpenseAttachmentFileService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseAttachmentFileService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\SearchExpenseCustomersService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\SearchExpenseCustomersService::class),
            \TriCab\Core\Expense\Application\Service\GetExpenseCustomerContactsService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseCustomerContactsService::class),
            \TriCab\Core\Expense\Application\Service\ImportExpenseBankFeedService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ImportExpenseBankFeedService::class),
            \TriCab\Core\Expense\Application\Service\GetExpenseBankFeedService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseBankFeedService::class),
            \TriCab\Core\Expense\Application\Service\ListExpenseBankFeedCandidatesService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListExpenseBankFeedCandidatesService::class),
            \TriCab\Core\Expense\Application\Service\RunExpenseBankFeedMatchingService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\RunExpenseBankFeedMatchingService::class),
            \TriCab\Core\Expense\Application\Service\ManualMatchExpenseBankFeedService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ManualMatchExpenseBankFeedService::class),
            \TriCab\Core\Expense\Application\Service\ResolveExpenseBankFeedService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ResolveExpenseBankFeedService::class),
            \TriCab\Core\Expense\Application\Service\OffsetExpenseBankFeedService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\OffsetExpenseBankFeedService::class),
            \TriCab\Core\Expense\Application\Service\UndoOffsetExpenseBankFeedService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\UndoOffsetExpenseBankFeedService::class),
            \TriCab\Core\Expense\Application\Service\ResolveExpenseReceiptFinanceService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ResolveExpenseReceiptFinanceService::class),
            \TriCab\Core\Expense\Application\Service\ListExpensePeriodsService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListExpensePeriodsService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\ListExpenseFinanceBankTransactionsService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListExpenseFinanceBankTransactionsService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\GetExpenseFinanceBankTransactionService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseFinanceBankTransactionService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\GetOrCreateExpensePeriodService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetOrCreateExpensePeriodService::class),
            \TriCab\Core\Expense\Application\Service\PreCloseExpensePeriodService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\PreCloseExpensePeriodService::class),
            \TriCab\Core\Expense\Application\Service\CloseExpensePeriodService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\CloseExpensePeriodService::class),
            \TriCab\Core\Expense\Application\Service\GetExpenseReconciliationService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseReconciliationService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\GetExpenseFinanceWorkspaceService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseFinanceWorkspaceService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\GetExpenseFinanceEmployeeService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\GetExpenseFinanceEmployeeService::class)
                ->constructorParameter('legacyReadEnabled', $settings->triexpenseLegacyReadEnabled),
            \TriCab\Core\Expense\Application\Service\PrepareMissingReceiptRemindersService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\PrepareMissingReceiptRemindersService::class),
            \TriCab\Core\Expense\Application\Service\PrepareManagerReminderService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\PrepareManagerReminderService::class),
            \TriCab\Core\Expense\Application\Service\ListExpenseAuditService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ListExpenseAuditService::class),
            \TriCab\Core\Expense\Application\Service\ExportEmployeeReceiptsCsvService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ExportEmployeeReceiptsCsvService::class),
            \TriCab\Core\Expense\Application\Service\ExportFinanceReconciliationCsvService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\ExportFinanceReconciliationCsvService::class),
            \TriCab\Core\Expense\Application\Service\RenderFinanceSummaryPdfService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\RenderFinanceSummaryPdfService::class),
            \TriCab\Core\Expense\Application\Service\RenderFinanceFullPdfService::class => \DI\autowire(\TriCab\Core\Expense\Application\Service\RenderFinanceFullPdfService::class),
            \TriCab\Core\Expense\Domain\Service\ExpenseAuBankFeedCsvParser::class => \DI\autowire(\TriCab\Core\Expense\Domain\Service\ExpenseAuBankFeedCsvParser::class),
            \TriCab\Core\Expense\Domain\Service\ExpenseNzBankFeedCsvParser::class => \DI\autowire(\TriCab\Core\Expense\Domain\Service\ExpenseNzBankFeedCsvParser::class),
            \TriCab\Core\Expense\Domain\Service\ExpenseBankFeedMatcher::class => \DI\autowire(\TriCab\Core\Expense\Domain\Service\ExpenseBankFeedMatcher::class),
            \TriCab\Core\Expense\Domain\Service\ExpenseNearMissDiagnostics::class => \DI\autowire(\TriCab\Core\Expense\Domain\Service\ExpenseNearMissDiagnostics::class),

            \TriCab\Core\LogNote\Contract\LogNoteRepository::class => \DI\autowire(\TriCab\Api\LogNote\Persistence\InMemoryLogNoteRepository::class),
            \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository::class => \DI\autowire(\TriCab\Api\CustomerContactReport\Persistence\InMemoryCustomerContactReportRepository::class),
            \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory::class => \DI\autowire(\TriCab\Api\CustomerContactReport\Identity\IdentityCustomerContactReportAssigneeDirectory::class),
            \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine::class => static function (\DI\Container $container) use ($settings): \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine {
                $staff = $container->get(\TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory::class);
                if (!$staff instanceof \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportAssigneeDirectory) {
                    throw new \RuntimeException('Customer Contact staff directory is not available.');
                }

                return new \TriCab\Api\CustomerContactReport\Identity\GraphCustomerContactReportReportingLine(
                    new \TriCab\Api\Infrastructure\Http\StreamJsonHttpClient(15.0),
                    $settings->graphTenantId,
                    $settings->graphClientId,
                    $settings->graphClientSecret,
                    $staff,
                );
            },
            \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotificationSettings::class => \DI\factory(static function () use ($settings): \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotificationSettings {
                return new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportNotificationSettings(
                    webBaseUrl: $settings->customerContactWebBaseUrl,
                    coordinatorLogin: $settings->customerContactCoordinatorLogin,
                    accountsMailbox: $settings->customerContactAccountsMailbox,
                );
            }),
            \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportNotifier::class => static function () use ($settings): \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportNotifier {
                if (!$settings->customerContactEmailEnabled || trim($settings->customerContactMailFrom) === '') {
                    return new \TriCab\Api\CustomerContactReport\Email\InMemoryCustomerContactReportNotifier();
                }

                return new \TriCab\Api\CustomerContactReport\Email\GraphCustomerContactReportNotifier(
                    new \TriCab\Api\Infrastructure\Http\StreamJsonHttpClient(15.0),
                    new \TriCab\Api\CustomerContactReport\Email\CustomerContactReportEmailTemplate(),
                    $settings->graphTenantId,
                    $settings->graphClientId,
                    $settings->graphClientSecret,
                    $settings->customerContactMailFrom,
                    true,
                );
            },
            \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin::class => \DI\factory(static function (\TriCab\Api\Config\Settings $settings): \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin {
                return new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin($settings->customerContactAccountsLogin);
            }),
            \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore::class => static function (\DI\Container $container) use ($settings): \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore {
                if ($settings->usesMemoryProductBlobStore()) {
                    return new \TriCab\Api\CustomerContactReport\Storage\InMemoryCustomerContactReportFileStore();
                }
                $blobs = $container->get(\TriCab\Core\Product\Contract\ProductBlobStore::class);
                if (!$blobs instanceof \TriCab\Core\Product\Contract\ProductBlobStore) {
                    throw new \RuntimeException('Product blob storage is not available.');
                }

                return new \TriCab\Api\CustomerContactReport\Storage\ProductBackedCustomerContactReportFileStore(
                    $blobs,
                    $settings->blobContainerTriexpense,
                );
            },
            \TriCab\Core\LogNote\Contract\LogNoteAuditRecorder::class => \DI\autowire(\TriCab\Api\LogNote\Persistence\InMemoryLogNoteAuditRecorder::class),
            \TriCab\Core\LogNote\Contract\CustomerNoteExternalSync::class => \DI\autowire(\TriCab\Api\LogNote\Persistence\DisabledCustomerNoteExternalSync::class),
            \TriCab\Api\LogNote\Infrastructure\DeterministicLogNoteSummariser::class => \DI\autowire(\TriCab\Api\LogNote\Infrastructure\DeterministicLogNoteSummariser::class),
            \TriCab\Core\LogNote\Contract\LogNoteSummariser::class => static function (Container $container) use ($settings): \TriCab\Core\LogNote\Contract\LogNoteSummariser {
                $fallback = self::get($container, \TriCab\Api\LogNote\Infrastructure\DeterministicLogNoteSummariser::class);
                if (!$settings->isFoundryConfigured()) {
                    return $fallback;
                }

                $http = new StreamJsonHttpClient((float) $settings->foundryTimeoutSeconds);
                $primary = new \TriCab\Api\LogNote\Infrastructure\FoundryLogNoteSummariser(
                    http: $http,
                    accessTokens: new ManagedIdentityFoundryAccessTokenProvider($http),
                    endpoint: $settings->foundryEndpoint,
                    deployment: $settings->foundryModelDeployment,
                    authMode: $settings->foundryAuthMode,
                    apiKey: $settings->foundryApiKey,
                    effort: $settings->foundryEffort,
                );

                return new \TriCab\Api\LogNote\Infrastructure\FallbackLogNoteSummariser($primary, $fallback);
            },
            \TriCab\Api\Http\Auth\LogNoteErrorMapper::class => \DI\autowire(\TriCab\Api\Http\Auth\LogNoteErrorMapper::class),
            \TriCab\Core\LogNote\Application\Service\CreateLogNoteService::class => \DI\autowire(\TriCab\Core\LogNote\Application\Service\CreateLogNoteService::class),
            \TriCab\Core\LogNote\Application\Service\ListLogNotesService::class => \DI\autowire(\TriCab\Core\LogNote\Application\Service\ListLogNotesService::class),
            \TriCab\Core\LogNote\Application\Service\GetLogNoteService::class => \DI\autowire(\TriCab\Core\LogNote\Application\Service\GetLogNoteService::class),
            \TriCab\Core\LogNote\Application\Service\SummariseLogNoteService::class => \DI\autowire(\TriCab\Core\LogNote\Application\Service\SummariseLogNoteService::class),

            AssistantQueryInterpreter::class => static function () use ($settings, $prompts): AssistantQueryInterpreter {
                unset($prompts);

                return $settings->isFoundryConfigured()
                    ? new AgentAssistantQueryInterpreter()
                    : new DeterministicAssistantQueryInterpreter();
            },
            AssistantResponseProvider::class => static function (Container $container) use ($settings, $prompts): AssistantResponseProvider {
                if (!$settings->isFoundryConfigured()) {
                    return new DeterministicAssistantResponseProvider();
                }

                $http = new StreamJsonHttpClient((float) $settings->foundryTimeoutSeconds);
                $primary = new ClaudeFoundryAgentProvider(
                    http: $http,
                    accessTokens: new ManagedIdentityFoundryAccessTokenProvider($http),
                    tools: self::get($container, AssistantToolExecutor::class),
                    endpoint: $settings->foundryEndpoint,
                    deployment: $settings->foundryModelDeployment,
                    authMode: $settings->foundryAuthMode,
                    prompt: $prompts->get('product-assistant-response', 'v8'),
                    maxToolCalls: $settings->foundryMaxToolCalls,
                    apiKey: $settings->foundryApiKey,
                    trace: self::get($container, AssistantTurnTrace::class),
                    effort: $settings->foundryEffort,
                    stream: new \TriCab\Api\Assistant\Infrastructure\Foundry\CurlFoundryStreamClient((float) $settings->foundryTimeoutSeconds),
                    events: self::get($container, AssistantTurnEvents::class),
                );

                return new FallbackAssistantResponseProvider(
                    $primary,
                    self::get($container, SearchGroundedDeterministicAssistantResponseProvider::class),
                    self::get($container, AssistantTurnTrace::class),
                );
            },
            // One per request: the service and both providers write into the same turn.
            AssistantTurnTrace::class => \DI\create(AssistantTurnTrace::class),
            // One per request, like the trace: the streaming action listens, the provider emits.
            AssistantTurnEvents::class => \DI\create(AssistantTurnEvents::class),
            InMemoryAssistantConversationRepository::class => \DI\create(InMemoryAssistantConversationRepository::class),
            AssistantConversationRepository::class => \DI\get(InMemoryAssistantConversationRepository::class),
            AssistantProductGrounder::class => \DI\autowire(AssistantProductGrounder::class),
            ProductAssistantToolExecutor::class => \DI\autowire(ProductAssistantToolExecutor::class),
            AssistantToolExecutor::class => \DI\get(ProductAssistantToolExecutor::class),
            SearchGroundedDeterministicAssistantResponseProvider::class => \DI\autowire(SearchGroundedDeterministicAssistantResponseProvider::class),
            ProductAssistantService::class => \DI\autowire(ProductAssistantService::class),
            AssistantErrorMapper::class => \DI\autowire(AssistantErrorMapper::class),
            AssistantMessageAction::class => \DI\autowire(AssistantMessageAction::class),
            EntraJwksProvider::class => \DI\autowire(EntraJwksClient::class),
            EntraJwksClient::class => \DI\autowire(EntraJwksClient::class),
            EntraJwtValidationDebugLogger::class => \DI\autowire(EntraJwtValidationDebugLogger::class),
            EntraTokenValidator::class => \DI\autowire(MicrosoftEntraJwtValidator::class),
            ResolveAuthenticatedUserHandler::class => \DI\autowire(ResolveAuthenticatedUserHandler::class),
            IdentityResolutionService::class => \DI\autowire(IdentityResolutionService::class),
            LegacyStreamParser::class => \DI\autowire(LegacyStreamParser::class),
            DompdfPdfRenderer::class => \DI\create(DompdfPdfRenderer::class)
                ->constructor($documentResources . '/templates', $documentResources . '/assets'),
            PdfRenderer::class => \DI\get(DompdfPdfRenderer::class),
            GenerateOcrPdfHandler::class => \DI\autowire(GenerateOcrPdfHandler::class),
            \TriCab\Api\QuoteOrder\DocumentGeneration\QuoteDetailsToTcquLegacyStreamMapper::class => \DI\autowire(),
            \TriCab\Api\QuoteOrder\DocumentGeneration\GenerateQuoteTemplatePdfService::class => \DI\autowire(),
            HealthAction::class => \DI\autowire(HealthAction::class),
            DatabaseHealthAction::class => \DI\autowire(DatabaseHealthAction::class),
            GetAuthenticatedUserAction::class => \DI\autowire(GetAuthenticatedUserAction::class),
            GenerateDocumentPdfAction::class => \DI\autowire(GenerateDocumentPdfAction::class),
            \TriCab\Api\Http\Action\QuoteOrder\GetQuoteTemplateDocumentAction::class => \DI\autowire(),
            DevelopmentAuthenticationMiddleware::class => \DI\autowire(DevelopmentAuthenticationMiddleware::class),
            BearerTokenAuthenticationMiddleware::class => \DI\autowire(BearerTokenAuthenticationMiddleware::class),
            CorsMiddleware::class => \DI\autowire(CorsMiddleware::class),
            JsonExceptionMiddleware::class => \DI\autowire(JsonExceptionMiddleware::class),
            RequestPerformanceMetrics::class => \DI\autowire(RequestPerformanceMetrics::class),
            RequestPerformanceMiddleware::class => \DI\autowire(RequestPerformanceMiddleware::class),
            ApplicationCache::class => static function () use ($settings): ApplicationCache {
                return ApplicationCacheFactory::create($settings);
            },
            ReferenceDataCache::class => \DI\autowire(ReferenceDataCache::class),
            ReferenceDataCacheInvalidationMiddleware::class => \DI\autowire(ReferenceDataCacheInvalidationMiddleware::class),
            AzureCliAccessTokenProvider::class => static function (Container $container) use ($settings): AzureCliAccessTokenProvider {
                return new AzureCliAccessTokenProvider(
                    azBinary: $settings->azureCliBinary,
                    metrics: self::get($container, RequestPerformanceMetrics::class),
                    cache: self::get($container, ApplicationCache::class),
                );
            },
            ManagedIdentityTokenClient::class => static function (Container $container): ManagedIdentityTokenClient {
                return new ManagedIdentityTokenClient(self::get($container, JsonHttpClient::class));
            },
            ManagedIdentitySqlAccessTokenProvider::class => static function (Container $container): ManagedIdentitySqlAccessTokenProvider {
                return new ManagedIdentitySqlAccessTokenProvider(
                    self::get($container, ManagedIdentityTokenClient::class),
                    self::get($container, ApplicationCache::class),
                    self::get($container, RequestPerformanceMetrics::class),
                );
            },
            AzureSqlPdoFactory::class => static function (Container $container) use ($settings): AzureSqlPdoFactory {
                return new AzureSqlPdoFactory(
                    $settings,
                    self::get($container, AzureCliAccessTokenProvider::class),
                    self::get($container, RequestPerformanceMetrics::class),
                    new NativePdoConnector(),
                    null,
                    self::get($container, ManagedIdentitySqlAccessTokenProvider::class),
                );
            },
            AzureSqlConnectionFactory::class => \DI\autowire(AzureSqlConnectionFactory::class),
            JsonHttpClient::class => \DI\autowire(StreamJsonHttpClient::class),
            SessionTokenHasher::class => \DI\autowire(Sha256SessionTokenHasher::class),
            Clock::class => \DI\autowire(SystemClock::class),
            GuestAuthEventLogger::class => \DI\create(GuestAuthEventLogger::class),
            GuestAuthErrorMapper::class => \DI\autowire(GuestAuthErrorMapper::class),
            GuestAuthRateLimiter::class => static function (Container $container): GuestAuthRateLimiter {
                return new WindowedGuestAuthRateLimiter(self::get($container, ApplicationCache::class));
            },
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
            GetAdminDashboardAction::class => \DI\autowire(GetAdminDashboardAction::class),
            GetAdminDashboardProductActivityAction::class => \DI\autowire(GetAdminDashboardProductActivityAction::class),
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
            ResolveAuthenticatedMarketCodesService::class => \DI\autowire(ResolveAuthenticatedMarketCodesService::class),
            \TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerCodesService::class => \DI\autowire(
                \TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerCodesService::class,
            ),
            ResolveTechnicalAccessoriesAccessService::class => \DI\autowire(ResolveTechnicalAccessoriesAccessService::class),
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
            GetRegionsService::class => \DI\autowire(GetRegionsService::class),
            ManageRegionService::class => \DI\autowire(ManageRegionService::class),
            RegionPreferenceResolver::class => \DI\autowire(RegionPreferenceResolver::class),
            PublicRegionAction::class => \DI\autowire(PublicRegionAction::class),
            PublicContentRepository::class => static function (): PublicContentRepository {
                return PublicContentRepository::fromProjectRoot(dirname(__DIR__, 2));
            },
            TechnicalContentRepository::class => static function (): TechnicalContentRepository {
                return TechnicalContentRepository::fromProjectRoot(dirname(__DIR__, 2));
            },
            TechnicalDocumentClientUrlResolver::class => \DI\autowire(TechnicalDocumentClientUrlResolver::class),
            ResolveTechnicalDocumentsQuery::class => \DI\autowire(ResolveTechnicalDocumentsQuery::class),
            ListTechnicalSectionsAction::class => \DI\autowire(ListTechnicalSectionsAction::class),
            GetTechnicalArticleAction::class => \DI\autowire(GetTechnicalArticleAction::class),
            GetTechnicalTableAction::class => \DI\autowire(GetTechnicalTableAction::class),
            GetTechnicalProductCodingAction::class => \DI\autowire(GetTechnicalProductCodingAction::class),
            ListTechnicalDocumentsAction::class => \DI\autowire(ListTechnicalDocumentsAction::class),
            GetPublicAboutAction::class => \DI\autowire(GetPublicAboutAction::class),
            GetPublicContactAction::class => \DI\autowire(GetPublicContactAction::class),
            RegionAdminAction::class => \DI\autowire(RegionAdminAction::class),
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
            GetProductRegionsAction::class => \DI\autowire(GetProductRegionsAction::class),
            AssignProductRegionsAction::class => \DI\autowire(AssignProductRegionsAction::class),
            AssignProductIndustriesAction::class => \DI\autowire(AssignProductIndustriesAction::class),
            AssignProductApplicationsAction::class => \DI\autowire(AssignProductApplicationsAction::class),
            ReplaceProductRelationshipsAction::class => \DI\autowire(ReplaceProductRelationshipsAction::class),
            GetProductSeoAction::class => \DI\autowire(GetProductSeoAction::class),
            UpdateProductSeoAction::class => \DI\autowire(UpdateProductSeoAction::class),
            UpdateProductThumbnailPresentationAction::class => \DI\autowire(UpdateProductThumbnailPresentationAction::class),
            UpdateProductPageMediaLayoutAction::class => \DI\autowire(UpdateProductPageMediaLayoutAction::class),
            UpdateProductPageContentAction::class => \DI\autowire(UpdateProductPageContentAction::class),
            ChangeProductSlugAction::class => \DI\autowire(ChangeProductSlugAction::class),
            GetProductPricingAction::class => \DI\autowire(GetProductPricingAction::class),
            CompareLegacyBaseCostAction::class => \DI\autowire(CompareLegacyBaseCostAction::class),
            VariantLegacyPricingIdentityAdminAction::class => \DI\autowire(VariantLegacyPricingIdentityAdminAction::class),
            ProductPriceListsAdminAction::class => \DI\autowire(ProductPriceListsAdminAction::class),
            SetProductPricingActiveAction::class => \DI\autowire(SetProductPricingActiveAction::class),
            UpsertProductPricingAction::class => \DI\autowire(UpsertProductPricingAction::class),
            GetPublicProductBySlugAction::class => \DI\autowire(GetPublicProductBySlugAction::class),
            GetPublicProductCustomerViewAction::class => \DI\autowire(GetPublicProductCustomerViewAction::class),
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
            ClearProductPresentationMediaRoleService::class => \DI\autowire(ClearProductPresentationMediaRoleService::class),
            ClearProductPresentationMediaRoleAction::class => \DI\autowire(ClearProductPresentationMediaRoleAction::class),
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
            ManageProduct3DModelService::class => \DI\autowire(ManageProduct3DModelService::class),
            ProcessProduct3DAssetService::class => \DI\autowire(ProcessProduct3DAssetService::class),
            Product3DAssetProcessRunner::class => static function (): Product3DAssetProcessRunner {
                return ProcOpenProduct3DAssetProcessRunner::fromEnvironment();
            },
            InitiateProduct3DModelUploadService::class => \DI\autowire(InitiateProduct3DModelUploadService::class),
            CompleteProduct3DModelUploadService::class => \DI\autowire(CompleteProduct3DModelUploadService::class),
            InitiateProduct3DPreviewUploadService::class => \DI\autowire(InitiateProduct3DPreviewUploadService::class),
            CompleteProduct3DPreviewUploadService::class => \DI\autowire(CompleteProduct3DPreviewUploadService::class),
            GetProduct3DSpecificationSourceService::class => \DI\autowire(GetProduct3DSpecificationSourceService::class),
            Product3DGenerationProvider::class => static function () use ($settings): Product3DGenerationProvider {
                if (!$settings->product3dEnabled) {
                    return new DisabledProduct3DGenerationProvider();
                }

                return match ($settings->product3dProvider) {
                    'procedural-cable' => new ProceduralCable3DGenerationProvider(),
                    'img2threejs' => new Img2ThreeJsGenerationProvider(
                        enabled: $settings->product3dGenerationEnabled,
                    ),
                    default => new DisabledProduct3DGenerationProvider(),
                };
            },
            CreateProduct360SetAction::class => \DI\autowire(CreateProduct360SetAction::class),
            Initiate360FrameUploadAction::class => \DI\autowire(Initiate360FrameUploadAction::class),
            Complete360FrameUploadAction::class => \DI\autowire(Complete360FrameUploadAction::class),
            ActivateProduct360SetAction::class => \DI\autowire(ActivateProduct360SetAction::class),
            InitiateArModelUploadAction::class => \DI\autowire(InitiateArModelUploadAction::class),
            CompleteArModelUploadAction::class => \DI\autowire(CompleteArModelUploadAction::class),
            Product3DModelsAdminAction::class => \DI\autowire(Product3DModelsAdminAction::class),
            GetProduct3DSpecificationSourceAction::class => \DI\autowire(GetProduct3DSpecificationSourceAction::class),
            AddProductDocumentService::class => \DI\autowire(AddProductDocumentService::class),
            WithdrawProductDocumentService::class => \DI\autowire(WithdrawProductDocumentService::class),
            SoftDeleteProductDocumentService::class => \DI\autowire(SoftDeleteProductDocumentService::class),
            AssignProductMarketsService::class => \DI\autowire(AssignProductMarketsService::class),
            AssignProductRegionsService::class => \DI\autowire(AssignProductRegionsService::class),
            AssignProductIndustriesService::class => \DI\autowire(AssignProductIndustriesService::class),
            AssignProductApplicationsService::class => \DI\autowire(AssignProductApplicationsService::class),
            ReplaceProductRelationshipsService::class => \DI\autowire(ReplaceProductRelationshipsService::class),
            UpdateProductSeoService::class => \DI\autowire(UpdateProductSeoService::class),
            UpdateProductThumbnailPresentationService::class => \DI\autowire(UpdateProductThumbnailPresentationService::class),
            UpdateProductPageMediaLayoutService::class => \DI\autowire(UpdateProductPageMediaLayoutService::class),
            UpdateProductPageContentService::class => \DI\autowire(UpdateProductPageContentService::class),
            ChangeProductSlugService::class => \DI\autowire(ChangeProductSlugService::class),
            UpsertProductPricingService::class => \DI\autowire(UpsertProductPricingService::class),
            GetProductPricingOverviewService::class => \DI\autowire(GetProductPricingOverviewService::class),
            GetProductPricingMatrixService::class => \DI\autowire(GetProductPricingMatrixService::class),
            GetProductBaseCostHistoryService::class => \DI\autowire(GetProductBaseCostHistoryService::class),
            CompareLegacyBaseCostService::class => \DI\autowire(CompareLegacyBaseCostService::class),
            ResolveLegacyVariantPricingIdentityService::class => \DI\autowire(ResolveLegacyVariantPricingIdentityService::class),
            ManageVariantLegacyPricingIdentityService::class => \DI\autowire(ManageVariantLegacyPricingIdentityService::class),
            AuditLegacyVariantPricingIdentityService::class => \DI\autowire(AuditLegacyVariantPricingIdentityService::class),
            LegacyPricingMaskGenerator::class => \DI\autowire(LegacyPricingMaskGenerator::class),
            SetProductDefaultPricingUnitService::class => \DI\autowire(SetProductDefaultPricingUnitService::class),
            SetProductPricingActiveService::class => \DI\autowire(SetProductPricingActiveService::class),
            ListAdminProductsService::class => \DI\autowire(ListAdminProductsService::class),
            GetAdminProductService::class => \DI\autowire(GetAdminProductService::class),
            GetAdminProductCustomerViewService::class => \DI\autowire(GetAdminProductCustomerViewService::class),
            GetPublicProductService::class => \DI\autowire(GetPublicProductService::class),
            SearchProductsService::class => \DI\autowire(SearchProductsService::class),
            SearchProductCodesService::class => \DI\autowire(SearchProductCodesService::class),

            ManageProductClassificationService::class => \DI\autowire(ManageProductClassificationService::class),
            ManageProductStyleService::class => \DI\autowire(ManageProductStyleService::class),
            ResolveEffectiveStyleResourcesService::class => \DI\autowire(ResolveEffectiveStyleResourcesService::class),
            MakeLocalCopyStyleResourceService::class => \DI\autowire(MakeLocalCopyStyleResourceService::class),
            LegacyStyleOccurrenceSource::class => \DI\autowire(MysqlLegacyStyleOccurrenceSource::class),
            DiscoverLegacyStyleInventoryService::class => \DI\autowire(DiscoverLegacyStyleInventoryService::class),
            CreateMissingProductStylesFromInventoryService::class => \DI\autowire(CreateMissingProductStylesFromInventoryService::class),
            DiscoverLegacyStyleHierarchyService::class => \DI\autowire(DiscoverLegacyStyleHierarchyService::class)
                ->constructorParameter('inventory', \DI\get(DiscoverLegacyStyleInventoryService::class)),
            ReviewLegacyStyleHierarchyEvidenceService::class => \DI\autowire(ReviewLegacyStyleHierarchyEvidenceService::class),
            DiscoverExistingProductStyleMappingService::class => \DI\autowire(DiscoverExistingProductStyleMappingService::class),
            DiscoverProductCodeStyleMappingService::class => \DI\autowire(DiscoverProductCodeStyleMappingService::class),
            DiscoverProductStyleFamilyTreeService::class => \DI\autowire(DiscoverProductStyleFamilyTreeService::class),
            DiscoverCatalogueProductStyleMappingService::class => \DI\autowire(DiscoverCatalogueProductStyleMappingService::class),
            DiscoverStyleSharedDataGeneralisationService::class => \DI\autowire(DiscoverStyleSharedDataGeneralisationService::class),
            StyleSharedDataClassifier::class => \DI\autowire(StyleSharedDataClassifier::class),
            ResolveCatalogueStyleFamilyProductsService::class => \DI\autowire(ResolveCatalogueStyleFamilyProductsService::class),
            CatalogueProductStyleEvidenceSource::class => \DI\autowire(EmptyCatalogueProductStyleEvidenceSource::class),
            ApplyProductCodeStyleMappingService::class => \DI\autowire(ApplyProductCodeStyleMappingService::class),
            ApplyApprovedStyleFamilyTreeAndMappingService::class => \DI\autowire(ApplyApprovedStyleFamilyTreeAndMappingService::class),
            ApplyApprovedCatalogueNodeProductStylesService::class => \DI\autowire(ApplyApprovedCatalogueNodeProductStylesService::class),
            CatalogueNodeEffectiveProductsService::class => \DI\autowire(CatalogueNodeEffectiveProductsService::class),
            ProductStyleMappingIdentitySource::class => \DI\autowire(EmptyProductStyleMappingIdentitySource::class),
            ProductStyleFamilyEvidenceSource::class => \DI\autowire(EmptyProductStyleFamilyEvidenceSource::class),
            StyleSharedDataEvidenceSource::class => \DI\autowire(EmptyStyleSharedDataEvidenceSource::class),
            AssignProductStyleService::class => \DI\autowire(AssignProductStyleService::class),
            EnrichPublicProductWithStyleInheritanceService::class => \DI\autowire(EnrichPublicProductWithStyleInheritanceService::class),
            InitiateStyleMediaUploadService::class => \DI\autowire(InitiateStyleMediaUploadService::class),
            CompleteStyleMediaUploadService::class => \DI\autowire(CompleteStyleMediaUploadService::class),
            DeleteStyleMediaAssetService::class => \DI\autowire(DeleteStyleMediaAssetService::class),
            ManagePriceListService::class => \DI\autowire(ManagePriceListService::class),
            VerifyLegacyPriceContextService::class => \DI\autowire(VerifyLegacyPriceContextService::class),
            ManageCatalogueNavigationService::class => \DI\autowire(ManageCatalogueNavigationService::class),
            GetPublicCatalogueService::class => \DI\autowire(GetPublicCatalogueService::class),
            SpecificationGroupService::class => \DI\autowire(SpecificationGroupService::class),
            SpecificationDefinitionService::class => \DI\autowire(SpecificationDefinitionService::class),
            SpecificationOptionService::class => \DI\autowire(SpecificationOptionService::class),
            SpecificationUnitService::class => \DI\autowire(SpecificationUnitService::class),
            ProductMigrationService::class => \DI\autowire(ProductMigrationService::class),
            LegacyIdentitySourceConfig::class => static function (Container $container): LegacyIdentitySourceConfig {
                $legacy = self::get($container, LiveLegacySourceConfig::class);
                /** @var array<string, string|null> $env */
                $env = [];
                foreach ([
                    'APP_ENV',
                    'LEGACY_MIGRATION_ALLOW_WRITE_CAPABLE_READER',
                    'LEGACY_WP_DSN', 'LEGACY_WP_HOST', 'LEGACY_WP_PORT', 'LEGACY_WP_DATABASE',
                    'LEGACY_WP_USER', 'LEGACY_WP_PASSWORD', 'LEGACY_WP_TABLE_PREFIX', 'LEGACY_WP_SSL_MODE',
                    'LEGACY_WP_READ_ONLY',
                    'LEGACY_TC_DSN', 'LEGACY_TC_HOST', 'LEGACY_TC_PORT', 'LEGACY_TC_DATABASE',
                    'LEGACY_TC_USER', 'LEGACY_TC_PASSWORD', 'LEGACY_TC_SSL_MODE',
                    'LEGACY_TC_READ_ONLY',
                ] as $key) {
                    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
                    $env[$key] = is_string($value) || is_numeric($value) ? (string) $value : null;
                }

                return LegacyIdentitySourceConfig::fromEnvironment($env);
            },
            ExistingNormalizedEmailLookup::class => static function (Container $container) use ($settings): ExistingNormalizedEmailLookup {
                if ($settings->usesAzureSqlUserRepository()) {
                    return new AzureSqlExistingNormalizedEmailLookup(self::get($container, SqlConnection::class));
                }

                return new NullExistingNormalizedEmailLookup();
            },
            LegacySourceHealthProbe::class => static function (Container $container): LegacySourceHealthProbe {
                $config = self::get($container, LegacyIdentitySourceConfig::class);
                $factory = self::get($container, ReadOnlyMysqlConnectionFactory::class);
                $wp = null;
                $tc = null;
                $wpError = null;
                $tcError = null;
                if ($config->legacy->wordpressConfigured()) {
                    try {
                        $wp = $factory->create(
                            (string) $config->legacy->wordpressDsn,
                            (string) $config->legacy->wordpressUser,
                            (string) $config->legacy->wordpressPassword,
                            'wordpress-identity',
                            $config->legacy->wordpressSslMode ?? 'required',
                        );
                    } catch (\Throwable $e) {
                        $wpError = $e;
                    }
                }
                if ($config->legacy->tcConfigured()) {
                    try {
                        $tc = $factory->create(
                            (string) $config->legacy->tcDsn,
                            (string) $config->legacy->tcUser,
                            (string) $config->legacy->tcPassword,
                            'tc-identity',
                            $config->legacy->tcSslMode ?? 'required',
                        );
                    } catch (\Throwable $e) {
                        $tcError = $e;
                    }
                }

                return new LiveLegacyIdentityHealthProbe(
                    $config,
                    new LegacyIdentitySourceHealth(),
                    $wp,
                    $tc,
                    $wpError,
                    $tcError,
                );
            },
            LegacyMigrationStore::class => static function (Container $container) use ($settings): LegacyMigrationStore {
                $mode = strtolower((string) (getenv('LEGACY_MIGRATION_STORE') ?: ($_ENV['LEGACY_MIGRATION_STORE'] ?? '')));
                if ($mode === 'memory') {
                    return new InMemoryLegacyMigrationStore();
                }
                if ($mode === 'azure-sql' || ($mode === '' && $settings->usesAzureSqlUserRepository())) {
                    if (!$settings->usesAzureSqlUserRepository()) {
                        throw new InvalidArgumentException('LEGACY_MIGRATION_STORE=azure-sql requires USER_REPOSITORY=azure-sql.');
                    }

                    return new AzureSqlLegacyMigrationStore(self::get($container, SqlConnection::class));
                }

                return new InMemoryLegacyMigrationStore();
            },
            LegacyMigrationModuleRegistry::class => static function (Container $container): LegacyMigrationModuleRegistry {
                $config = self::get($container, LegacyIdentitySourceConfig::class);
                $users = null;
                if ($config->liveExtractionConfigured()) {
                    $factory = self::get($container, ReadOnlyMysqlConnectionFactory::class);
                    $wp = $factory->create(
                        (string) $config->legacy->wordpressDsn,
                        (string) $config->legacy->wordpressUser,
                        (string) $config->legacy->wordpressPassword,
                        'wordpress-identity',
                        $config->legacy->wordpressSslMode ?? 'required',
                    );
                    $tc = $factory->create(
                        (string) $config->legacy->tcDsn,
                        (string) $config->legacy->tcUser,
                        (string) $config->legacy->tcPassword,
                        'tc-identity',
                        $config->legacy->tcSslMode ?? 'required',
                    );
                    $users = FrameworkModule::usersLive(
                        new LegacyWordPressUserExtractor(
                            $wp,
                            $tc,
                            $config,
                            new LegacyIdentitySourceHealth(),
                        ),
                        self::get($container, ExistingNormalizedEmailLookup::class),
                    );
                }

                return new DefaultModuleRegistry($users);
            },
            LegacyMigrationEngine::class => \DI\autowire(LegacyMigrationEngine::class)
                ->constructorParameter('healthProbe', \DI\get(LegacySourceHealthProbe::class)),
            MigrationBatchService::class => \DI\autowire(MigrationBatchService::class),
            MigrationSourceService::class => \DI\autowire(MigrationSourceService::class),
            MigrationValidationService::class => \DI\autowire(MigrationValidationService::class),
            MigrationConflictService::class => \DI\autowire(MigrationConflictService::class),
            MigrationExecutionService::class => \DI\autowire(MigrationExecutionService::class),
            MigrationRollbackService::class => \DI\autowire(MigrationRollbackService::class),
            MigrationReportingService::class => \DI\autowire(MigrationReportingService::class),
            MigrationAuditService::class => \DI\autowire(MigrationAuditService::class),
            MigrationStatisticsService::class => \DI\autowire(MigrationStatisticsService::class),
            MigrationSettingsService::class => \DI\autowire(MigrationSettingsService::class),
            LegacyMigrationAdminAction::class => \DI\autowire(LegacyMigrationAdminAction::class),
            LegacyStaffReadOnlyAnalysisService::class => static function (Container $container) use ($settings): LegacyStaffReadOnlyAnalysisService {
                $azure = $settings->usesAzureSqlUserRepository()
                    ? self::get($container, SqlConnection::class)
                    : null;

                return new LegacyStaffReadOnlyAnalysisService(
                    self::get($container, LegacyIdentitySourceConfig::class),
                    self::get($container, ReadOnlyMysqlConnectionFactory::class),
                    self::get($container, LegacyIdentitySourceHealth::class),
                    $settings,
                    $azure,
                );
            },
            StaffUserMigrationDryRunService::class => static function (Container $container) use ($settings): StaffUserMigrationDryRunService {
                $azure = $settings->usesAzureSqlUserRepository()
                    ? self::get($container, SqlConnection::class)
                    : null;

                return new StaffUserMigrationDryRunService(
                    self::get($container, LegacyIdentitySourceConfig::class),
                    self::get($container, ReadOnlyMysqlConnectionFactory::class),
                    self::get($container, LegacyIdentitySourceHealth::class),
                    $settings,
                    $azure,
                );
            },
            StaffMigrationPreparationService::class => static function (Container $container) use ($settings): StaffMigrationPreparationService {
                $azure = $settings->usesAzureSqlUserRepository()
                    ? self::get($container, SqlConnection::class)
                    : null;

                return new StaffMigrationPreparationService(
                    self::get($container, LegacyIdentitySourceConfig::class),
                    self::get($container, ReadOnlyMysqlConnectionFactory::class),
                    self::get($container, LegacyIdentitySourceHealth::class),
                    $settings,
                    $azure,
                );
            },
            LegacyStaffUserMappingRepository::class => static function (Container $container) use ($settings): LegacyStaffUserMappingRepository {
                if (!$settings->usesAzureSqlUserRepository()) {
                    return new DisabledLegacyStaffUserMappingRepository();
                }

                return new AzureSqlLegacyStaffUserMappingRepository(self::get($container, SqlConnection::class));
            },
            StaffIdentityUserCreator::class => static function (Container $container) use ($settings): StaffIdentityUserCreator {
                if (!$settings->usesAzureSqlUserRepository()) {
                    return new DisabledStaffIdentityUserCreator();
                }

                return new AzureSqlStaffIdentityUserCreator(
                    self::get($container, UserManagementRepository::class),
                    self::get($container, SqlConnection::class),
                );
            },
            StaffMigrationWriteUnit::class => static function (Container $container) use ($settings): StaffMigrationWriteUnit {
                if (!$settings->usesAzureSqlUserRepository()) {
                    return new DisabledStaffMigrationWriteUnit();
                }


                return new AzureSqlStaffMigrationWriteUnit(
                    self::get($container, SqlConnection::class),
                    self::get($container, StaffIdentityUserCreator::class),
                    self::get($container, LegacyStaffUserMappingRepository::class),
                );
            },
            StaffUserMigrationExecutor::class => \DI\autowire(StaffUserMigrationExecutor::class),
            StaffUserMigrationExecuteService::class => static function (Container $container) use ($settings): StaffUserMigrationExecuteService {
                $azure = $settings->usesAzureSqlUserRepository()
                    ? self::get($container, SqlConnection::class)
                    : null;

                return new StaffUserMigrationExecuteService(
                    self::get($container, StaffMigrationPreparationService::class),
                    self::get($container, StaffUserMigrationExecutor::class),
                    self::get($container, LegacyMigrationStore::class),
                    self::get($container, LegacyStaffUserMappingRepository::class),
                    $settings,
                    $azure,
                );
            },
            EntraDirectoryReader::class => static function (Container $container) use ($settings): EntraDirectoryReader {
                return new MicrosoftGraphEntraDirectoryReader(
                    self::get($container, JsonHttpClient::class),
                    $settings->graphTenantId,
                    $settings->graphClientId,
                    $settings->graphClientSecret,
                );
            },
            \TriCab\Core\TelDirectory\Contract\TelDirectoryGraphReader::class => static function (Container $container) use ($settings): \TriCab\Core\TelDirectory\Contract\TelDirectoryGraphReader {
                return new \TriCab\Api\TelDirectory\Infrastructure\MicrosoftGraphTelDirectoryReader(
                    self::get($container, JsonHttpClient::class),
                    $settings->graphTenantId,
                    $settings->graphClientId,
                    $settings->graphClientSecret,
                );
            },
            \TriCab\Core\TelDirectory\Application\Service\ListTelDirectoryService::class => \DI\autowire(
                \TriCab\Core\TelDirectory\Application\Service\ListTelDirectoryService::class,
            ),
            \TriCab\Api\Http\Action\TelDirectory\ListTelDirectoryAction::class => \DI\autowire(
                \TriCab\Api\Http\Action\TelDirectory\ListTelDirectoryAction::class,
            ),

            \TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository::class => static function (Container $container): \TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository {
                $config = self::get($container, \TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig::class);
                if (!$config->tcConfigured()) {
                    return new \TriCab\Api\PriceLookup\Persistence\InMemoryPriceLookupPriceRepository();
                }
                return new \TriCab\Api\PriceLookup\Persistence\MysqlPriceLookupPriceRepository(
                    self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class),
                );
            },
            \TriCab\Core\PriceLookup\Contract\PriceLookupFxRepository::class => static function (Container $container): \TriCab\Core\PriceLookup\Contract\PriceLookupFxRepository {
                $config = self::get($container, \TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig::class);
                if (!$config->tcConfigured()) {
                    return new \TriCab\Api\PriceLookup\Persistence\InMemoryPriceLookupFxRepository();
                }
                return new \TriCab\Api\PriceLookup\Persistence\MysqlPriceLookupFxRepository(
                    self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class),
                );
            },
            \TriCab\Core\PriceLookup\Application\Service\SearchPriceLookupService::class => \DI\autowire(
                \TriCab\Core\PriceLookup\Application\Service\SearchPriceLookupService::class,
            ),
            \TriCab\Core\PriceLookup\Application\Service\QuotePriceLookupService::class => \DI\autowire(
                \TriCab\Core\PriceLookup\Application\Service\QuotePriceLookupService::class,
            ),
            \TriCab\Core\PriceLookup\Application\Service\ListPriceLookupCodesService::class => \DI\autowire(
                \TriCab\Core\PriceLookup\Application\Service\ListPriceLookupCodesService::class,
            ),

            \TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository::class => static function (): \TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository {
                return new \TriCab\Api\PdfCatalogue\Persistence\InMemoryPdfCatalogueRepository();
            },
            \TriCab\Core\PdfCatalogue\Application\Service\ListPdfCataloguesService::class => \DI\autowire(
                \TriCab\Core\PdfCatalogue\Application\Service\ListPdfCataloguesService::class,
            ),
            \TriCab\Core\PdfCatalogue\Application\Service\GetPdfCatalogueService::class => \DI\autowire(
                \TriCab\Core\PdfCatalogue\Application\Service\GetPdfCatalogueService::class,
            ),
            \TriCab\Core\PdfCatalogue\Application\Service\GetPdfCatalogueFileService::class => static function (Container $container) use ($settings): \TriCab\Core\PdfCatalogue\Application\Service\GetPdfCatalogueFileService {
                $containerName = trim($settings->blobContainerCatalogues);
                if ($containerName === '') {
                    $containerName = \TriCab\Core\PdfCatalogue\Module::BLOB_CONTAINER;
                }

                return new \TriCab\Core\PdfCatalogue\Application\Service\GetPdfCatalogueFileService(
                    self::get($container, \TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository::class),
                    self::get($container, ProductBlobStore::class),
                    $containerName,
                    max(60, $settings->blobDownloadSasTtlSeconds),
                );
            },
            \TriCab\Api\Http\Action\PdfCatalogue\ListPdfCataloguesAction::class => \DI\autowire(
                \TriCab\Api\Http\Action\PdfCatalogue\ListPdfCataloguesAction::class,
            ),
            \TriCab\Api\Http\Action\PdfCatalogue\GetPdfCatalogueAction::class => \DI\autowire(
                \TriCab\Api\Http\Action\PdfCatalogue\GetPdfCatalogueAction::class,
            ),
            \TriCab\Api\Http\Action\PdfCatalogue\GetPdfCatalogueFileAction::class => \DI\autowire(
                \TriCab\Api\Http\Action\PdfCatalogue\GetPdfCatalogueFileAction::class,
            ),
            \TriCab\Api\QuoteOrder\Mapping\LegacyQuoteOrderMapper::class => \DI\autowire(
                \TriCab\Api\QuoteOrder\Mapping\LegacyQuoteOrderMapper::class,
            ),
            \TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository::class => static function (Container $container) use ($settings): \TriCab\Core\QuoteOrder\Contract\QuoteOrderRepository {
                if (!$settings->quotesOrdersLegacyReadEnabled) {
                    return new \TriCab\Api\QuoteOrder\Legacy\NullQuoteOrderRepository();
                }
                try {
                    $connection = self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class);
                } catch (\Throwable) {
                    return new \TriCab\Api\QuoteOrder\Legacy\NullQuoteOrderRepository();
                }

                return new \TriCab\Api\QuoteOrder\Legacy\MysqlLegacyQuoteOrderRepository(
                    $connection,
                    new \TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard(),
                    self::get($container, \TriCab\Api\QuoteOrder\Mapping\LegacyQuoteOrderMapper::class),
                );
            },
            \TriCab\Core\QuoteOrder\Application\Service\QuoteOrderAccessService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\QuoteOrderAccessService::class,
            ),
            \TriCab\Core\QuoteOrder\Application\Service\SearchQuoteOrdersService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\SearchQuoteOrdersService::class,
            )->constructorParameter('legacyReadEnabled', $settings->quotesOrdersLegacyReadEnabled),
            \TriCab\Core\QuoteOrder\Application\Service\GetQuoteService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\GetQuoteService::class,
            )->constructorParameter('legacyReadEnabled', $settings->quotesOrdersLegacyReadEnabled),
            \TriCab\Core\QuoteOrder\Application\Service\GetOrderService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\GetOrderService::class,
            )->constructorParameter('legacyReadEnabled', $settings->quotesOrdersLegacyReadEnabled),
            \TriCab\Api\Http\Auth\ResolveQuoteOrderActorService::class => \DI\autowire(
                \TriCab\Api\Http\Auth\ResolveQuoteOrderActorService::class,
            ),
            \TriCab\Api\Http\Auth\QuoteOrderErrorMapper::class => \DI\autowire(
                \TriCab\Api\Http\Auth\QuoteOrderErrorMapper::class,
            ),
            \TriCab\Api\Http\Action\QuoteOrder\ListQuoteOrdersAction::class => \DI\autowire(
                \TriCab\Api\Http\Action\QuoteOrder\ListQuoteOrdersAction::class,
            ),
            \TriCab\Api\Http\Action\QuoteOrder\GetQuoteAction::class => \DI\autowire(
                \TriCab\Api\Http\Action\QuoteOrder\GetQuoteAction::class,
            ),
            \TriCab\Api\Http\Action\QuoteOrder\GetOrderAction::class => \DI\autowire(
                \TriCab\Api\Http\Action\QuoteOrder\GetOrderAction::class,
            ),
            \TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig::class => static function () use ($settings): \TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig {
                return $settings->legacyQuoteWriteConfig();
            },
            \TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore::class => static function (Container $container) use ($settings): \TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore {
                if (!$settings->usesAzureSqlUserRepository()) {
                    return new \TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteIdempotencyStore();
                }

                return new \TriCab\Api\QuoteOrder\Persistence\AzureSqlQuoteWriteIdempotencyStore(
                    self::get($container, SqlConnection::class),
                );
            },
            \TriCab\Api\QuoteOrder\Legacy\PdoLegacyQuoteWriteConnection::class => static function () use ($settings): \TriCab\Api\QuoteOrder\Legacy\PdoLegacyQuoteWriteConnection {
                $config = $settings->legacyQuoteWriteConfig();
                if (!$config->isConfigured()) {
                    throw new \RuntimeException('Quote write connection requested without QUOTES_ORDERS_LEGACY_WRITE_* credentials.');
                }

                return new \TriCab\Api\QuoteOrder\Legacy\PdoLegacyQuoteWriteConnection($config);
            },
            \TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository::class => static function (Container $container) use ($settings): \TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository {
                $config = $settings->legacyQuoteWriteConfig();
                if (!$config->isConfigured()) {
                    return new \TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteCorrelationRepository();
                }

                return new \TriCab\Api\QuoteOrder\Legacy\PdoQuoteWriteCorrelationRepository(
                    self::get($container, \TriCab\Api\QuoteOrder\Legacy\PdoLegacyQuoteWriteConnection::class),
                    $config,
                );
            },
            \TriCab\Core\QuoteOrder\Application\Service\QuoteWriteAuditLogger::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Infrastructure\NullQuoteWriteAuditLogger::class,
            ),
            \TriCab\Core\QuoteOrder\Application\Service\QuoteWriteDryRunService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\QuoteWriteDryRunService::class,
            ),
            \TriCab\Core\QuoteOrder\Domain\Policy\QuoteValidityPolicy::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Domain\Policy\ProvisionalPlusTwoDayQuoteValidityPolicy::class,
            ),
            \TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver::class => static function (Container $container) use ($settings): \TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver {
                try {
                    $legacy = self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class);
                    $azure = $settings->usesAzureSqlUserRepository()
                        ? self::get($container, SqlConnection::class)
                        : null;

                    return new \TriCab\Api\QuoteOrder\Legacy\AzureLinkedDescriptQuoteLegacyOperatorIdResolver($legacy, $azure);
                } catch (\Throwable) {
                    return new \TriCab\Core\QuoteOrder\Infrastructure\NullQuoteLegacyOperatorIdResolver();
                }
            },
            \TriCab\Core\QuoteOrder\Contract\QuoteLineLegacyMappingResolver::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Infrastructure\SimpleCableQuoteLineLegacyMappingResolver::class,
            ),
            \TriCab\Core\QuoteOrder\Application\Service\QuoteCreateCustomerEligibilityService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\QuoteCreateCustomerEligibilityService::class,
            ),
            \TriCab\Core\QuoteOrder\Application\Service\CartQuoteDraftMapper::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\CartQuoteDraftMapper::class,
            ),
            \TriCab\Core\QuoteOrder\Application\Service\QuoteWritePreflightService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\QuoteWritePreflightService::class,
            ),
            \TriCab\Core\QuoteOrder\Application\Service\SaveQuoteService::class => \DI\autowire(
                \TriCab\Core\QuoteOrder\Application\Service\SaveQuoteService::class,
            ),
            \TriCab\Core\QuoteOrder\Contract\QuoteWriteRepository::class => static function (Container $container) use ($settings): \TriCab\Core\QuoteOrder\Contract\QuoteWriteRepository {
                // Phase 4C2: still disabled by default. No HTTP write route is registered.
                if (!$settings->quotesOrdersLegacyWriteEnabled) {
                    return new \TriCab\Core\QuoteOrder\Infrastructure\DisabledQuoteWriteRepository();
                }

                $config = $settings->legacyQuoteWriteConfig();
                $config->assertExecutable();
                (new \TriCab\Core\QuoteOrder\Live\NonProductionQuoteWriteSafety())->assertDevTableSuffixRequired($config);

                return new \TriCab\Core\QuoteOrder\Infrastructure\TransactionalLegacyQuoteWriteRepository(
                    $config,
                    self::get($container, \TriCab\Api\QuoteOrder\Legacy\PdoLegacyQuoteWriteConnection::class),
                    self::get($container, \TriCab\Core\QuoteOrder\Contract\QuoteWriteCorrelationRepository::class),
                    new \TriCab\Core\QuoteOrder\Domain\Mapping\PersistQuoteDraftMapper(),
                    self::get($container, \TriCab\Core\QuoteOrder\Application\Service\QuoteWriteAuditLogger::class),
                );
            },
            StaffEntraLinkWriteUnit::class => static function (Container $container) use ($settings): StaffEntraLinkWriteUnit {
                if (!$settings->usesAzureSqlUserRepository()) {
                    return new DisabledStaffEntraLinkWriteUnit();
                }

                return new AzureSqlStaffEntraLinkWriteUnit(self::get($container, SqlConnection::class));
            },
            StaffEntraLinkExecutor::class => \DI\autowire(StaffEntraLinkExecutor::class),
            StaffEntraLinkDryRunService::class => static function (Container $container) use ($settings): StaffEntraLinkDryRunService {
                $azure = $settings->usesAzureSqlUserRepository()
                    ? self::get($container, SqlConnection::class)
                    : null;

                return new StaffEntraLinkDryRunService(
                    self::get($container, LegacyIdentitySourceConfig::class),
                    self::get($container, ReadOnlyMysqlConnectionFactory::class),
                    self::get($container, LegacySourceHealthProbe::class),
                    $settings,
                    $azure,
                    self::get($container, EntraDirectoryReader::class),
                );
            },
            StaffEntraLinkExecuteService::class => static function (Container $container) use ($settings): StaffEntraLinkExecuteService {
                $azure = $settings->usesAzureSqlUserRepository()
                    ? self::get($container, SqlConnection::class)
                    : null;

                return new StaffEntraLinkExecuteService(
                    self::get($container, StaffEntraLinkDryRunService::class),
                    self::get($container, StaffEntraLinkExecutor::class),
                    self::get($container, LegacyMigrationStore::class),
                    $settings,
                    $azure,
                );
            },
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
                    'LEGACY_WP_READ_ONLY',
                    'LEGACY_SOURCE_WAIVE_WP',
                    'LEGACY_TC_DSN', 'LEGACY_TC_HOST', 'LEGACY_TC_PORT', 'LEGACY_TC_DATABASE',
                    'LEGACY_TC_USER', 'LEGACY_TC_PASSWORD', 'LEGACY_TC_SSL_MODE',
                    'LEGACY_TC_READ_ONLY',
                    'LEGACY_SOURCE_WAIVE_TC', 'LEGACY_FILES_ROOT', 'LEGACY_SOURCE_WAIVE_FILES',
                    'PHASE12_PILOT_APPROVED',
                ] as $key) {
                    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
                    $env[$key] = is_string($value) || is_numeric($value) ? (string) $value : null;
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
            \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class => static function (Container $container): \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection {
                $config = self::get($container, LiveLegacySourceConfig::class);
                $factory = self::get($container, ReadOnlyMysqlConnectionFactory::class);
                if (!$config->tcConfigured()) {
                    throw new InvalidArgumentException('Legacy TC source is not configured.');
                }

                return $factory->create(
                    (string) $config->tcDsn,
                    (string) $config->tcUser,
                    (string) $config->tcPassword,
                    'tc',
                    $config->tcSslMode,
                );
            },
            \TriCab\Core\Product\Application\Pricing\CustomerPricingMarginRepository::class => static function (Container $container): \TriCab\Core\Product\Application\Pricing\CustomerPricingMarginRepository {
                $config = self::get($container, LiveLegacySourceConfig::class);
                if (!$config->tcConfigured()) {
                    return new \TriCab\Core\Product\Application\Pricing\InMemoryCustomerPricingMarginRepository();
                }

                return new \TriCab\Api\Product\Pricing\MysqlLegacyCustomerMarginRepository(
                    self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class),
                );
            },
            \TriCab\Core\Product\Application\Pricing\CurrencyFxRateRepository::class => static function (Container $container): \TriCab\Core\Product\Application\Pricing\CurrencyFxRateRepository {
                $config = self::get($container, LiveLegacySourceConfig::class);
                if (!$config->tcConfigured()) {
                    return new \TriCab\Core\Product\Application\Pricing\InMemoryCurrencyFxRateRepository();
                }

                return new \TriCab\Api\Product\Pricing\MysqlLegacyCurrencyFxRepository(
                    self::get($container, \TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection::class),
                );
            },
            \TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway::class => static function (Container $container) use ($settings): \TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway {
                if (!$settings->isLegacyCostApiEnabled()) {
                    return new \TriCab\Api\Product\Pricing\DisabledLegacyCostCalculationGateway();
                }

                $http = new StreamJsonHttpClient((float) $settings->legacyCostApiTimeoutSeconds);

                return new \TriCab\Api\Product\Pricing\HttpLegacyCostCalculationGateway(
                    $http,
                    $settings->legacyCostApiUrl,
                );
            },
            \TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService::class => \DI\autowire(\TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService::class),
            \TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService::class => static function (Container $container) use ($settings): \TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService {
                return new \TriCab\Core\Product\Application\Pricing\ResolveCustomerSellPriceService(
                    catalog: self::get($container, \TriCab\Core\Product\Application\Pricing\VariantCommercialCatalogRepository::class),
                    margins: self::get($container, \TriCab\Core\Product\Application\Pricing\CustomerPricingMarginRepository::class),
                    fx: self::get($container, \TriCab\Core\Product\Application\Pricing\CurrencyFxRateRepository::class),
                    formula: new \TriCab\Core\Product\Migration\Pricing\LegacyCustomerSellFormulaContract(),
                    legacyCost: self::get($container, \TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway::class),
                    legacyCostApiEnabled: $settings->isLegacyCostApiEnabled(),
                    dbcoResolver: self::get($container, \TriCab\Core\Product\Application\Pricing\ResolvePricingDbcoService::class),
                    pricingCostSource: $settings->pricingCostSource,
                    defaultTmmktmMargin: $settings->pricingDefaultTmmktmMargin,
                );
            },
            \TriCab\Core\Product\Application\Pricing\PrepareRfqLinePricingService::class => \DI\autowire(\TriCab\Core\Product\Application\Pricing\PrepareRfqLinePricingService::class),
            \TriCab\Core\Product\Application\Pricing\PrepareCartItemPricingService::class => \DI\autowire(\TriCab\Core\Product\Application\Pricing\PrepareCartItemPricingService::class),
            \TriCab\Core\Commerce\Contract\VariantAvailabilityRepository::class => \DI\autowire(\TriCab\Api\Commerce\Persistence\UnknownVariantAvailabilityRepository::class),
            \TriCab\Core\Commerce\Application\Service\CartEffectiveCustomerResolver::class => \DI\autowire(\TriCab\Core\Commerce\Application\Service\CartEffectiveCustomerResolver::class),
            \TriCab\Core\Commerce\Application\Service\CartDtoAssembler::class => static function (Container $container): \TriCab\Core\Commerce\Application\Service\CartDtoAssembler {
                return new \TriCab\Core\Commerce\Application\Service\CartDtoAssembler(
                    self::get($container, \TriCab\Core\Commerce\Contract\CartLineMediaResolver::class),
                );
            },
            \TriCab\Core\Commerce\Application\Service\GetCartService::class => \DI\autowire(\TriCab\Core\Commerce\Application\Service\GetCartService::class),
            \TriCab\Core\Commerce\Application\Service\AddCartItemService::class => \DI\autowire(\TriCab\Core\Commerce\Application\Service\AddCartItemService::class),
            \TriCab\Core\Commerce\Application\Service\UpdateCartItemService::class => \DI\autowire(\TriCab\Core\Commerce\Application\Service\UpdateCartItemService::class),
            \TriCab\Core\Commerce\Application\Service\RemoveCartItemService::class => \DI\autowire(\TriCab\Core\Commerce\Application\Service\RemoveCartItemService::class),
            \TriCab\Core\Commerce\Application\Service\RepriceCartService::class => \DI\autowire(\TriCab\Core\Commerce\Application\Service\RepriceCartService::class),
            \TriCab\Core\Commerce\Application\Service\SwitchCartCustomerService::class => \DI\autowire(\TriCab\Core\Commerce\Application\Service\SwitchCartCustomerService::class),
            \TriCab\Api\Http\Action\PublicCart\GetCartAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicCart\GetCartAction::class),
            \TriCab\Api\Http\Action\PublicCart\PostCartItemAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicCart\PostCartItemAction::class),
            \TriCab\Api\Http\Action\PublicCart\PatchCartItemAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicCart\PatchCartItemAction::class),
            \TriCab\Api\Http\Action\PublicCart\DeleteCartItemAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicCart\DeleteCartItemAction::class),
            \TriCab\Api\Http\Action\PublicCart\PostCartRepriceAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicCart\PostCartRepriceAction::class),
            \TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec::class => \DI\factory(static function (\Psr\Container\ContainerInterface $c): \TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec {
                $settings = $c->get(\TriCab\Api\Config\Settings::class);
                $secret = $settings->guestJwtSecret !== ''
                    ? $settings->guestJwtSecret
                    : hash('sha256', 'tricab-dev-pricing-context|' . $settings->appEnv);

                return new \TriCab\Core\Product\Application\Pricing\StaffPricingContextTokenCodec($secret);
            }),
            \TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService::class => \DI\autowire(\TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService::class),
            \TriCab\Core\Product\Application\Pricing\PricingActorContextResolver::class => \DI\get(\TriCab\Core\Product\Application\Pricing\ResolvePricingActorContextService::class),
            \TriCab\Core\Product\Application\Pricing\ResolveCustomerPricingContextService::class => \DI\autowire(\TriCab\Core\Product\Application\Pricing\ResolveCustomerPricingContextService::class),
            \TriCab\Core\Product\Application\Pricing\CustomerPricingContextResolver::class => \DI\get(\TriCab\Core\Product\Application\Pricing\ResolveCustomerPricingContextService::class),
            \TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser::class => \DI\autowire(\TriCab\Api\Http\Auth\ResolveRequestAuthenticatedUser::class),
            \TriCab\Api\Http\Action\PublicProduct\PostCustomerVariantPricingBatchAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicProduct\PostCustomerVariantPricingBatchAction::class),
            \TriCab\Api\Http\Action\PublicProduct\PostPrepareRfqLinePricingAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicProduct\PostPrepareRfqLinePricingAction::class),
            \TriCab\Api\Http\Action\PublicProduct\PostPrepareCartItemPricingAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicProduct\PostPrepareCartItemPricingAction::class),
            \TriCab\Api\Http\Action\PublicProduct\PostPricingCustomerContextAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicProduct\PostPricingCustomerContextAction::class),
            \TriCab\Api\Http\Action\PublicProduct\GetPricingCustomersAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicProduct\GetPricingCustomersAction::class),
            \TriCab\Api\Http\Action\PublicProduct\GetPricingCustomerContactsAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicProduct\GetPricingCustomerContactsAction::class),
            \TriCab\Api\Http\Action\PublicProduct\PostPricingMarginOverrideAction::class => \DI\autowire(\TriCab\Api\Http\Action\PublicProduct\PostPricingMarginOverrideAction::class),
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
            DiscoverLegacyMasterDataService::class => \DI\autowire(DiscoverLegacyMasterDataService::class),
            StyleCodeNormalizer::class => \DI\autowire(StyleCodeNormalizer::class),
            BuildCatalogueMappingReadinessService::class => \DI\autowire(BuildCatalogueMappingReadinessService::class),
            \TriCab\Core\Product\Migration\Application\Service\PlanProductMigrationMasterDataService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\PlanProductMigrationMasterDataService::class),
            \TriCab\Core\Product\Migration\Application\Service\PlanBatchAClassificationService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\PlanBatchAClassificationService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchA1CategoryService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchA1CategoryService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchA2ClassificationService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchA2ClassificationService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchA3OperatorApprovalService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchA3OperatorApprovalService::class),
            \TriCab\Core\Product\Migration\Application\Service\ApplyBatchAClassificationService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ApplyBatchAClassificationService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchBSpecificationMasterService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchBSpecificationMasterService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchB1OperatorApprovalService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchB1OperatorApprovalService::class),
            \TriCab\Core\Product\Migration\Application\Service\ApplyBatchBSpecificationMasterService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ApplyBatchBSpecificationMasterService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchCStyleSpecificationMappingService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchCStyleSpecificationMappingService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchC1UnresolvedConceptsService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchC1UnresolvedConceptsService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveBatchC2OperatorApprovalService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveBatchC2OperatorApprovalService::class),
            \TriCab\Core\Product\Migration\Application\Service\ApplyBatchC2StyleFieldMappingService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ApplyBatchC2StyleFieldMappingService::class),
            \TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ApplyCableSpecBatchD4TargetApplyService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecFieldMappingService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecFieldMappingService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD2OperatorReviewService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD2OperatorReviewService::class),
            \TriCab\Core\Product\Migration\Application\Service\RecordCableSpecBatchD2OperatorDecisionsService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\RecordCableSpecBatchD2OperatorDecisionsService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD3FinalDryRunService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveCableSpecBatchD3FinalDryRunService::class),
            \TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD5ReviewService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD5ReviewService::class),
            \TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD51FinalResolutionService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\InvestigateCableSpecBatchD51FinalResolutionService::class),
            \TriCab\Core\Product\Migration\Application\Service\ResolveProductMigrationPilotDryRunService::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ResolveProductMigrationPilotDryRunService::class),
            \TriCab\Core\Product\Migration\Application\Service\ApplyProductMigrationPilotF12Service::class => \DI\autowire(\TriCab\Core\Product\Migration\Application\Service\ApplyProductMigrationPilotF12Service::class),
            \TriCab\Core\Product\Migration\Classification\LegacyTermSemanticClassifier::class => \DI\autowire(\TriCab\Core\Product\Migration\Classification\LegacyTermSemanticClassifier::class),
            \TriCab\Core\Product\Migration\Classification\ClassificationNameNormalizer::class => \DI\autowire(\TriCab\Core\Product\Migration\Classification\ClassificationNameNormalizer::class),
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
            CatalogueTabsAdminAction::class => \DI\autowire(CatalogueTabsAdminAction::class),
            CatalogueNodesAdminAction::class => \DI\autowire(CatalogueNodesAdminAction::class),
            ProductCataloguePlacementsAdminAction::class => \DI\autowire(ProductCataloguePlacementsAdminAction::class),
            GetPublicCataloguePanelAction::class => \DI\autowire(GetPublicCataloguePanelAction::class),
            GetPublicCatalogueTabsAction::class => \DI\autowire(GetPublicCatalogueTabsAction::class),
            GetPublicCatalogueTabTreeAction::class => \DI\autowire(GetPublicCatalogueTabTreeAction::class),
            GetPublicCatalogueNodeAction::class => \DI\autowire(GetPublicCatalogueNodeAction::class),
            PostCableCalculatorCalculateAction::class => \DI\autowire(PostCableCalculatorCalculateAction::class),
            FindProductAction::class => \DI\autowire(FindProductAction::class),
            ProductFinderClient::class => static function () use ($settings): ProductFinderClient {
                // Off unless a Python service is actually running. Locally that
                // is the usual case, and a null client answers the same shape
                // the HTTP one does when the service is down, so nothing
                // downstream needs a second code path.
                if (!$settings->isProductFinderEnabled()) {
                    return new NullProductFinderClient();
                }

                // Its own client, not the shared JsonHttpClient: this hop is a
                // loopback socket to a process that loads an 18,994-product
                // catalogue on first use, so it wants a longer timeout than the
                // ten seconds the shared default carries.
                return new HttpProductFinderClient(
                    new StreamJsonHttpClient((float) $settings->productFinderTimeoutSeconds),
                    $settings->productFinderBaseUrl,
                );
            },
            CalculateCableService::class => \DI\autowire(CalculateCableService::class),
            ResolveCableCalculationProductsService::class => \DI\autowire(ResolveCableCalculationProductsService::class),
            CableCalculatorProductResolver::class => static function (\Psr\Container\ContainerInterface $c) {
                // Default null: catalogue lookup must never block / crash engineering calculation
                // (paused Azure SQL + LoginTimeout can exceed max_execution_time as a fatal).
                $mode = strtolower((string) (Environment::value('CABLE_CALCULATOR_PRODUCT_RESOLVER', 'null') ?? 'null'));
                if ($mode === 'null' || $mode === 'disabled' || $mode === 'off') {
                    return new NullCableCalculatorProductResolver();
                }

                return $c->get(AzureSqlCableCalculatorProductResolver::class);
            },
            AzureSqlCableCalculatorProductResolver::class => static function (\Psr\Container\ContainerInterface $c): AzureSqlCableCalculatorProductResolver {
                // Lazy PDO: Action DI must not open Azure SQL before CalculateCableService runs.
                return new AzureSqlCableCalculatorProductResolver(
                    static function () use ($c): ?PDO {
                        try {
                            if (!$c->has(PDO::class)) {
                                return null;
                            }
                            $candidate = $c->get(PDO::class);

                            return $candidate instanceof PDO ? $candidate : null;
                        } catch (\Throwable) {
                            return null;
                        }
                    },
                );
            },
            CableCalculatorEngine::class => static function (\Psr\Container\ContainerInterface $c) {
                $mode = strtolower((string) (Environment::value('CABLE_CALCULATOR_ENGINE', 'native') ?? 'native'));
                if ($mode === 'native') {
                    return $c->get(NativeCableCalculatorEngine::class);
                }

                return $c->get(LegacyCliTriCalcEngine::class);
            },
            NativeCableCalculatorEngine::class => static function (): NativeCableCalculatorEngine {
                $configured = Environment::value('CABLE_CALCULATOR_DATASET_DIR');
                $default = dirname(__DIR__, 2) . '/resources/cable-calculator/v1';
                $datasetDir = $configured !== null && $configured !== '' ? $configured : $default;
                $resolved = realpath($datasetDir) ?: $datasetDir;

                return new NativeCableCalculatorEngine($resolved);
            },
            LegacyCliTriCalcEngine::class => static function (): LegacyCliTriCalcEngine {
                $configured = Environment::value('TRICAB_TC_WEB_CALCULATE_JSON');
                $default = dirname(__DIR__, 4) . '/tc-web/tools/legacy-cable-calculator/calculate-json.php';
                $script = $configured !== null && $configured !== '' ? $configured : $default;
                $resolved = realpath($script) ?: $script;
                $php = PHP_BINARY;

                return new LegacyCliTriCalcEngine($resolved, $php);
            },
            ProductCategoriesAdminAction::class => \DI\autowire(ProductCategoriesAdminAction::class),
            ProductSeriesAdminAction::class => \DI\autowire(ProductSeriesAdminAction::class),
            SpecificationGroupsAdminAction::class => \DI\autowire(SpecificationGroupsAdminAction::class),
            SpecificationDefinitionsAdminAction::class => \DI\autowire(SpecificationDefinitionsAdminAction::class),
            SpecificationOptionsAdminAction::class => \DI\autowire(SpecificationOptionsAdminAction::class),
            SpecificationUnitsAdminAction::class => \DI\autowire(SpecificationUnitsAdminAction::class),
            GetProductLookupsService::class => \DI\autowire(GetProductLookupsService::class),
            GetProductPublishReadinessService::class => \DI\autowire(GetProductPublishReadinessService::class),
            GetProductRegionsService::class => \DI\autowire(GetProductRegionsService::class),
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
            $definitions[SqlConnection::class] = static function (Container $container): SqlConnection {
                return new LazySqlConnection(static function () use ($container): PdoSqlConnection {
                    return new PdoSqlConnection(
                        self::get($container, PDO::class),
                        self::get($container, RequestPerformanceMetrics::class),
                    );
                });
            };
            $definitions[ApplicationTransaction::class] = \DI\autowire(SqlApplicationTransaction::class);
            $definitions[UserRepository::class] = \DI\autowire(AzureSqlUserRepository::class);
            $definitions[EmailOtpChallengeRepository::class] = \DI\autowire(AzureSqlEmailOtpChallengeRepository::class);
            $definitions[\TriCab\Core\Identity\Contract\ExpiredEmailOtpChallengePurger::class] = \DI\autowire(\TriCab\Api\Identity\Persistence\AzureSqlExpiredEmailOtpChallengePurger::class);
            $definitions[GuestSessionRepository::class] = \DI\autowire(AzureSqlGuestSessionRepository::class);
            $definitions[TrustedDeviceRepository::class] = \DI\autowire(AzureSqlTrustedDeviceRepository::class);
            $definitions[UserPasskeyRepository::class] = \DI\autowire(AzureSqlUserPasskeyRepository::class);
            $definitions[GuestPasskeySetupTokenRepository::class] = \DI\autowire(AzureSqlGuestPasskeySetupTokenRepository::class);
            $definitions[PasskeyChallengeRepository::class] = \DI\autowire(AzureSqlPasskeyChallengeRepository::class);
            $definitions[PasskeyRegistrationSessionRepository::class] = \DI\autowire(AzureSqlPasskeyRegistrationSessionRepository::class);
            $definitions[UserProvisioningRepository::class] = \DI\autowire(AzureSqlUserProvisioningRepository::class);
            $definitions[UserManagementRepository::class] = \DI\autowire(AzureSqlUserManagementRepository::class);
            $definitions[RoleManagementRepository::class] = \DI\autowire(AzureSqlRoleManagementRepository::class);
            $definitions[PermissionManagementRepository::class] = \DI\autowire(AzureSqlPermissionManagementRepository::class);
            $definitions[UserManagementAuditRepository::class] = \DI\autowire(AzureSqlUserManagementAuditRepository::class);
            $definitions[AdminMutationTransaction::class] = \DI\autowire(AdminMutationTransaction::class);
            $definitions[CustomerRepository::class] = \DI\autowire(AzureSqlCustomerRepository::class);
            $definitions[\TriCab\Core\LogNote\Contract\LogNoteRepository::class] = \DI\autowire(\TriCab\Api\LogNote\Persistence\AzureSqlLogNoteRepository::class);
            $definitions[\TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository::class] = \DI\autowire(\TriCab\Api\CustomerContactReport\Persistence\AzureSqlCustomerContactReportRepository::class);
            $definitions[\TriCab\Core\LogNote\Contract\LogNoteAuditRecorder::class] = \DI\autowire(\TriCab\Api\LogNote\Persistence\AzureSqlLogNoteAuditRecorder::class);
            $definitions[\TriCab\Core\PdfCatalogue\Contract\PdfCatalogueRepository::class] = \DI\autowire(\TriCab\Api\PdfCatalogue\Persistence\AzureSqlPdfCatalogueRepository::class);
            $definitions[CustomerContactRepository::class] = \DI\autowire(AzureSqlCustomerContactRepository::class);
            $definitions[CustomerContactUserRepository::class] = \DI\autowire(AzureSqlCustomerContactUserRepository::class);
            $definitions[CustomerPortalUserProvisioner::class] = \DI\autowire(UserManagementCustomerPortalUserProvisioner::class);
            $definitions[GuestLoginEligibilityLookup::class] = \DI\autowire(AzureSqlGuestLoginEligibilityLookup::class);
            $definitions[ProductRepository::class] = \DI\autowire(AzureSqlProductRepository::class);
            $definitions[\TriCab\Core\Commerce\Contract\CartRepository::class] = \DI\autowire(\TriCab\Api\Commerce\Persistence\AzureSqlCartRepository::class);
            $definitions[\TriCab\Core\Commerce\Contract\CartLineMediaResolver::class] = static function (Container $container): \TriCab\Core\Commerce\Contract\CartLineMediaResolver {
                return new \TriCab\Api\Commerce\Persistence\AzureSqlCartLineMediaResolver(
                    self::get($container, SqlConnection::class),
                    self::get($container, ProductDevMediaUrlResolver::class),
                );
            };
            $definitions[\TriCab\Core\Expense\Contract\ExpenseReceiptRepository::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\AzureSqlExpenseReceiptRepository::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpenseProfileRepository::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\AzureSqlExpenseProfileRepository::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\AzureSqlExpenseProfileAdminQuery::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpenseBankFeedRepository::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\AzureSqlExpenseBankFeedRepository::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpensePeriodRepository::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\AzureSqlExpensePeriodRepository::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpenseReminderLogRepository::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\AzureSqlExpenseReminderLogRepository::class);
            // RFQ plan 2.4: the draft store and the queue on the live MySQL
            // store's shape (`create-rfq-schema.sql`). The store has no
            // in-memory twin, and nothing calls it until the Phase 3/4
            // endpoints do; the queue replaces InMemoryRfqRepository.
            $definitions[\TriCab\Core\Rfq\Contract\RfqDraftStore::class]
                = \DI\autowire(\TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore::class);
            $definitions[\TriCab\Core\Rfq\Contract\RfqRepository::class]
                = \DI\autowire(\TriCab\Api\Rfq\Persistence\AzureSqlRfqRepository::class);
            // Parity plan E6: workflow writes share the draft store (one lock, one
            // transaction with the activity row); people are active identity users.
            $definitions[\TriCab\Core\Rfq\Contract\RfqWorkflowStore::class]
                = static fn (Container $container): \TriCab\Core\Rfq\Contract\RfqWorkflowStore
                    => self::get($container, \TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore::class);
            $definitions[\TriCab\Core\Rfq\Contract\RfqSyncStore::class]
                = static fn (Container $container): \TriCab\Core\Rfq\Contract\RfqSyncStore
                    => self::get($container, \TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore::class);
            $definitions[\TriCab\Core\Rfq\Contract\RfqPeopleDirectory::class]
                = \DI\autowire(\TriCab\Api\Rfq\Persistence\AzureSqlRfqPeopleDirectory::class);
            // Plans 2.5/3.5: reader jobs are written by the draft store (one
            // transaction with the rows they replace); documents in their own
            // private container (`BLOB_CONTAINER_RFQ`).
            $definitions[\TriCab\Core\Rfq\Contract\RfqReaderJobStore::class]
                = static fn (Container $container): \TriCab\Core\Rfq\Contract\RfqReaderJobStore
                    => self::get($container, \TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore::class);
            $definitions[\TriCab\Core\Rfq\Contract\RfqDocumentStore::class]
                = static fn (Container $container): \TriCab\Core\Rfq\Contract\RfqDocumentStore
                    => new \TriCab\Api\Rfq\Persistence\AzureSqlRfqDocumentStore(
                        self::get($container, SqlConnection::class),
                        self::get($container, ProductBlobStore::class),
                        $settings->blobContainerRfq,
                        self::get($container, Clock::class),
                    );
            $definitions[\TriCab\Core\Rfq\Contract\RfqCustomerDirectory::class]
                = \DI\autowire(\TriCab\Api\Rfq\Persistence\AzureSqlRfqCustomerDirectory::class);
            // Parity plan E5.5: correction memory and Save Review's log.
            $definitions[\TriCab\Core\Rfq\Contract\RfqCorrectionMemory::class]
                = \DI\autowire(\TriCab\Api\Rfq\Persistence\AzureSqlRfqCorrectionMemory::class);
            // Finance scopes and the portal user map (F1.10): the two tables in `finance`, and
            // the read of identity's email addresses the mapping script matches against.
            $definitions[\TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader::class]
                = \DI\autowire(\TriCab\Api\Finance\Access\AzureSqlFinanceAccessScopeReader::class);
            $definitions[\TriCab\Core\Finance\Shared\Access\FinancePortalUserMap::class]
                = \DI\autowire(\TriCab\Api\Finance\Access\AzureSqlFinancePortalUserMap::class);
            $definitions[\TriCab\Core\Finance\Shared\Access\FinancePlatformUserLookup::class]
                = \DI\autowire(\TriCab\Api\Finance\Access\AzureSqlFinancePlatformUserLookup::class);
            // Finance files (F1.9): the rows in `finance`, the bytes in a private container.
            $definitions[\TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore::class]
                = static function (Container $container) use ($settings): \TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore {
                    $blobs = self::get($container, ProductBlobStore::class);
                    if ($settings->financeArSampleFiles) {
                        // Local development only (Settings refuses it elsewhere): the in-memory store forgets between requests.
                        \TriCab\Api\Finance\Ar\ArSampleFiles::load($blobs, $settings->blobContainerFinance);
                    }
                    if ($settings->financeApSampleFiles) {
                        // The same for Tri-AP's invented invoice documents (Settings refuses it elsewhere).
                        \TriCab\Api\Finance\Ap\ApSampleFiles::load($blobs, $settings->blobContainerFinance);
                    }

                    return new \TriCab\Api\Finance\Attachments\AzureSqlFinanceAttachmentStore(
                        self::get($container, SqlConnection::class),
                        $blobs,
                        $settings->blobContainerFinance,
                    );
                };
            // Finance jobs and audit (F1.8): the claim table and the audit table in `finance`.
            $definitions[\TriCab\Core\Finance\Shared\Audit\FinanceAuditLog::class]
                = \DI\autowire(\TriCab\Api\Finance\Audit\AzureSqlFinanceAuditLog::class);
            $definitions[\TriCab\Core\Finance\Shared\Jobs\FinanceJobClaims::class]
                = \DI\autowire(\TriCab\Api\Finance\Jobs\AzureSqlFinanceJobClaims::class);
            // Odoo reference lists (F1.12): the two tables in `finance`.
            $definitions[\TriCab\Core\Finance\Shared\Odoo\OdooRecordStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Odoo\AzureSqlOdooRecordStore::class);
            $definitions[\TriCab\Core\Finance\Shared\Odoo\OdooSyncRuns::class]
                = \DI\autowire(\TriCab\Api\Finance\Odoo\AzureSqlOdooSyncRuns::class);
            // Finance mail (F1.4): the registry and the ledger in the `finance` schema.
            $definitions[\TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry::class]
                = \DI\autowire(\TriCab\Api\Finance\Mail\AzureSqlFinanceMailboxRegistry::class);
            $definitions[\TriCab\Core\Finance\Shared\Mail\FinanceMailLedger::class]
                = \DI\autowire(\TriCab\Api\Finance\Mail\AzureSqlFinanceMailLedger::class);
            // Tri-Task (Phase 2): the member directory reads `[identity]` and `finance.task_member_profiles`.
            $definitions[\TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory::class]
                = \DI\autowire(\TriCab\Api\Finance\Task\AzureSqlFinanceTaskMemberDirectory::class);
            $definitions[\TriCab\Core\Finance\Task\Contract\TaskSetupStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Task\AzureSqlTaskSetupStore::class);
            $definitions[\TriCab\Core\Finance\Task\Contract\TaskStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Task\AzureSqlTaskStore::class);
            $definitions[\TriCab\Core\Finance\Task\Contract\TaskBoardStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Task\AzureSqlTaskBoardStore::class);
            $definitions[\TriCab\Core\Finance\Task\Contract\TaskMailLinks::class]
                = \DI\autowire(\TriCab\Api\Finance\Task\AzureSqlTaskMailLinks::class);
            $definitions[\TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Task\AzureSqlTaskReportRecipientStore::class);
            // Tri-AR (Phase 5): the assignee directory reads `[identity]`; the option lists are `finance.ar_option_lists`.
            $definitions[\TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory::class]
                = \DI\autowire(\TriCab\Api\Finance\Ar\AzureSqlArAssigneeDirectory::class);
            $definitions[\TriCab\Core\Finance\Ar\Contract\ArOptionStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ar\AzureSqlArOptionStore::class);
            $definitions[\TriCab\Core\Finance\Ar\Contract\ArRecordStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ar\AzureSqlArRecordStore::class);
            $definitions[\TriCab\Core\Finance\Ar\Contract\ArOutboundStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ar\AzureSqlArOutboundStore::class);
            $definitions[\TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ar\AzureSqlArCreditNoteStore::class);
            $definitions[\TriCab\Core\Finance\Ar\Contract\ArScanStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ar\AzureSqlArScanStore::class);
            // Tri-AP (Phase 6): the Settings lists and the signing limits are `finance.invoice_*` tables.
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApReferenceStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApReferenceStore::class);
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApSigningLimitStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApSigningLimitStore::class);
            // Tri-AP: vendors and extraction (T6.8, T6.9): the vendors are the `vendors` list of `finance.odoo_records`.
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApVendorStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApVendorStore::class);
            // Tri-AP: scan (T6.7): the runs and the claim are `finance.invoice_scan_runs`.
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApScanStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApScanStore::class);
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApInvoiceStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore::class);
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApAuditStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApAuditStore::class);
            // Tri-AP: AP Mailbox (T6.6)
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApEmailStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApEmailStore::class);
            // Tri-AP: mail (T6.10)
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApOutboundStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApOutboundStore::class);
            // Tri-AP: approvals (T6.11): the batches are `finance.invoice_approval_*`; the approvers are read from `[identity]`.
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApApprovalStore::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApApprovalStore::class);
            $definitions[\TriCab\Core\Finance\Ap\Contract\ApApproverDirectory::class]
                = \DI\autowire(\TriCab\Api\Finance\Ap\AzureSqlApApproverDirectory::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingSelfServiceRepository::class);
            // Phase 3.3 — the write side. All four share one locator, whose single
            // statement carries ownership, item type, withdrawal and content
            // version; nothing else resolves an assignment.
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingTrainingRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingTrainingRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingPolicyRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingPolicyRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingFormRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingFormRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingDocumentRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingDocumentRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingWriteIdempotencyStore::class);
            // Phase 6 — the completion feed, read only.
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingCompletionFeedRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingCompletionFeedRepository::class);
            // Phase 4 — HR administration. Admin writes audit inside their own
            // transaction; the audit log alone is for refusals.
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingPeopleRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAdminAuditLog::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingReviewRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingReviewRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingAccountDirectory::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingContentRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingContentRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingReportRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingReportRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingReminderRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingReminderRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingRetentionRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingRetentionRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingFormContributionRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingFormContributionRepository::class);
            $definitions[\TriCab\Core\Onboarding\Contract\OnboardingDocumentGenerationRepository::class]
                = \DI\autowire(\TriCab\Api\Onboarding\Persistence\AzureSqlOnboardingDocumentGenerationRepository::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpenseAuditRecorder::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\AzureSqlExpenseAuditRecorder::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpenseGraphFinanceGroupChecker::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\NullExpenseGraphFinanceGroupChecker::class);
            $definitions[\TriCab\Core\Expense\Contract\ExpenseFinanceCapabilityResolver::class] = \DI\autowire(\TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver::class);
            $definitions[RegionRepository::class] = \DI\autowire(AzureSqlRegionRepository::class);
            $definitions[ProductVariantRepository::class] = \DI\autowire(AzureSqlProductVariantRepository::class);
            $definitions[ProductAdminQueryRepository::class] = \DI\autowire(AzureSqlProductAdminQueryRepository::class);
            $definitions[ProductSearchRepository::class] = \DI\autowire(AzureSqlProductSearchRepository::class);
            $definitions[ProductCodeSearchRepository::class] = \DI\autowire(AzureSqlProductCodeSearchRepository::class);
            $definitions[AssistantConversationRepository::class] = \DI\autowire(AzureSqlAssistantConversationRepository::class);
            $definitions[AssistantStyleOptionsProvider::class] = \DI\autowire(AzureSqlAssistantStyleOptionsProvider::class);
            $definitions[AssistantCodeProductLookup::class] = \DI\autowire(AzureSqlAssistantCodeProductLookup::class);
            $definitions[ProductLookupRepository::class] = \DI\autowire(AzureSqlProductLookupRepository::class);
            $definitions[ProductClassificationRepository::class] = \DI\autowire(AzureSqlProductClassificationRepository::class);
            $definitions[ProductStyleRepository::class] = \DI\autowire(AzureSqlProductStyleRepository::class);
            $definitions[ProductStyleMappingIdentitySource::class] = \DI\autowire(AzureSqlProductStyleMappingIdentitySource::class);
            $definitions[ProductStyleFamilyEvidenceSource::class] = \DI\autowire(AzureSqlProductStyleFamilyEvidenceSource::class);
            $definitions[StyleSharedDataEvidenceSource::class] = \DI\autowire(AzureSqlStyleSharedDataEvidenceSource::class);
            $definitions[CatalogueProductStyleEvidenceSource::class] = \DI\autowire(AzureSqlCatalogueProductStyleEvidenceSource::class);
            $definitions[PriceListRepository::class] = \DI\autowire(AzureSqlPriceListRepository::class);
            $definitions[VariantLegacyPricingIdentityRepository::class] = \DI\autowire(AzureSqlVariantLegacyPricingIdentityRepository::class);
            $definitions[CatalogueNavigationRepository::class] = \DI\autowire(AzureSqlCatalogueNavigationRepository::class);
            $definitions[ProductSpecificationRepository::class] = \DI\autowire(AzureSqlProductSpecificationRepository::class);
            $definitions[ProductMediaRepository::class] = \DI\autowire(AzureSqlProductMediaRepository::class);
            $definitions[ProductDocumentRepository::class] = \DI\autowire(AzureSqlProductDocumentRepository::class);
            $definitions[TechnicalDocumentLibraryRepository::class] = \DI\autowire(AzureSqlTechnicalDocumentLibraryRepository::class);
            $definitions[Product360Repository::class] = \DI\autowire(AzureSqlProduct360Repository::class);
            $definitions[ProductArModelRepository::class] = \DI\autowire(AzureSqlProductArModelRepository::class);
            $definitions[Product3DModelRepository::class] = \DI\autowire(AzureSqlProduct3DModelRepository::class);
            $definitions[Product3DAssetRepository::class] = \DI\autowire(AzureSqlProduct3DAssetRepository::class);
            $definitions[ProductPublicQueryRepository::class] = static function (Container $container) use ($settings): ProductPublicQueryRepository {
                return new AzureSqlProductPublicQueryRepository(
                    self::get($container, SqlConnection::class),
                    self::get($container, Product3DModelRepository::class),
                    $settings->product3dEnabled,
                    self::get($container, ProductPageMediaLayoutRepository::class),
                    self::get($container, ProductPageContentRepository::class),
                );
            };
            $definitions[ProductCustomerViewQueryRepository::class] = static function (Container $container) use ($settings): ProductCustomerViewQueryRepository {
                return new AzureSqlProductCustomerViewRepository(
                    self::get($container, SqlConnection::class),
                    self::get($container, Product3DModelRepository::class),
                    $settings->product3dEnabled,
                    self::get($container, ProductPageMediaLayoutRepository::class),
                    self::get($container, ProductPageContentRepository::class),
                );
            };
            $definitions[ProductMarketRepository::class] = \DI\autowire(AzureSqlProductMarketRepository::class);
            $definitions[ProductRelationshipRepository::class] = \DI\autowire(AzureSqlProductRelationshipRepository::class);
            $definitions[ProductSeoRepository::class] = \DI\autowire(AzureSqlProductSeoRepository::class);
            $definitions[ProductThumbnailPresentationRepository::class] = \DI\autowire(AzureSqlProductThumbnailPresentationRepository::class);
            $definitions[ProductPageMediaLayoutRepository::class] = \DI\autowire(AzureSqlProductPageMediaLayoutRepository::class);
            $definitions[ProductPageContentRepository::class] = \DI\autowire(AzureSqlProductPageContentRepository::class);
            $definitions[SiteConfigurationRepository::class] = \DI\autowire(AzureSqlSiteConfigurationRepository::class);
            $definitions[ProductCodeRuleRepository::class] = \DI\autowire(AzureSqlProductCodeRuleRepository::class);
            $definitions[ProductPricingRepository::class] = \DI\autowire(AzureSqlProductPricingRepository::class);
            $definitions[\TriCab\Core\Product\Application\Pricing\VariantCommercialCatalogRepository::class] = \DI\autowire(\TriCab\Api\Product\Pricing\AzureSqlVariantCommercialCatalogRepository::class);
            $definitions[\TriCab\Core\Product\Application\Pricing\VariantBaseCostRepository::class] = \DI\autowire(\TriCab\Api\Product\Pricing\AzureSqlVariantBaseCostRepository::class);
            $definitions[ProductAuditRepository::class] = \DI\autowire(AzureSqlProductAuditRepository::class);
            $definitions[AdminDashboardQueryRepository::class] = \DI\autowire(AzureSqlAdminDashboardQueryRepository::class);
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

            $definitions[\TriCab\Core\Identity\Contract\ExpiredEmailOtpChallengePurger::class] = \DI\autowire(\TriCab\Api\Identity\Persistence\InMemoryExpiredEmailOtpChallengePurger::class);

            if ($settings->isGuestJwtConfigured()) {
                $definitions[EmailOtpChallengeRepository::class] = \DI\autowire(InMemoryEmailOtpChallengeRepository::class);
                $definitions[GuestSessionRepository::class] = \DI\autowire(InMemoryGuestSessionRepository::class);
                $definitions[TrustedDeviceRepository::class] = \DI\autowire(InMemoryTrustedDeviceRepository::class);
                $definitions[UserPasskeyRepository::class] = \DI\autowire(InMemoryUserPasskeyRepository::class);
                $definitions[GuestPasskeySetupTokenRepository::class] = \DI\autowire(InMemoryGuestPasskeySetupTokenRepository::class);
                $definitions[PasskeyChallengeRepository::class] = \DI\autowire(InMemoryPasskeyChallengeRepository::class);
                $definitions[PasskeyRegistrationSessionRepository::class] = \DI\autowire(InMemoryPasskeyRegistrationSessionRepository::class);
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
            $definitions[\TriCab\Core\Commerce\Contract\CartRepository::class] = \DI\autowire(\TriCab\Api\Commerce\Persistence\InMemoryCartRepository::class);
            $definitions[\TriCab\Core\Commerce\Contract\CartLineMediaResolver::class] = \DI\autowire(\TriCab\Api\Commerce\Persistence\InMemoryCartLineMediaResolver::class);
            $definitions[RegionRepository::class] = \DI\autowire(InMemoryRegionRepository::class);
            $definitions[ProductRepository::class] = \DI\autowire(InMemoryProductRepository::class);
            $definitions[ProductVariantRepository::class] = \DI\autowire(InMemoryProductVariantRepository::class);
            $definitions[ProductAdminQueryRepository::class] = \DI\autowire(InMemoryProductAdminQueryRepository::class);
            $definitions[Product3DModelRepository::class] = \DI\autowire(InMemoryProduct3DModelRepository::class);
            $definitions[Product3DAssetRepository::class] = \DI\autowire(InMemoryProduct3DAssetRepository::class);
            $definitions[ProductPublicQueryRepository::class] = static function (Container $container) use ($settings): ProductPublicQueryRepository {
                return new InMemoryProductPublicQueryRepository(
                    self::get($container, InMemoryProductStore::class),
                    self::get($container, Product3DModelRepository::class),
                    $settings->product3dEnabled,
                );
            };
            $definitions[ProductCustomerViewQueryRepository::class] = static function (Container $container) use ($settings): ProductCustomerViewQueryRepository {
                return new InMemoryProductCustomerViewRepository(
                    self::get($container, InMemoryProductStore::class),
                    self::get($container, Product3DModelRepository::class),
                    $settings->product3dEnabled,
                );
            };
            $definitions[ProductSearchRepository::class] = \DI\autowire(InMemoryProductSearchRepository::class);
            $definitions[ProductCodeSearchRepository::class] = \DI\autowire(InMemoryProductCodeSearchRepository::class);
            $definitions[AssistantStyleOptionsProvider::class] = \DI\autowire(InMemoryAssistantStyleOptionsProvider::class);
            $definitions[AssistantCodeProductLookup::class] = \DI\autowire(InMemoryAssistantCodeProductLookup::class);
            $definitions[ProductLookupRepository::class] = \DI\autowire(InMemoryProductLookupRepository::class);
            $definitions[ProductClassificationRepository::class] = \DI\autowire(InMemoryProductClassificationRepository::class);
            $definitions[ProductStyleRepository::class] = \DI\autowire(InMemoryProductStyleRepository::class);
            $definitions[CatalogueNavigationRepository::class] = \DI\autowire(InMemoryCatalogueNavigationRepository::class);
            $definitions[ProductSpecificationRepository::class] = \DI\autowire(InMemoryProductSpecificationRepository::class);
            $definitions[ProductMediaRepository::class] = \DI\autowire(InMemoryProductMediaRepository::class);
            $definitions[ProductDocumentRepository::class] = \DI\autowire(InMemoryProductDocumentRepository::class);
            $definitions[TechnicalDocumentLibraryRepository::class] = \DI\autowire(InMemoryTechnicalDocumentLibraryRepository::class);
            $definitions[Product360Repository::class] = \DI\autowire(InMemoryProduct360Repository::class);
            $definitions[ProductArModelRepository::class] = \DI\autowire(InMemoryProductArModelRepository::class);
            $definitions[ProductMarketRepository::class] = \DI\autowire(InMemoryProductMarketRepository::class);
            $definitions[ProductRelationshipRepository::class] = \DI\autowire(InMemoryProductRelationshipRepository::class);
            $definitions[ProductSeoRepository::class] = \DI\autowire(InMemoryProductSeoRepository::class);
            $definitions[ProductThumbnailPresentationRepository::class] = \DI\autowire(InMemoryProductThumbnailPresentationRepository::class);
            $definitions[ProductPageMediaLayoutRepository::class] = \DI\autowire(InMemoryProductPageMediaLayoutRepository::class);
            $definitions[ProductPageContentRepository::class] = \DI\autowire(InMemoryProductPageContentRepository::class);
            $definitions[SiteConfigurationRepository::class] = \DI\autowire(InMemorySiteConfigurationRepository::class);
            $definitions[ProductCodeRuleRepository::class] = \DI\autowire(InMemoryProductCodeRuleRepository::class);
            $definitions[ProductPricingRepository::class] = \DI\autowire(InMemoryProductPricingRepository::class);
            $definitions[PriceListRepository::class] = \DI\autowire(InMemoryPriceListRepository::class);
            $definitions[VariantLegacyPricingIdentityRepository::class] = \DI\autowire(InMemoryVariantLegacyPricingIdentityRepository::class);
            $definitions[\TriCab\Core\Product\Application\Pricing\VariantCommercialCatalogRepository::class] = \DI\autowire(\TriCab\Core\Product\Application\Pricing\InMemoryVariantCommercialCatalogRepository::class);
            $definitions[\TriCab\Core\Product\Application\Pricing\VariantBaseCostRepository::class] = \DI\autowire(\TriCab\Core\Product\Application\Pricing\InMemoryVariantBaseCostRepository::class);
            $definitions[ProductAuditRepository::class] = \DI\autowire(InMemoryProductAuditRepository::class);
            $definitions[AdminDashboardQueryRepository::class] = \DI\autowire(InMemoryAdminDashboardQueryRepository::class);
            $definitions[ProductIntegrationRepository::class] = \DI\autowire(InMemoryProductIntegrationRepository::class);
            $definitions[LegacyProductMappingRepository::class] = \DI\autowire(InMemoryLegacyProductMappingRepository::class);
            $definitions[ProductSubResourceResolver::class] = static function (Container $container): ProductSubResourceResolver {
                return new ProductSubResourceResolver(self::get($container, InMemoryProductStore::class), null);
            };
        }

        if ($settings->customerContactE2eMode) {
            self::addCustomerContactE2eDefinitions($definitions, $settings);
        }

        $builder->addDefinitions($definitions);
        if ($settings->customerContactE2eMode) {
            $builder->addDefinitions(self::customerContactE2eDecorators());
        }

        return $builder->build();
    }

    /**
     * Browser harness bindings. Settings already refused production and Azure SQL.
     *
     * @param array<string, mixed> $definitions
     */
    private static function addCustomerContactE2eDefinitions(array &$definitions, Settings $settings): void
    {
        if ($settings->isProduction() || $settings->usesAzureSqlUserRepository()) {
            throw new InvalidArgumentException(
                'CUSTOMER_CONTACT_E2E_MODE cannot be enabled when APP_ENV=production or USER_REPOSITORY=azure-sql. '
                . 'E2E mode does not replace Azure Customer Contact persistence. Refusing to start.',
            );
        }

        $definitions[\TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository::class] = static function (): \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportRepository {
            return new \TriCab\Api\CustomerContactReport\Persistence\DevelopmentFileCustomerContactReportRepository(
                \TriCab\Api\CustomerContactReport\Persistence\CustomerContactE2ePaths::repositoryFile(),
            );
        };
        $definitions[\TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore::class] = static function (): \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportFileStore {
            return new \TriCab\Api\CustomerContactReport\Storage\DevelopmentFileCustomerContactReportFileStore(
                \TriCab\Api\CustomerContactReport\Persistence\CustomerContactE2ePaths::fileDirectory(),
            );
        };
        $definitions[\TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine::class] = static function (Container $container): \TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine {
            return new \TriCab\Api\CustomerContactReport\Identity\DevelopmentCustomerContactReportReportingLine(
                self::get($container, \TriCab\Core\Identity\Contract\UserRepository::class),
            );
        };
        $accountsLogin = $settings->customerContactAccountsLogin !== ''
            ? $settings->customerContactAccountsLogin
            : \TriCab\Api\CustomerContactReport\Identity\CustomerContactE2eDirectory::ACCOUNTS_LOGIN;
        $definitions[\TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin::class] = static function () use ($accountsLogin): \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin {
            return new \TriCab\Core\CustomerContactReport\Application\DTO\CustomerContactReportAccountsLogin($accountsLogin);
        };
    }

    /**
     * Applied after the main definitions so PHP-DI still has the memory repositories to wrap.
     *
     * @return array<string, mixed>
     */
    private static function customerContactE2eDecorators(): array
    {
        return [
            UserRepository::class => \DI\decorate(
                static function (object $repository): object {
                    if ($repository instanceof \TriCab\Api\Identity\Persistence\InMemoryApiUserRepository) {
                        \TriCab\Api\CustomerContactReport\Identity\CustomerContactE2eDirectory::seedAuthentication($repository);
                    }

                    return $repository;
                },
            ),
            \TriCab\Api\Identity\Persistence\InMemoryUserManagementStore::class => \DI\decorate(
                static function (object $store): object {
                    if ($store instanceof \TriCab\Api\Identity\Persistence\InMemoryUserManagementStore) {
                        \TriCab\Api\CustomerContactReport\Identity\CustomerContactE2eDirectory::seedDirectory($store);
                    }

                    return $store;
                },
            ),
            \TriCab\Api\Customer\Persistence\InMemory\InMemoryCustomerStore::class => \DI\decorate(
                static function (object $store): object {
                    if ($store instanceof \TriCab\Api\Customer\Persistence\InMemory\InMemoryCustomerStore) {
                        \TriCab\Api\CustomerContactReport\Identity\CustomerContactE2eDirectory::seedCustomer($store);
                    }

                    return $store;
                },
            ),
        ];
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

        $definitions[TrustedDeviceCredentialGenerator::class] = \DI\autowire(TrustedDeviceCredentialGenerator::class);
        $definitions[RegisterTrustedDeviceService::class] = static function (Container $container) use ($settings): RegisterTrustedDeviceService {
            return new RegisterTrustedDeviceService(
                devices: self::get($container, TrustedDeviceRepository::class),
                credentials: self::get($container, TrustedDeviceCredentialGenerator::class),
                clock: self::get($container, Clock::class),
                ttlDays: $settings->trustedDeviceTtlDays,
            );
        };
        $definitions[ExchangeTrustedDeviceSessionService::class] = \DI\autowire(ExchangeTrustedDeviceSessionService::class);
        $definitions[RevokeTrustedDeviceService::class] = \DI\autowire(RevokeTrustedDeviceService::class);
        $definitions[TrustedDeviceAuthErrorMapper::class] = \DI\autowire(TrustedDeviceAuthErrorMapper::class);
        $definitions[RegisterTrustedDeviceAction::class] = \DI\autowire(RegisterTrustedDeviceAction::class);
        $definitions[ExchangeTrustedDeviceSessionAction::class] = \DI\autowire(ExchangeTrustedDeviceSessionAction::class);
        $definitions[RevokeTrustedDeviceAction::class] = \DI\autowire(RevokeTrustedDeviceAction::class);

        $definitions[GoogleJwksClient::class] = \DI\create(GoogleJwksClient::class);
        $definitions[GoogleIdTokenValidator::class] = static function (Container $container) use ($settings): GoogleIdTokenValidator {
            return new GoogleOidcIdTokenValidator(
                googleClientIds: $settings->googleAllowedClientIds(),
                jwksClient: self::get($container, GoogleJwksClient::class),
                clockSkewSeconds: $settings->guestJwtClockSkewSeconds,
            );
        };
        $definitions[AuthenticateWithGoogleService::class] = \DI\autowire(AuthenticateWithGoogleService::class);
        $definitions[GoogleAuthErrorMapper::class] = \DI\autowire(GoogleAuthErrorMapper::class);
        $definitions[AuthenticateGoogleAction::class] = \DI\autowire(AuthenticateGoogleAction::class);

        $definitions[AppleJwksClient::class] = \DI\create(AppleJwksClient::class);
        $definitions[AppleClientSecretGenerator::class] = \DI\autowire(AppleClientSecretGenerator::class);
        $definitions[AppleAuthorizationChallengeService::class] = \DI\autowire(AppleAuthorizationChallengeService::class);
        $definitions[AppleAuthorizationCodeExchanger::class] = \DI\autowire(AppleAuthorizationCodeHttpExchanger::class);
        $definitions[AppleOidcIdTokenValidator::class] = static function (Container $container) use ($settings): AppleOidcIdTokenValidator {
            return new AppleOidcIdTokenValidator(
                settings: $settings,
                jwksClient: self::get($container, AppleJwksClient::class),
                clockSkewSeconds: $settings->guestJwtClockSkewSeconds,
            );
        };
        $definitions[AppleIdTokenValidator::class] = \DI\get(AppleOidcIdTokenValidator::class);
        $definitions[AppleNativeIdTokenValidator::class] = \DI\get(AppleOidcIdTokenValidator::class);
        $definitions[AuthenticateWithAppleService::class] = \DI\autowire(AuthenticateWithAppleService::class);
        $definitions[AuthenticateWithAppleNativeService::class] = \DI\autowire(AuthenticateWithAppleNativeService::class);
        $definitions[AppleAuthErrorMapper::class] = \DI\autowire(AppleAuthErrorMapper::class);
        $definitions[AppleAuthChallengeAction::class] = \DI\autowire(AppleAuthChallengeAction::class);
        $definitions[AuthenticateAppleAction::class] = \DI\autowire(AuthenticateAppleAction::class);
        $definitions[AuthenticateAppleNativeAction::class] = \DI\autowire(AuthenticateAppleNativeAction::class);
        self::addPasskeyAuthDefinitions($definitions, $settings);
    }

    /**
     * Real webauthn-lib unless Fake is explicitly opted in for in-memory development tests.
     * RP ID and origins always come from Settings, never from the request Host header.
     */
    public static function passkeyCeremonyFor(Settings $settings): PasskeyCeremony
    {
        if ($settings->usesFakePasskeyCeremony()) {
            return new FakePasskeyCeremony();
        }

        return new WebauthnLibPasskeyCeremony();
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private static function addPasskeyAuthDefinitions(array &$definitions, Settings $settings): void
    {
        if (!$settings->isPasskeyAuthConfigured()) {
            return;
        }

        $definitions[PasskeyRpConfig::class] = static function () use ($settings): PasskeyRpConfig {
            return new PasskeyRpConfig(
                rpId: $settings->passkeyRpId,
                rpName: $settings->passkeyRpName,
                allowedOrigins: $settings->passkeyAllowedOrigins,
            );
        };
        $definitions[PasskeyCeremony::class] = static function () use ($settings): PasskeyCeremony {
            return self::passkeyCeremonyFor($settings);
        };
        $definitions[PasskeyAuthRateLimiter::class] = static function (Container $container): PasskeyAuthRateLimiter {
            return new WindowedPasskeyAuthRateLimiter(self::get($container, ApplicationCache::class));
        };
        $definitions[GuestPasskeyEmailSender::class] = static function (Container $container) use ($settings): GuestPasskeyEmailSender {
            return self::createGuestPasskeyEmailSender($settings, $container);
        };
        // Onboarding invitations (4.1) go through the same provider as sign-in
        // codes, so one setting decides where every guest-facing email goes.
        $definitions[\TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender::class] = static function (Container $container) use ($settings): \TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender {
            return self::createOnboardingInvitationEmailSender($settings, $container);
        };
        // Reminders (5.2) share the invitation's provider and its sender class.
        $definitions[\TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender::class] = static function (Container $container) use ($settings): \TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender {
            $sender = self::createOnboardingInvitationEmailSender($settings, $container);
            if (!$sender instanceof \TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender) {
                throw new \LogicException('The onboarding email sender does not send reminders.');
            }

            return $sender;
        };
        $definitions[\TriCab\Core\Onboarding\Application\DTO\Reminder\OnboardingReminderDelivery::class] = static function () use ($settings): \TriCab\Core\Onboarding\Application\DTO\Reminder\OnboardingReminderDelivery {
            return \TriCab\Core\Onboarding\Application\DTO\Reminder\OnboardingReminderDelivery::decide(
                $settings->onboardingReminderEmails,
                $settings->onboardingEmailTestRecipient,
                $settings->isProduction(),
                $settings->usesDevelopmentGuestEmail(),
            );
        };
        $definitions[\TriCab\Core\Onboarding\Application\Service\SendOnboardingRemindersService::class] = static function (Container $container) use ($settings): \TriCab\Core\Onboarding\Application\Service\SendOnboardingRemindersService {
            return new \TriCab\Core\Onboarding\Application\Service\SendOnboardingRemindersService(
                reminders: self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingReminderRepository::class),
                accounts: self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory::class),
                sender: self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingReminderEmailSender::class),
                delivery: self::get($container, \TriCab\Core\Onboarding\Application\DTO\Reminder\OnboardingReminderDelivery::class),
                clock: self::get($container, Clock::class),
                publicAppBaseUrl: $settings->passkeyPublicAppBaseUrl,
            );
        };
        $definitions[\TriCab\Core\Onboarding\Application\Service\InviteOnboardingPersonService::class] = static function (Container $container) use ($settings): \TriCab\Core\Onboarding\Application\Service\InviteOnboardingPersonService {
            return new \TriCab\Core\Onboarding\Application\Service\InviteOnboardingPersonService(
                people: self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository::class),
                accounts: self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingAccountDirectory::class),
                sender: self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender::class),
                audit: self::get($container, \TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog::class),
                clock: self::get($container, Clock::class),
                publicAppBaseUrl: $settings->passkeyPublicAppBaseUrl,
            );
        };
        $definitions[RequestGuestPasskeySetupLinkService::class] = static function (Container $container) use ($settings): RequestGuestPasskeySetupLinkService {
            return new RequestGuestPasskeySetupLinkService(
                eligibility: self::get($container, GuestLoginEligibilityService::class),
                users: self::get($container, UserRepository::class),
                tokens: self::get($container, GuestPasskeySetupTokenRepository::class),
                emailSender: self::get($container, GuestPasskeyEmailSender::class),
                rateLimiter: self::get($container, PasskeyAuthRateLimiter::class),
                publicAppBaseUrl: $settings->passkeyPublicAppBaseUrl,
                ttlSeconds: $settings->passkeySetupTokenTtlSeconds,
                clock: self::get($container, Clock::class),
            );
        };
        $definitions[VerifyGuestPasskeySetupTokenService::class] = static function (Container $container) use ($settings): VerifyGuestPasskeySetupTokenService {
            return new VerifyGuestPasskeySetupTokenService(
                tokens: self::get($container, GuestPasskeySetupTokenRepository::class),
                sessions: self::get($container, PasskeyRegistrationSessionRepository::class),
                users: self::get($container, UserRepository::class),
                rateLimiter: self::get($container, PasskeyAuthRateLimiter::class),
                sessionTtlSeconds: $settings->passkeyRegistrationChallengeTtlSeconds,
                clock: self::get($container, Clock::class),
            );
        };
        $definitions[StartPasskeyRegistrationService::class] = static function (Container $container) use ($settings): StartPasskeyRegistrationService {
            return new StartPasskeyRegistrationService(
                sessions: self::get($container, PasskeyRegistrationSessionRepository::class),
                challenges: self::get($container, PasskeyChallengeRepository::class),
                passkeys: self::get($container, UserPasskeyRepository::class),
                users: self::get($container, UserRepository::class),
                ceremony: self::get($container, PasskeyCeremony::class),
                rp: self::get($container, PasskeyRpConfig::class),
                rateLimiter: self::get($container, PasskeyAuthRateLimiter::class),
                challengeTtlSeconds: $settings->passkeyRegistrationChallengeTtlSeconds,
                clock: self::get($container, Clock::class),
            );
        };
        $definitions[CompletePasskeyRegistrationService::class] = \DI\autowire(CompletePasskeyRegistrationService::class);
        $definitions[StartPasskeyAuthenticationService::class] = static function (Container $container) use ($settings): StartPasskeyAuthenticationService {
            return new StartPasskeyAuthenticationService(
                challenges: self::get($container, PasskeyChallengeRepository::class),
                ceremony: self::get($container, PasskeyCeremony::class),
                rp: self::get($container, PasskeyRpConfig::class),
                rateLimiter: self::get($container, PasskeyAuthRateLimiter::class),
                challengeTtlSeconds: $settings->passkeyAuthChallengeTtlSeconds,
                clock: self::get($container, Clock::class),
            );
        };
        $definitions[CompletePasskeyAuthenticationService::class] = \DI\autowire(CompletePasskeyAuthenticationService::class);
        $definitions[ManageUserPasskeysService::class] = \DI\autowire(ManageUserPasskeysService::class);
        $definitions[PasskeyAuthErrorMapper::class] = \DI\autowire(PasskeyAuthErrorMapper::class);
        $definitions[RequestPasskeySetupLinkAction::class] = \DI\autowire(RequestPasskeySetupLinkAction::class);
        $definitions[VerifyPasskeySetupLinkAction::class] = static function (Container $container) use ($settings): VerifyPasskeySetupLinkAction {
            return new VerifyPasskeySetupLinkAction(
                service: self::get($container, VerifyGuestPasskeySetupTokenService::class),
                responder: self::get($container, JsonResponder::class),
                errors: self::get($container, PasskeyAuthErrorMapper::class),
                logger: self::get($container, GuestAuthEventLogger::class),
                secureCookies: $settings->isProduction(),
            );
        };
        $definitions[PasskeyRegistrationOptionsAction::class] = \DI\autowire(PasskeyRegistrationOptionsAction::class);
        $definitions[PasskeyRegistrationCompleteAction::class] = \DI\autowire(PasskeyRegistrationCompleteAction::class);
        $definitions[PasskeyAuthenticationOptionsAction::class] = \DI\autowire(PasskeyAuthenticationOptionsAction::class);
        $definitions[PasskeyAuthenticationCompleteAction::class] = \DI\autowire(PasskeyAuthenticationCompleteAction::class);
        $definitions[ListPasskeysAction::class] = \DI\autowire(ListPasskeysAction::class);
        $definitions[RenamePasskeyAction::class] = \DI\autowire(RenamePasskeyAction::class);
        $definitions[RevokePasskeyAction::class] = \DI\autowire(RevokePasskeyAction::class);
    }

    /**
     * Graph mail is wired whenever credentials exist, even with
     * TRIEXPENSE_EMAIL_ENABLED=false: the adapter then resolves recipients and
     * logs the intent without calling Graph, which is what makes a non-production
     * run observable without mailing anyone. With no credentials at all there is
     * nothing to log against, so the recording Null port stays — and that is also
     * what tests get.
     */
    private static function createExpenseNotificationPort(
        Settings $settings,
        Container $container,
    ): \TriCab\Core\Expense\Contract\ExpenseNotificationPort {
        if (!$settings->triexpenseEmailEnabled && !$settings->isTriexpenseGraphConfigured()) {
            return new \TriCab\Api\Expense\Persistence\NullExpenseNotificationPort();
        }

        return new \TriCab\Api\Expense\Persistence\GraphExpenseNotificationPort(
            http: new StreamJsonHttpClient(
                (float) $settings->triexpenseGraphRequestTimeoutSeconds,
                (float) $settings->triexpenseGraphConnectTimeoutSeconds,
            ),
            profiles: self::get($container, \TriCab\Core\Expense\Contract\ExpenseProfileRepository::class),
            templates: self::get($container, \TriCab\Api\Expense\Email\ExpenseEmailTemplates::class),
            logger: self::get($container, \TriCab\Api\Expense\Observability\ExpenseEventLogger::class),
            tenantId: $settings->graphTenantId,
            clientId: $settings->graphClientId,
            clientSecret: $settings->graphClientSecret,
            mailFrom: $settings->triexpenseMailFrom,
            sendingEnabled: $settings->isTriexpenseEmailSendingEnabled(),
            testRecipient: $settings->triexpenseEmailTestRecipientOrNull(),
        );
    }

    private static function createExpenseCaptureAssistPort(
        Settings $settings,
        Container $container,
    ): \TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort {
        if (!$settings->isTriexpenseOcrConfigured()) {
            return new \TriCab\Api\Expense\Persistence\DisabledExpenseCaptureAssistPort();
        }

        return new \TriCab\Api\Expense\Persistence\AzureExpenseCaptureAssistPort(
            http: new StreamJsonHttpClient(
                (float) $settings->triexpenseOcrRequestTimeoutSeconds,
                (float) $settings->triexpenseOcrConnectTimeoutSeconds,
            ),
            logger: self::get($container, \TriCab\Api\Expense\Observability\ExpenseEventLogger::class),
            docIntelEndpoint: $settings->triexpenseDocIntelEndpoint,
            docIntelKey: $settings->triexpenseDocIntelKey,
            openAiEndpoint: $settings->triexpenseOpenAiEndpoint,
            openAiKey: $settings->triexpenseOpenAiKey,
            openAiDeployment: $settings->triexpenseOpenAiDeployment,
        );
    }

    private static function createOnboardingInvitationEmailSender(Settings $settings, Container $container): \TriCab\Core\Onboarding\Contract\OnboardingInvitationEmailSender
    {
        if ($settings->usesDevelopmentGuestEmail()) {
            return new \TriCab\Api\Onboarding\Email\DevelopmentOnboardingInvitationEmailSender();
        }

        $http = self::get($container, JsonHttpClient::class);

        if ($settings->usesMicrosoftGraphGuestEmail()) {
            return new \TriCab\Api\Onboarding\Email\MicrosoftGraphOnboardingInvitationEmailSender(
                http: $http,
                tenantId: $settings->graphTenantId,
                clientId: $settings->graphClientId,
                clientSecret: $settings->graphClientSecret,
                mailbox: $settings->rfqMailbox,
            );
        }

        return new \TriCab\Api\Onboarding\Email\PostmarkOnboardingInvitationEmailSender(
            http: $http,
            serverToken: $settings->postmarkServerToken,
            fromEmail: $settings->postmarkFromEmail,
            fromName: $settings->postmarkFromName,
            messageStream: $settings->postmarkMessageStream,
        );
    }

    private static function createGuestPasskeyEmailSender(Settings $settings, Container $container): GuestPasskeyEmailSender
    {
        if ($settings->usesDevelopmentGuestEmail()) {
            return new DevelopmentGuestPasskeyEmailSender();
        }

        $http = self::get($container, JsonHttpClient::class);

        if ($settings->usesMicrosoftGraphGuestEmail()) {
            return new MicrosoftGraphGuestPasskeyEmailSender(
                http: $http,
                tenantId: $settings->graphTenantId,
                clientId: $settings->graphClientId,
                clientSecret: $settings->graphClientSecret,
                mailbox: $settings->rfqMailbox,
            );
        }

        return new PostmarkGuestPasskeyEmailSender(
            http: $http,
            serverToken: $settings->postmarkServerToken,
            fromEmail: $settings->postmarkFromEmail,
            fromName: $settings->postmarkFromName,
            messageStream: $settings->postmarkMessageStream,
        );
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
