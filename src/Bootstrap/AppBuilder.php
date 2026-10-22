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
use TriCab\Api\Http\Action\Admin\ProductStylesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductFamiliesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductCategoriesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductPriceListsAdminAction;
use TriCab\Api\Http\Action\Admin\CatalogueTabsAdminAction;
use TriCab\Api\Http\Action\Admin\CatalogueNodesAdminAction;
use TriCab\Api\Http\Action\Admin\ProductCataloguePlacementsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationDefinitionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationGroupsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationOptionsAdminAction;
use TriCab\Api\Http\Action\Admin\SpecificationUnitsAdminAction;
use TriCab\Api\Http\Action\Admin\Migration\LegacyMigrationAdminAction;
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
use TriCab\Api\Http\Action\Admin\GetProduct3DAccessorySpecificationSourceAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProduct3DAction;
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
use TriCab\Api\Http\Action\Admin\PlatformSettingsAction;
use TriCab\Api\Http\Middleware\ActAsMiddleware;
use TriCab\Api\Http\Middleware\RequirePlatformAdministratorMiddleware;
use TriCab\Api\Http\Action\Admin\EndActAsAction;
use TriCab\Api\Http\Action\Admin\ListActAsSessionActionsAction;
use TriCab\Api\Http\Action\Admin\ListActAsSessionsAction;
use TriCab\Api\Http\Action\Admin\StartActAsAction;
use TriCab\Api\Http\Action\Admin\GetProductCustomerViewAction;
use TriCab\Api\Http\Action\Admin\UpdateCustomerViewThemeAction;
use TriCab\Api\Http\Action\Admin\ClearProductPresentationMediaRoleAction;
use TriCab\Api\Http\Action\Admin\UpdateProductMediaRoleAction;
use TriCab\Api\Http\Action\Admin\GetProductLookupsAction;
use TriCab\Api\Http\Action\Admin\GetProductPricingAction;
use TriCab\Api\Http\Action\Admin\CompareLegacyBaseCostAction;
use TriCab\Api\Http\Action\Admin\VariantLegacyPricingIdentityAdminAction;
use TriCab\Api\Http\Action\Admin\SetProductDefaultPricingUnitAction;
use TriCab\Api\Http\Action\Admin\SetProductPricingActiveAction;
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
use TriCab\Api\Http\Action\Admin\UpdateProductPageContentAction;
use TriCab\Api\Http\Action\Admin\UpdateProductPageMediaLayoutAction;
use TriCab\Api\Http\Action\Admin\UpdateProductThumbnailPresentationAction;
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
use TriCab\Api\Http\Action\PublicProduct\GetStableMediaImageAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductDocumentsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductMediaAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductRelationshipsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductSpecificationsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPublicProductVariantsAction;
use TriCab\Api\Http\Action\PublicProduct\PostCustomerVariantPricingBatchAction;
use TriCab\Api\Http\Action\PublicProduct\PostPrepareRfqLinePricingAction;
use TriCab\Api\Http\Action\PublicProduct\PostPrepareCartItemPricingAction;
use TriCab\Api\Http\Action\PublicProduct\GetPricingCustomerContactsAction;
use TriCab\Api\Http\Action\PublicProduct\GetPricingCustomersAction;
use TriCab\Api\Http\Action\PublicProduct\PostPricingCustomerContextAction;
use TriCab\Api\Http\Action\PublicProduct\PostPricingMarginOverrideAction;
use TriCab\Api\Http\Action\PublicProduct\SearchPublicProductCodesAction;
use TriCab\Api\Http\Action\PublicProduct\SearchPublicProductsAction;
use TriCab\Api\Http\Action\PublicCart\DeleteCartItemAction;
use TriCab\Api\Http\Action\PublicCart\GetCartAction;
use TriCab\Api\Http\Action\PublicCart\PatchCartItemAction;
use TriCab\Api\Http\Action\PublicCart\PostCartItemAction;
use TriCab\Api\Http\Action\PublicCart\PostCartRepriceAction;
use TriCab\Api\Http\Action\PublicRegionAction;
use TriCab\Api\Http\Action\PublicContent\GetPublicAboutAction;
use TriCab\Api\Http\Action\PublicContent\GetPublicContactAction;
use TriCab\Api\Http\Action\Technical\GetTechnicalArticleAction;
use TriCab\Api\Http\Action\Technical\GetTechnicalProductCodingAction;
use TriCab\Api\Http\Action\Technical\GetTechnicalTableAction;
use TriCab\Api\Http\Action\Technical\ListTechnicalDocumentsAction;
use TriCab\Api\Http\Action\Technical\ListTechnicalSectionsAction;
use TriCab\Api\Http\Action\PdfCatalogue\GetPdfCatalogueAction;
use TriCab\Api\Http\Action\PdfCatalogue\GetPdfCatalogueFileAction;
use TriCab\Api\Http\Action\PdfCatalogue\ListPdfCataloguesAction;
use TriCab\Api\Http\Action\PriceLookup\ListPriceLookupCodesAction;
use TriCab\Api\Http\Action\PriceLookup\QuotePriceLookupAction;
use TriCab\Api\Http\Action\PriceLookup\SearchPriceLookupAction;
use TriCab\Api\Http\Action\AssistantMessageAction;
use TriCab\Api\Http\Action\CableCalculator\PostCableCalculatorCalculateAction;
use TriCab\Api\Http\Action\ProductFinder\FindProductAction;
use TriCab\Api\Http\Action\Admin\UpdateRoleAction;
use TriCab\Api\Http\Action\Admin\UpdateUserAction;
use TriCab\Api\Http\Action\Admin\GetAdminDashboardAction;
use TriCab\Api\Http\Action\Admin\GetAdminDashboardProductActivityAction;
use TriCab\Api\Http\Action\DatabaseHealthAction;
use TriCab\Api\Http\Action\Dev\DevProductFileDeliveryAction;
use TriCab\Api\Http\Action\GetAuthenticatedUserAction;
use TriCab\Api\Http\Action\GenerateDocumentPdfAction;
use TriCab\Api\Http\Action\HealthAction;
use TriCab\Api\Http\Action\Onboarding\DeleteOnboardingContentItemAction;
use TriCab\Api\Http\Action\Onboarding\DeleteOnboardingPersonAction;
use TriCab\Api\Http\Action\Rfq\GetRfqAccessAction;
use TriCab\Api\Http\Action\Rfq\CreateRfqAction;
use TriCab\Api\Http\Action\Rfq\GetRfqAction;
use TriCab\Api\Http\Action\Rfq\ListRfqsAction;
use TriCab\Api\Http\Action\Rfq\SaveRfqAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingAdminOverviewAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingDocumentContentAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingDocumentsAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingItemAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingPeopleAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingPersonAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingPersonDocumentContentAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingPersonDocumentsAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingReviewItemAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingReportAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingReviewQueueAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingSelfSummaryAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingAttemptAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingAttemptSubmissionAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingFormSubmissionAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingPersonAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingPersonInvitationAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingPolicyAcknowledgementAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingPresentationProgressAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingReviewDecisionAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingFormContributionAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingFormPhotoAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingFormPhotoAction;
use TriCab\Api\Http\Action\Onboarding\PutOnboardingFormContributionAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingManagerFormsAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingManagerFormAction;
use TriCab\Api\Http\Action\Onboarding\PutOnboardingManagerContributionAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingCompletionFeedAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingContentAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingContentItemAction;
use TriCab\Api\Http\Action\Onboarding\GetOnboardingContentVersionAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingContentDraftAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingContentItemAction;
use TriCab\Api\Http\Action\Onboarding\PostOnboardingContentPublishAction;
use TriCab\Api\Http\Action\Onboarding\PutOnboardingContentItemAction;
use TriCab\Api\Http\Action\Onboarding\PutOnboardingContentVersionAction;
use TriCab\Api\Http\Action\Onboarding\PutOnboardingFormDraftAction;
use TriCab\Api\Http\Action\Onboarding\PutOnboardingPersonAllocationAction;
use TriCab\Api\Http\Action\Onboarding\PutOnboardingPersonProfileAction;
use TriCab\Api\Http\Action\LogoutGuestAction;
use TriCab\Api\Http\Action\TrustedDevice\ExchangeTrustedDeviceSessionAction;
use TriCab\Api\Http\Action\TrustedDevice\RegisterTrustedDeviceAction;
use TriCab\Api\Http\Action\TrustedDevice\RevokeTrustedDeviceAction;
use TriCab\Api\Http\Action\RequestGuestOtpAction;
use TriCab\Api\Http\Action\AuthenticateGoogleAction;
use TriCab\Api\Http\Action\AppleAuthChallengeAction;
use TriCab\Api\Http\Action\AuthenticateAppleAction;
use TriCab\Api\Http\Action\AuthenticateAppleNativeAction;
use TriCab\Api\Http\Action\VerifyGuestOtpAction;
use TriCab\Api\Http\Action\Passkey\ListPasskeysAction;
use TriCab\Api\Http\Action\Passkey\PasskeyAuthenticationCompleteAction;
use TriCab\Api\Http\Action\Passkey\PasskeyAuthenticationOptionsAction;
use TriCab\Api\Http\Action\Passkey\PasskeyDiagnosticsAction;
use TriCab\Api\Http\Action\Passkey\PasskeyRegistrationCompleteAction;
use TriCab\Api\Http\Action\Passkey\PasskeyRegistrationOptionsAction;
use TriCab\Api\Http\Action\Passkey\RenamePasskeyAction;
use TriCab\Api\Http\Action\Passkey\RequestPasskeySetupLinkAction;
use TriCab\Api\Http\Action\Passkey\RevokePasskeyAction;
use TriCab\Api\Http\Action\Passkey\VerifyPasskeySetupLinkAction;
use TriCab\Api\Http\Auth\RequirePermission;
use TriCab\Api\Http\Action\TriExpense\ApproveExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\CheckExpenseDuplicateAction;
use TriCab\Api\Http\Action\TriExpense\CompleteExpenseAttachmentAction;
use TriCab\Api\Http\Action\TriExpense\CreateExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\Finance\CloseExpensePeriodAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ExportEmployeeReceiptsCsvAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ExportFinanceReconciliationCsvAction;
use TriCab\Api\Http\Action\TriExpense\Finance\GetExpenseBankFeedAction;
use TriCab\Api\Http\Action\TriExpense\Finance\GetExpenseFinanceEmployeeAction;
use TriCab\Api\Http\Action\TriExpense\Finance\GetExpenseFinanceWorkspaceAction;
use TriCab\Api\Http\Action\TriExpense\Finance\GetExpenseReconciliationAction;
use TriCab\Api\Http\Action\TriExpense\Finance\GetOrCreateExpensePeriodAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ImportExpenseBankFeedAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ListExpenseAuditAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ListExpenseBankFeedCandidatesAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ListExpenseFinanceBankTransactionsAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ListExpensePeriodsAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ManualMatchExpenseBankFeedAction;
use TriCab\Api\Http\Action\TriExpense\Finance\OffsetExpenseBankFeedAction;
use TriCab\Api\Http\Action\TriExpense\Finance\PreCloseExpensePeriodAction;
use TriCab\Api\Http\Action\TriExpense\Finance\PrepareManagerReminderAction;
use TriCab\Api\Http\Action\TriExpense\Finance\PrepareMissingReceiptRemindersAction;
use TriCab\Api\Http\Action\TriExpense\Finance\RenderFinanceFullPdfAction;
use TriCab\Api\Http\Action\TriExpense\Finance\RenderFinanceSummaryPdfAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ResolveExpenseBankFeedAction;
use TriCab\Api\Http\Action\TriExpense\Finance\ResolveExpenseReceiptFinanceAction;
use TriCab\Api\Http\Action\TriExpense\Finance\RunExpenseBankFeedMatchingAction;
use TriCab\Api\Http\Action\TriExpense\Finance\UndoOffsetExpenseBankFeedAction;
use TriCab\Api\Http\Action\TriExpense\GetExpenseAttachmentFileAction;
use TriCab\Api\Http\Action\TriExpense\GetExpenseConfigAction;
use TriCab\Api\Http\Action\TriExpense\GetExpenseCustomerContactsAction;
use TriCab\Api\Http\Action\TriExpense\GetExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\InitiateExpenseAttachmentAction;
use TriCab\Api\Http\Action\TriExpense\ListExpenseApprovalsAction;
use TriCab\Api\Http\Action\TriExpense\ListExpenseProfilesAdminAction;
use TriCab\Api\Http\Action\TriExpense\ListExpenseAdminUsersAction;
use TriCab\Api\Http\Action\TriExpense\GetExpenseAdminOverviewAction;
use TriCab\Api\Http\Action\TriExpense\SetExpenseUserTeRolesAction;
use TriCab\Api\Http\Action\TriExpense\SearchExpenseAdminEmployeesAction;
use TriCab\Api\Http\Action\TriExpense\DryCheckExpenseLegacyApproverMappingAction;
use TriCab\Api\Http\Action\TriExpense\ListExpenseReceiptsAction;
use TriCab\Api\Http\Action\TriExpense\PurgeExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\RejectExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\RestoreExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\ExtractExpenseReceiptDraftAction;
use TriCab\Api\Http\Action\TriExpense\RunExpenseReceiptOcrAction;
use TriCab\Api\Http\Action\TriExpense\SearchExpenseCustomersAction;
use TriCab\Api\Http\Action\TriExpense\SendBackExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\SoftDeleteExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\SubmitExpenseReceiptAction;
use TriCab\Api\Http\Action\TriExpense\UpdateExpenseProfileApproverAction;
use TriCab\Api\Http\Action\TriExpense\UpdateExpenseReceiptAction;
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

        // Added first, so it sits closest to the route: it runs after Bearer/Development
        // authentication and after routing (it needs the matched route), but before any
        // per-route middleware, so RequirePermissionMiddleware checks the target user.
        $app->add(new ActAsMiddleware($container));

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
        if ($settings->customerContactE2eMode) {
            $app->add(new \TriCab\Api\Http\Middleware\CustomerContactE2eFailPutMiddleware());
        }
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

            $requireGuest = new RequirePermission($container);
            $app->post('/api/v1/auth/devices/register', RegisterTrustedDeviceAction::class)
                ->add(($requireGuest)('ACCOUNT.LOGIN'));
            $app->post('/api/v1/auth/devices/session', ExchangeTrustedDeviceSessionAction::class);
            $app->delete('/api/v1/auth/devices/current', RevokeTrustedDeviceAction::class)
                ->add(($requireGuest)('ACCOUNT.LOGIN'));
        }

        if ($settings->isGoogleAuthConfigured()) {
            $app->post('/api/v1/auth/google', AuthenticateGoogleAction::class);
        }

        if ($settings->isAppleAuthConfigured()) {
            $app->get('/api/v1/auth/apple/challenge', AppleAuthChallengeAction::class);
            $app->post('/api/v1/auth/apple', AuthenticateAppleAction::class);
        }

        if ($settings->isAppleNativeAuthConfigured()) {
            $app->post('/api/v1/auth/apple/native', AuthenticateAppleNativeAction::class);
        }

        if ($settings->isPasskeyAuthConfigured()) {
            $app->post('/api/v1/auth/guest/passkey/setup-link', RequestPasskeySetupLinkAction::class);
            $app->post('/api/v1/auth/guest/passkey/setup-link/verify', VerifyPasskeySetupLinkAction::class);
            $app->post('/api/v1/auth/passkeys/registration/options', PasskeyRegistrationOptionsAction::class);
            $app->post('/api/v1/auth/passkeys/registration/complete', PasskeyRegistrationCompleteAction::class);
            $app->post('/api/v1/auth/passkeys/authentication/options', PasskeyAuthenticationOptionsAction::class);
            $app->post('/api/v1/auth/passkeys/authentication/complete', PasskeyAuthenticationCompleteAction::class);
            $require = new RequirePermission($container);
            $app->get('/api/v1/auth/passkeys', ListPasskeysAction::class)->add(($require)('ACCOUNT.LOGIN'));
            $app->patch('/api/v1/auth/passkeys/{publicId}', RenamePasskeyAction::class)->add(($require)('ACCOUNT.LOGIN'));
            $app->delete('/api/v1/auth/passkeys/{publicId}', RevokePasskeyAction::class)->add(($require)('ACCOUNT.LOGIN'));
            if ($settings->isDevelopment()) {
                $app->get('/api/v1/dev/passkey-diagnostics', PasskeyDiagnosticsAction::class);
            }
        }

        if ($settings->isDevMemoryProductFileDeliveryEnabled()) {
            $app->get('/api/v1/dev/product-files/{filePublicId}', DevProductFileDeliveryAction::class);
        }

        self::registerPublicProductRoutes($app);
        self::registerTriExpenseRoutes($app, $container);
        self::registerQuoteOrderRoutes($app, $container);
        self::registerLogNoteRoutes($app, $container);
        self::registerTelDirectoryRoutes($app, $container);
        self::registerPdfCatalogueRoutes($app, $container);
        self::registerPriceLookupRoutes($app, $container);
        self::registerTechnicalRoutes($app, $container);
        $app->get('/api/v1/regions', PublicRegionAction::class);
        $app->get('/api/v1/regions/current', PublicRegionAction::class);
        $app->post('/api/v1/regions/select', PublicRegionAction::class);
        $app->get('/api/v1/public/about', GetPublicAboutAction::class);
        $app->get('/api/v1/public/contact', GetPublicContactAction::class);
        self::registerOnboardingRoutes($app, $container);
        self::registerRfqRoutes($app, $container);
        self::registerNprRoutes($app, $container);
        self::registerCableFitRoutes($app, $container);
        if ($settings->mailModuleEnabled) {
            self::registerMailRoutes($app, $container);
        }
        if ($settings->customerContactModuleEnabled) {
            self::registerCustomerContactReportRoutes($app, $container);
        }
        if ($settings->financeModuleEnabled) {
            self::registerFinanceRoutes($app, $container);
        }
        self::registerAdminRoutes($app, $container);
    }

    /**
     * CableFit candidate designs for TSD. Every candidate route holds `TSD.CABLEFIT.USE`.
     * The research routes (C3) hold USE to read and `TSD.CABLEFIT.RESEARCH` to write.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerCableFitRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $use = \TriCab\Core\CableFit\Module::PERMISSION_USE;

        $app->get('/api/v1/tsd/cablefit/candidates', \TriCab\Api\Http\Action\CableFit\ListCableFitCandidatesAction::class)
            ->add(($require)($use));
        $app->post('/api/v1/tsd/cablefit/candidates', \TriCab\Api\Http\Action\CableFit\CreateCableFitCandidateAction::class)
            ->add(($require)($use));
        $app->get('/api/v1/tsd/cablefit/candidates/{publicId}', \TriCab\Api\Http\Action\CableFit\GetCableFitCandidateAction::class)
            ->add(($require)($use));
        $app->put('/api/v1/tsd/cablefit/candidates/{publicId}', \TriCab\Api\Http\Action\CableFit\SaveCableFitCandidateAction::class)
            ->add(($require)($use));
        // Creator or Administrator only; the service checks.
        $app->delete('/api/v1/tsd/cablefit/candidates/{publicId}', \TriCab\Api\Http\Action\CableFit\DeleteCableFitCandidateAction::class)
            ->add(($require)($use));

        // Research agent (C3): advisory only. Reads need USE; queueing a run and editing sources need RESEARCH.
        $research = \TriCab\Core\CableFit\Module::PERMISSION_RESEARCH;
        $app->get('/api/v1/tsd/cablefit/research-sources', \TriCab\Api\Http\Action\CableFit\ListResearchSourcesAction::class)
            ->add(($require)($use));
        $app->post('/api/v1/tsd/cablefit/research-sources', \TriCab\Api\Http\Action\CableFit\CreateResearchSourceAction::class)
            ->add(($require)($research));
        $app->put('/api/v1/tsd/cablefit/research-sources/{publicId}', \TriCab\Api\Http\Action\CableFit\UpdateResearchSourceAction::class)
            ->add(($require)($research));
        $app->post('/api/v1/tsd/cablefit/candidates/{candidatePublicId}/research', \TriCab\Api\Http\Action\CableFit\RequestResearchRunAction::class)
            ->add(($require)($research));
        $app->get('/api/v1/tsd/cablefit/candidates/{candidatePublicId}/research', \TriCab\Api\Http\Action\CableFit\ListResearchRunsAction::class)
            ->add(($require)($use));
        $app->get('/api/v1/tsd/cablefit/candidates/{candidatePublicId}/research/{runPublicId}', \TriCab\Api\Http\Action\CableFit\GetResearchRunAction::class)
            ->add(($require)($use));
    }

    /**
     * Mail review inbox (inbox plan I2). Registered only when MAIL_MODULE_ENABLED is true; off, every path
     * under /api/v1/mail is a 404. Every route carries exactly one permission, pinned by `MailRouteTableTest`.
     * The review route holds `MAIL.ACCESS` because marking read or unread needs no more; its service demands
     * `MAIL.REVIEW` for the other actions. Literal paths are registered before any `{id}` path.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerMailRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $access = \TriCab\Core\Mail\Module::PERMISSION_ACCESS;

        $app->get('/api/v1/mail/me', \TriCab\Api\Http\Action\Mail\GetMailMeAction::class)
            ->add(($require)($access));
        $app->get('/api/v1/mail/export', \TriCab\Api\Http\Action\Mail\ExportMailLabelsAction::class)
            ->add(($require)(\TriCab\Core\Mail\Module::PERMISSION_REVIEW));
        $app->get('/api/v1/mail/queues', \TriCab\Api\Http\Action\Mail\ListMailQueuesAction::class)
            ->add(($require)($access));
        $app->get('/api/v1/mail/threads', \TriCab\Api\Http\Action\Mail\ListMailThreadsAction::class)
            ->add(($require)($access));
        $app->get('/api/v1/mail/threads/{id}', \TriCab\Api\Http\Action\Mail\GetMailThreadAction::class)
            ->add(($require)($access));
        $app->post('/api/v1/mail/threads/{id}/review', \TriCab\Api\Http\Action\Mail\ReviewMailThreadAction::class)
            ->add(($require)($access));
        $app->delete('/api/v1/mail/threads/{id}', \TriCab\Api\Http\Action\Mail\DeleteMailThreadAction::class)
            ->add(($require)(\TriCab\Core\Mail\Module::PERMISSION_REVIEW));
        $app->get('/api/v1/mail/messages/{id}/body', \TriCab\Api\Http\Action\Mail\GetMailMessageBodyAction::class)
            ->add(($require)($access));
        $app->get('/api/v1/mail/attachments/{id}', \TriCab\Api\Http\Action\Mail\DownloadMailAttachmentAction::class)
            ->add(($require)($access));
    }

    /**
     * Finance (the five finance apps). Registered only when
     * FINANCE_MODULE_ENABLED is true; off, every path under /api/v1/finance is a
     * 404. Each app registers its own routes behind its own switch, in the
     * phase that builds it. Every route carries exactly one permission, pinned
     * by `FinanceRouteTableTest`.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerFinanceRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $app->get('/api/v1/finance/me', \TriCab\Api\Http\Action\Finance\GetFinanceAccessAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_ACCESS));
        $app->get('/api/v1/finance/admin/mailboxes', \TriCab\Api\Http\Action\Finance\ListFinanceMailboxesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_ADMIN));
        // Odoo reference lists (F1.12). `status` is registered before `{list}` so it is never read as a list key.
        $app->post('/api/v1/finance/reference/sync', \TriCab\Api\Http\Action\Finance\SyncOdooReferenceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_ADMIN));
        $app->get('/api/v1/finance/reference/status', \TriCab\Api\Http\Action\Finance\GetOdooReferenceStatusAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_ADMIN));
        $app->get('/api/v1/finance/reference/{list}', \TriCab\Api\Http\Action\Finance\ListOdooReferenceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_ACCESS));

        $settings = $container->get(Settings::class);
        if ($settings instanceof Settings && $settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_TASK)) {
            self::registerFinanceTaskRoutes($app, $container);
        }
        if ($settings instanceof Settings && $settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_AR)) {
            self::registerFinanceArRoutes($app, $container);
        }
        if ($settings instanceof Settings && $settings->financeAppEnabled(\TriCab\Core\Finance\Module::APP_AP)) {
            self::registerFinanceApRoutes($app, $container);
        }
    }

    /**
     * Tri-AP (Phase 6), under `/api/v1/finance/ap`. Registered only when FINANCE_MODULE_ENABLED and
     * FINANCE_AP_ENABLED are both true. Every route carries exactly one `FINANCE.AP.*` permission
     * (question Q1), pinned by `FinanceRouteTableTest`. A route that reads or changes an invoice or an
     * email also applies the person's mailbox scope in its use case; out of scope is a 404 (Q11).
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerFinanceApRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $ap = '/api/v1/finance/ap';

        // The person's own summary, the mailbox registry's view and the signing limits (T6.3).
        $app->get($ap . '/me', \TriCab\Api\Http\Action\Finance\Ap\GetApMeAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_ACCESS));
        $app->get($ap . '/mailboxes', \TriCab\Api\Http\Action\Finance\Ap\ListApMailboxesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->get($ap . '/signing-authority', \TriCab\Api\Http\Action\Finance\Ap\ListApSigningAuthorityAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));

        // Tri-AP: vendors and extraction (T6.8, T6.9). The vendor list is the platform's copy of Odoo's (not mailbox-scoped);
        // the routes that name an invoice apply the person's mailbox scope, and out of scope is a 404.
        $app->get($ap . '/vendors', \TriCab\Api\Http\Action\Finance\Ap\ListApVendorsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->get($ap . '/invoices/{id}/vendor-suggestions', \TriCab\Api\Http\Action\Finance\Ap\GetApInvoiceVendorSuggestionsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->put($ap . '/invoices/{id}/vendor-match', \TriCab\Api\Http\Action\Finance\Ap\SetApInvoiceVendorMatchAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));
        $app->post($ap . '/vendor-matching/run', \TriCab\Api\Http\Action\Finance\Ap\RunApVendorMatchingAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));
        $app->post($ap . '/extract', \TriCab\Api\Http\Action\Finance\Ap\ExtractApInvoiceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));

        // Tri-AP: scan (T6.7). One route, three modes (list, process, complete). The use case applies the person's mailbox
        // scope and the mailbox read grant; a period already scanned is a 409 carrying `priorRuns`.
        $app->post($ap . '/scan', \TriCab\Api\Http\Action\Finance\Ap\PostApScanAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_READ));

        // The register and its files (T6.4, T6.5). The use cases apply the person's mailbox scope; out of scope is a 404.
        // A permanent delete needs INVOICES.DELETE as well as the route's INVOICES.WRITE (checked in the use case).
        $app->get($ap . '/invoices', \TriCab\Api\Http\Action\Finance\Ap\ListApInvoicesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->post($ap . '/invoices', \TriCab\Api\Http\Action\Finance\Ap\CreateApInvoiceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));
        $app->post($ap . '/invoices/download', \TriCab\Api\Http\Action\Finance\Ap\DownloadApInvoicesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->get($ap . '/invoices/{id}', \TriCab\Api\Http\Action\Finance\Ap\GetApInvoiceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->patch($ap . '/invoices/{id}', \TriCab\Api\Http\Action\Finance\Ap\UpdateApInvoiceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));
        $app->delete($ap . '/invoices/{id}', \TriCab\Api\Http\Action\Finance\Ap\DeleteApInvoiceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));
        $app->post($ap . '/invoices/{id}/restore', \TriCab\Api\Http\Action\Finance\Ap\RestoreApInvoiceAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));
        $app->get($ap . '/invoices/{id}/file', \TriCab\Api\Http\Action\Finance\Ap\GetApInvoiceFileAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->post($ap . '/uploads', \TriCab\Api\Http\Action\Finance\Ap\UploadApFileAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));

        // Companies, cost centres and account categories: one set of actions, told the list by the route.
        foreach (\TriCab\Core\Finance\Ap\Domain\ApReferenceSpec::LISTS as $list) {
            $app->get($ap . '/' . $list, \TriCab\Api\Http\Action\Finance\Ap\ListApReferenceAction::class)
                ->setArgument('list', $list)
                ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
            $app->post($ap . '/' . $list, \TriCab\Api\Http\Action\Finance\Ap\CreateApReferenceAction::class)
                ->setArgument('list', $list)
                ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_SETTINGS_MANAGE));
            $app->patch($ap . '/' . $list . '/{id}', \TriCab\Api\Http\Action\Finance\Ap\UpdateApReferenceAction::class)
                ->setArgument('list', $list)
                ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_SETTINGS_MANAGE));
            $app->delete($ap . '/' . $list . '/{id}', \TriCab\Api\Http\Action\Finance\Ap\DeleteApReferenceAction::class)
                ->setArgument('list', $list)
                ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_SETTINGS_MANAGE));
        }

        // Tri-AP: AP Mailbox (T6.6)
        // The emails, their counts, the live body and attachments, reclassify and the bulk actions. The use cases apply the
        // person's mailbox scope; out of scope is a 404. `stats` and `bulk` are static, so `{id}` never reads them.
        $app->get($ap . '/emails', \TriCab\Api\Http\Action\Finance\Ap\ListApEmailsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_READ));
        $app->get($ap . '/emails/stats', \TriCab\Api\Http\Action\Finance\Ap\GetApEmailStatsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_READ));
        $app->post($ap . '/emails/bulk', \TriCab\Api\Http\Action\Finance\Ap\BulkApEmailsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));
        $app->get($ap . '/emails/{id}/body', \TriCab\Api\Http\Action\Finance\Ap\GetApEmailBodyAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_READ));
        $app->get($ap . '/emails/{id}/attachments', \TriCab\Api\Http\Action\Finance\Ap\ListApEmailAttachmentsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_READ));
        $app->get($ap . '/emails/{id}/attachments/{aid}/file', \TriCab\Api\Http\Action\Finance\Ap\GetApEmailAttachmentFileAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_READ));
        $app->patch($ap . '/emails/{id}', \TriCab\Api\Http\Action\Finance\Ap\UpdateApEmailAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_WRITE));

        // Tri-AP: mail (T6.10)
        // The composer: the mailboxes to send from, the AI drafts (they work while sending is off), the two sends (refused with a
        // 503 while FINANCE_AP_SENDING_ENABLED is off) and what has been sent about an invoice or an email.
        $app->get($ap . '/send-mailboxes', \TriCab\Api\Http\Action\Finance\Ap\ListApSendMailboxesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->post($ap . '/invoices/{id}/email-draft', \TriCab\Api\Http\Action\Finance\Ap\DraftApInvoiceEmailAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_SEND));
        $app->post($ap . '/emails/{id}/email-draft', \TriCab\Api\Http\Action\Finance\Ap\DraftApEmailReplyAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_SEND));
        $app->post($ap . '/invoices/{id}/send-email', \TriCab\Api\Http\Action\Finance\Ap\SendApVendorEmailAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_SEND));
        $app->post($ap . '/emails/{id}/send-reply', \TriCab\Api\Http\Action\Finance\Ap\SendApEmailReplyAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_MAIL_SEND));
        $app->get($ap . '/invoices/{id}/outbound-messages', \TriCab\Api\Http\Action\Finance\Ap\ListApInvoiceOutboundMessagesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->get($ap . '/emails/{id}/outbound-messages', \TriCab\Api\Http\Action\Finance\Ap\ListApEmailOutboundMessagesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));

        // Tri-AP: approvals (T6.11)
        // The people who may decide, the batches and their decisions. The list and the read apply the person's mailbox scope (a
        // batch with an invoice out of scope is not shown); so does the decision, which the source did not do (fault R7). Approval
        // mail obeys FINANCE_AP_SENDING_ENABLED (decision D80): while it is off the work is still recorded and the answer says so.
        $app->get($ap . '/approvers', \TriCab\Api\Http\Action\Finance\Ap\ListApApproversAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->get($ap . '/approval-batches', \TriCab\Api\Http\Action\Finance\Ap\ListApApprovalBatchesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->post($ap . '/approval-batches', \TriCab\Api\Http\Action\Finance\Ap\CreateApApprovalBatchAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_APPROVALS_PREPARE));
        $app->get($ap . '/approval-batches/{id}', \TriCab\Api\Http\Action\Finance\Ap\GetApApprovalBatchAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_INVOICES_READ));
        $app->post($ap . '/approval-batches/{id}/decision', \TriCab\Api\Http\Action\Finance\Ap\DecideApApprovalBatchAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AP_APPROVALS_DECIDE));
    }

    /**
     * Tri-Task (Phase 2), under `/api/v1/finance/task`. Registered only when
     * FINANCE_MODULE_ENABLED and FINANCE_TASK_ENABLED are both true. Every route carries
     * exactly one `FINANCE.TASK.*` permission, pinned by `FinanceRouteTableTest`; the rules
     * that need the record (own versus any task, who may see a task) live in the use cases
     * (decisions D14, D15).
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerFinanceTaskRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $task = '/api/v1/finance/task';

        // Members, the person's own summary, and job titles (T2.5).
        $app->get($task . '/me', \TriCab\Api\Http\Action\Finance\Task\GetTaskMeAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_ACCESS));
        $app->get($task . '/members', \TriCab\Api\Http\Action\Finance\Task\ListTaskMembersAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->patch($task . '/members/{userId}/profile', \TriCab\Api\Http\Action\Finance\Task\PatchTaskMemberProfileAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));

        // Projects, templates, statement areas and entities (T2.3).
        $app->get($task . '/projects', \TriCab\Api\Http\Action\Finance\Task\ListTaskProjectsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/projects', \TriCab\Api\Http\Action\Finance\Task\CreateTaskProjectAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->patch($task . '/projects/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskProjectAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->delete($task . '/projects/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskProjectAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->post($task . '/projects/{id}/restore', \TriCab\Api\Http\Action\Finance\Task\RestoreTaskProjectAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->get($task . '/templates', \TriCab\Api\Http\Action\Finance\Task\ListTaskTemplatesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/templates', \TriCab\Api\Http\Action\Finance\Task\CreateTaskTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->patch($task . '/templates/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->delete($task . '/templates/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->get($task . '/statement-areas', \TriCab\Api\Http\Action\Finance\Task\ListTaskStatementAreasAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/statement-areas', \TriCab\Api\Http\Action\Finance\Task\CreateTaskStatementAreaAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->post($task . '/statement-areas/bulk', \TriCab\Api\Http\Action\Finance\Task\BulkCreateTaskStatementAreasAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->patch($task . '/statement-areas/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskStatementAreaAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->delete($task . '/statement-areas/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskStatementAreaAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->get($task . '/entities', \TriCab\Api\Http\Action\Finance\Task\ListTaskEntitiesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/entities', \TriCab\Api\Http\Action\Finance\Task\CreateTaskEntityAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->patch($task . '/entities/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskEntityAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->delete($task . '/entities/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskEntityAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));

        // The project board (13 Oct 2026): a project's task groups, and project templates (read: TASKS.READ; write: SETUP.MANAGE).
        $app->get($task . '/projects/{id}/groups', \TriCab\Api\Http\Action\Finance\Task\ListTaskGroupsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/projects/{id}/groups', \TriCab\Api\Http\Action\Finance\Task\CreateTaskGroupAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->patch($task . '/groups/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskGroupAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->delete($task . '/groups/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskGroupAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->post($task . '/projects/{id}/task-order', \TriCab\Api\Http\Action\Finance\Task\OrderTaskBoardTasksAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->post($task . '/projects/{id}/save-as-template', \TriCab\Api\Http\Action\Finance\Task\SaveTaskProjectAsTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->get($task . '/project-templates', \TriCab\Api\Http\Action\Finance\Task\ListTaskProjectTemplatesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/project-templates', \TriCab\Api\Http\Action\Finance\Task\CreateTaskProjectTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->patch($task . '/project-templates/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskProjectTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->delete($task . '/project-templates/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskProjectTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->post($task . '/project-templates/{id}/apply', \TriCab\Api\Http\Action\Finance\Task\ApplyTaskProjectTemplateAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));

        // Project groups on the Projects page (Nick Han, 19 Oct 2026; read: TASKS.READ; write: SETUP.MANAGE).
        $app->get($task . '/project-groups', \TriCab\Api\Http\Action\Finance\Task\ListTaskProjectGroupsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/project-groups', \TriCab\Api\Http\Action\Finance\Task\CreateTaskProjectGroupAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->patch($task . '/project-groups/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskProjectGroupAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->delete($task . '/project-groups/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskProjectGroupAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));

        // Tasks, dependencies, history, the Bin and analytics (T2.4). One permission each; own versus any is the use case's (D14, D15).
        $app->get($task . '/tasks', \TriCab\Api\Http\Action\Finance\Task\ListTasksAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->get($task . '/tasks/{id}', \TriCab\Api\Http\Action\Finance\Task\GetTaskAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/tasks', \TriCab\Api\Http\Action\Finance\Task\CreateTaskAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_CREATE));
        $app->patch($task . '/tasks/{id}', \TriCab\Api\Http\Action\Finance\Task\UpdateTaskAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_UPDATE_OWN));
        $app->post($task . '/tasks/bulk', \TriCab\Api\Http\Action\Finance\Task\BulkUpdateTasksAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_UPDATE_OWN));
        $app->post($task . '/tasks/bulk-delete', \TriCab\Api\Http\Action\Finance\Task\BulkDeleteTasksAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_DELETE_OWN));
        $app->post($task . '/tasks/bulk-restore', \TriCab\Api\Http\Action\Finance\Task\BulkRestoreTasksAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_DELETE_OWN));
        $app->delete($task . '/tasks/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_DELETE_OWN));
        $app->post($task . '/tasks/{id}/restore', \TriCab\Api\Http\Action\Finance\Task\RestoreTaskAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_DELETE_OWN));
        $app->post($task . '/tasks/{id}/request-review', \TriCab\Api\Http\Action\Finance\Task\RequestTaskReviewAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_UPDATE_OWN));
        $app->get($task . '/tasks/{id}/history', \TriCab\Api\Http\Action\Finance\Task\GetTaskHistoryAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->get($task . '/history', \TriCab\Api\Http\Action\Finance\Task\ListTaskHistoryAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_ANALYTICS_READ));
        $app->get($task . '/analytics', \TriCab\Api\Http\Action\Finance\Task\GetTaskAnalyticsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_ANALYTICS_READ));

        // Task mail: the mailboxes the person may send from, the send, and what was sent about a task (T2.6).
        $app->get($task . '/send-mailboxes', \TriCab\Api\Http\Action\Finance\Task\ListTaskSendMailboxesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_MAIL_SEND));
        $app->post($task . '/mail/send', \TriCab\Api\Http\Action\Finance\Task\SendTaskMailAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_MAIL_SEND));
        $app->get($task . '/tasks/{id}/mail', \TriCab\Api\Http\Action\Finance\Task\ListTaskMailAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));

        // The daily summary recipients (T2.9).
        $app->get($task . '/settings/report-recipients', \TriCab\Api\Http\Action\Finance\Task\ListTaskReportRecipientsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETTINGS_MANAGE));
        $app->post($task . '/settings/report-recipients', \TriCab\Api\Http\Action\Finance\Task\AddTaskReportRecipientAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETTINGS_MANAGE));
        $app->delete($task . '/settings/report-recipients/{id}', \TriCab\Api\Http\Action\Finance\Task\DeleteTaskReportRecipientAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETTINGS_MANAGE));

        // The spreadsheet import: a dry run by default (T2.8). Projects it creates need SETUP.MANAGE inside.
        $app->post($task . '/import', \TriCab\Api\Http\Action\Finance\Task\ImportTasksAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_CREATE));

        // The five AI drafting routes, through finance_ai (T2.7).
        $app->post($task . '/ai/task-details', \TriCab\Api\Http\Action\Finance\Task\TaskAiDetailsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_CREATE));
        $app->post($task . '/ai/statement-areas', \TriCab\Api\Http\Action\Finance\Task\TaskAiStatementAreasAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_SETUP_MANAGE));
        $app->post($task . '/ai/task-emails', \TriCab\Api\Http\Action\Finance\Task\TaskAiTaskEmailsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_MAIL_SEND));
        $app->post($task . '/ai/reminders', \TriCab\Api\Http\Action\Finance\Task\TaskAiRemindersAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_TASKS_READ));
        $app->post($task . '/ai/email-draft', \TriCab\Api\Http\Action\Finance\Task\TaskAiEmailDraftAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_TASK_MAIL_SEND));
    }

    /**
     * Tri-AR (Phase 5), under `/api/v1/finance/ar`. Registered only when FINANCE_MODULE_ENABLED and
     * FINANCE_AR_ENABLED are both true. Every route carries exactly one `FINANCE.AR.*` permission,
     * pinned by `FinanceRouteTableTest`, except the public credit-note form (T5.10): its two routes
     * are the only ones with none, by design (question Q7), and the same test pins that too.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerFinanceArRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $ar = '/api/v1/finance/ar';

        // The person's own summary, the assignees, and the Category and Method lists (T5.3).
        $app->get($ar . '/me', \TriCab\Api\Http\Action\Finance\Ar\GetArMeAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_ACCESS));
        $app->get($ar . '/assignees', \TriCab\Api\Http\Action\Finance\Ar\ListArAssigneesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->get($ar . '/settings/options', \TriCab\Api\Http\Action\Finance\Ar\ListArOptionsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->post($ar . '/settings/options/{list}', \TriCab\Api\Http\Action\Finance\Ar\CreateArOptionAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_ADMIN));
        // `order` is registered before `{value}` so it is never read as an option's value.
        $app->put($ar . '/settings/options/{list}/order', \TriCab\Api\Http\Action\Finance\Ar\ReorderArOptionsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_ADMIN));
        $app->patch($ar . '/settings/options/{list}/{value}', \TriCab\Api\Http\Action\Finance\Ar\UpdateArOptionAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_ADMIN));
        $app->delete($ar . '/settings/options/{list}/{value}', \TriCab\Api\Http\Action\Finance\Ar\DeleteArOptionAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_ADMIN));

        // The register: list, one record, edit, the Bin and the tiles (T5.4). Attachment files, the
        // original-message body, scanning, mail and credit-note requests come with their own tasks.
        $app->get($ar . '/records', \TriCab\Api\Http\Action\Finance\Ar\ListArRecordsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->get($ar . '/records/{id}', \TriCab\Api\Http\Action\Finance\Ar\GetArRecordAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->patch($ar . '/records/{id}', \TriCab\Api\Http\Action\Finance\Ar\UpdateArRecordAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));
        $app->delete($ar . '/records/{id}', \TriCab\Api\Http\Action\Finance\Ar\DeleteArRecordAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));
        $app->post($ar . '/records/{id}/restore', \TriCab\Api\Http\Action\Finance\Ar\RestoreArRecordAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));
        $app->get($ar . '/dashboard/metrics', \TriCab\Api\Http\Action\Finance\Ar\GetArMetricsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));

        // The original message in the app's viewer (T5.6): read access, then the mailbox grant behind it.
        $app->get($ar . '/records/{id}/body', \TriCab\Api\Http\Action\Finance\Ar\GetArRecordBodyAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));

        // The AI-drafted reply (T5.8).
        $app->post($ar . '/records/{id}/email-draft', \TriCab\Api\Http\Action\Finance\Ar\DraftArEmailAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));

        // The reply composer (T5.9): the mailboxes to send from, what has gone to a customer, and the send itself.
        $app->get($ar . '/send-mailboxes', \TriCab\Api\Http\Action\Finance\Ar\ListArSendMailboxesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->get($ar . '/records/{id}/outbound-messages', \TriCab\Api\Http\Action\Finance\Ar\ListArOutboundMessagesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->post($ar . '/records/{id}/send-response', \TriCab\Api\Http\Action\Finance\Ar\SendArResponseAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));

        // Credit-note requests (T5.10): list, one, create (the link is returned once), edit, and send the link again.
        $app->get($ar . '/credit-notes', \TriCab\Api\Http\Action\Finance\Ar\ListArCreditNotesAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->get($ar . '/credit-notes/{id}', \TriCab\Api\Http\Action\Finance\Ar\GetArCreditNoteAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));
        $app->post($ar . '/credit-notes', \TriCab\Api\Http\Action\Finance\Ar\CreateArCreditNoteAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));
        $app->patch($ar . '/credit-notes/{id}', \TriCab\Api\Http\Action\Finance\Ar\UpdateArCreditNoteAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));
        $app->post($ar . '/credit-notes/{id}/resend', \TriCab\Api\Http\Action\Finance\Ar\ResendArCreditNoteAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));

        // The sales reps' credit-note form: the plan's one exception to "every finance route has a permission" (question Q7).
        // No sign-in and no permission: the link's token, its expiry, single use and a rate limit stand in for them, and
        // FinanceRouteTableTest pins that these two are the only such routes.
        $app->get($ar . '/public/credit-note-form', \TriCab\Api\Http\Action\Finance\Ar\GetArPublicFormAction::class);
        $app->post($ar . '/public/credit-note-form', \TriCab\Api\Http\Action\Finance\Ar\SubmitArPublicFormAction::class);

        // Scanning (T5.7): the operator's three modes, and the run history. The daily scan is a job, not a route.
        $app->post($ar . '/scan', \TriCab\Api\Http\Action\Finance\Ar\PostArScanAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_WRITE));
        $app->get($ar . '/scan-runs', \TriCab\Api\Http\Action\Finance\Ar\ListArScanRunsAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_RECORDS_READ));

        // A kept file (T5.5): its own permission (Q5), then the record guard.
        $app->get($ar . '/records/{id}/attachments/{aid}/file', \TriCab\Api\Http\Action\Finance\Ar\GetArAttachmentFileAction::class)
            ->add(($require)(\TriCab\Core\Finance\Module::PERMISSION_AR_ATTACHMENTS_READ));
    }

    /**
     * RFQ module. Reads, plus the first slice's two writes (plan 3.7): paste
     * intake and a review save. Every other write family (workflow, sync)
     * lands with its phase, each on its own permission.
     *
     * Every route carries exactly one `RFQ.*` permission, and every record the
     * handlers return is filtered by `RfqAccessPolicy` on top of it. The
     * permission is the door to the module; scope is which RFQs are behind it.
     * `RfqRouteTableTest` pins the one-permission-per-route rule, which is what
     * the source's `test_deploy_bundle` allow-list checks were for.
     *
     * `/rfqs/me` is registered before `/rfqs/{publicId}` so "me" is never read
     * as an id; FastRoute prefers the static route regardless.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerRfqRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $app->get('/api/v1/rfqs/me', GetRfqAccessAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_ACCESS));
        $app->get('/api/v1/rfqs', ListRfqsAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        // Analytics plan A1: the Analytics tab. A static route, so FastRoute
        // never reads "analytics" as a public id.
        $app->get('/api/v1/rfqs/analytics', \TriCab\Api\Http\Action\Rfq\GetRfqAnalyticsAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_ANALYTICS_READ));
        $app->get('/api/v1/rfqs/{publicId}', GetRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        // Plan 3.7 and parity plan E2: Match Text (stores nothing), Save as Draft,
        // and a review save. No external write on any of them.
        $app->post('/api/v1/rfqs/parse', \TriCab\Api\Http\Action\Rfq\ParseRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        $app->post('/api/v1/rfqs', CreateRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        // Plans 2.5, 3.3 and 3.5: file intake, the background reads and their
        // documents. Upload creates a draft; resolving a read is an edit.
        $app->post('/api/v1/rfqs/upload', \TriCab\Api\Http\Action\Rfq\UploadRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        // Parity plan E4.3: Scan Screenshot. Text back for the rep to review; no lines, no draft.
        $app->post('/api/v1/rfqs/ocr', \TriCab\Api\Http\Action\Rfq\ScanRfqScreenshotAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        $app->get('/api/v1/rfqs/{publicId}/reads', \TriCab\Api\Http\Action\Rfq\ListRfqReadsAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        $app->post('/api/v1/rfqs/{publicId}/reads/{readId}', \TriCab\Api\Http\Action\Rfq\ResolveRfqReadAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_EDIT));
        $app->get('/api/v1/rfqs/{publicId}/documents/{documentId}', \TriCab\Api\Http\Action\Rfq\DownloadRfqDocumentAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        $app->patch('/api/v1/rfqs/{publicId}', SaveRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_EDIT));
        // Parity plan E5.5: Save Review logs the saved revision and teaches the
        // correction memory. Managers only; the service also checks the edit.
        $app->post('/api/v1/rfqs/{publicId}/review', \TriCab\Api\Http\Action\Rfq\SaveRfqReviewAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_PRICING_VIEW));
        // Parity plan E3.5: readiness of the editor's lines, saved or not. Writes nothing.
        $app->post('/api/v1/rfqs/readiness', \TriCab\Api\Http\Action\Rfq\CheckRfqReadinessAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        $app->post('/api/v1/rfqs/{publicId}/readiness', \TriCab\Api\Http\Action\Rfq\CheckRfqReadinessAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        // Parity plan E7.1: the Product Summary and the two CSV exports, over the lines a save would write.
        $app->post('/api/v1/rfqs/summary', \TriCab\Api\Http\Action\Rfq\RfqOutputAction::class)
            ->setArgument('output', 'summary')
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        $app->post('/api/v1/rfqs/{publicId}/summary', \TriCab\Api\Http\Action\Rfq\RfqOutputAction::class)
            ->setArgument('output', 'summary')
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        $app->post('/api/v1/rfqs/exports/quote-lines', \TriCab\Api\Http\Action\Rfq\RfqOutputAction::class)
            ->setArgument('output', 'quote-lines')
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        $app->post('/api/v1/rfqs/{publicId}/exports/quote-lines', \TriCab\Api\Http\Action\Rfq\RfqOutputAction::class)
            ->setArgument('output', 'quote-lines')
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        $app->post('/api/v1/rfqs/exports/summary-lines', \TriCab\Api\Http\Action\Rfq\RfqOutputAction::class)
            ->setArgument('output', 'summary-quote-lines')
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        $app->post('/api/v1/rfqs/{publicId}/exports/summary-lines', \TriCab\Api\Http\Action\Rfq\RfqOutputAction::class)
            ->setArgument('output', 'summary-quote-lines')
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        // Parity plan E7.2: Match Accessories. Cost is in the answer, so both are pricing holders'.
        $app->post('/api/v1/rfqs/accessories', \TriCab\Api\Http\Action\Rfq\MatchRfqAccessoriesAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_PRICING_VIEW));
        $app->post('/api/v1/rfqs/{publicId}/accessories', \TriCab\Api\Http\Action\Rfq\MatchRfqAccessoriesAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_PRICING_VIEW));
        // Parity plan E7.3: Sync to 4js. Off unless RFQ_SYNC_ENABLED is `true`, and no live adapter is wired.
        $app->post('/api/v1/rfqs/{publicId}/sync', \TriCab\Api\Http\Action\Rfq\SyncRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_QUOTE_SYNC));
        // Parity plan E7.4: ALIFLEX makes a new draft from a finished quote, so it is a create.
        $app->post('/api/v1/rfqs/{publicId}/aliflex', \TriCab\Api\Http\Action\Rfq\CreateRfqAliflexAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CREATE));
        // Parity plan E1: the quote header's lookups. Read only, internal data.
        $app->get('/api/v1/rfqs/lookups/customers', \TriCab\Api\Http\Action\Rfq\SearchRfqCustomersAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_EDIT));
        $app->get('/api/v1/rfqs/lookups/contacts', \TriCab\Api\Http\Action\Rfq\ListRfqContactsAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_EDIT));
        $app->get('/api/v1/rfqs/lookups/pay-terms', \TriCab\Api\Http\Action\Rfq\ListRfqPayTermsAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_EDIT));
        $app->get('/api/v1/rfqs/lookups/products', \TriCab\Api\Http\Action\Rfq\SearchRfqProductsAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_EDIT));
        $app->get('/api/v1/rfqs/lookups/people', \TriCab\Api\Http\Action\Rfq\SearchRfqPeopleAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_ASSIGN));
        // Parity plan E6: the workflow panel. Every write carries `expectedRevision`
        // and answers the fresh summary; none bumps the revision.
        $app->get('/api/v1/rfqs/{publicId}/activity', \TriCab\Api\Http\Action\Rfq\ListRfqActivityAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_READ));
        $app->post('/api/v1/rfqs/{publicId}/claim', \TriCab\Api\Http\Action\Rfq\ClaimRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_CLAIM));
        $app->patch('/api/v1/rfqs/{publicId}/assignment', \TriCab\Api\Http\Action\Rfq\AssignRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_ASSIGN));
        $app->patch('/api/v1/rfqs/{publicId}/workflow', \TriCab\Api\Http\Action\Rfq\UpdateRfqWorkflowAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_WORKFLOW));
        $app->post('/api/v1/rfqs/{publicId}/archive', \TriCab\Api\Http\Action\Rfq\ArchiveRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_ARCHIVE));
        $app->post('/api/v1/rfqs/{publicId}/restore', \TriCab\Api\Http\Action\Rfq\RestoreRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_RESTORE));
        $app->post('/api/v1/rfqs/{publicId}/comments', \TriCab\Api\Http\Action\Rfq\CommentOnRfqAction::class)
            ->add(($require)(\TriCab\Core\Rfq\Module::PERMISSION_DRAFT_COMMENT));
    }

    /**
     * New Product Requests (plan NPR-TSD-MIGRATION-PLAN, N1). Sales work their own
     * requests under `/npr/me`; TSD reads what has reached it under `/npr/tsd`.
     * Each route has exactly one permission; the service then checks the record
     * (someone else's request answers 404). N2 adds submit, the manager's
     * `/npr/team` steps, TSD and signatory steps and manager-route admin.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerNprRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $app->get('/api/v1/npr/me/requests', \TriCab\Api\Http\Action\Npr\ListMyNprRequestsAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_READ));
        $app->post('/api/v1/npr/me/requests', \TriCab\Api\Http\Action\Npr\CreateNprRequestAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_CREATE));
        $app->get('/api/v1/npr/me/requests/{publicId}', \TriCab\Api\Http\Action\Npr\GetMyNprRequestAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_READ));
        $app->put('/api/v1/npr/me/requests/{publicId}', \TriCab\Api\Http\Action\Npr\SaveNprRequestAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_CREATE));

        $app->delete('/api/v1/npr/me/requests/{publicId}', \TriCab\Api\Http\Action\Npr\DeleteNprRequestAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_CREATE));

        $app->get('/api/v1/npr/tsd/requests', \TriCab\Api\Http\Action\Npr\ListTsdNprRequestsAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_TSD_READ));
        $app->get('/api/v1/npr/tsd/requests/{publicId}', \TriCab\Api\Http\Action\Npr\GetTsdNprRequestAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_TSD_READ));

        // N2: workflow steps. One permission each; the service then checks the record and status.
        $app->post('/api/v1/npr/me/requests/{publicId}/submit', \TriCab\Api\Http\Action\Npr\SubmitNprRequestAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_SUBMIT));

        $teamPermission = \TriCab\Core\Npr\Module::PERMISSION_TEAM_ENDORSE;
        $app->get('/api/v1/npr/team/requests', \TriCab\Api\Http\Action\Npr\ListTeamNprRequestsAction::class)
            ->add(($require)($teamPermission));
        $app->get('/api/v1/npr/team/requests/{publicId}', \TriCab\Api\Http\Action\Npr\GetTeamNprRequestAction::class)
            ->add(($require)($teamPermission));
        foreach (['endorse', 'return', 'reject'] as $step) {
            $app->post('/api/v1/npr/team/requests/{publicId}/' . $step, \TriCab\Api\Http\Action\Npr\NprWorkflowAction::class)
                ->setArgument('step', 'team.' . $step)
                ->add(($require)($teamPermission));
        }

        $tsdWork = \TriCab\Core\Npr\Module::PERMISSION_TSD_WORK;
        foreach (['acknowledge', 'assign', 'return', 'request-screening'] as $step) {
            $app->post('/api/v1/npr/tsd/requests/{publicId}/' . $step, \TriCab\Api\Http\Action\Npr\NprWorkflowAction::class)
                ->setArgument('step', 'tsd.' . $step)
                ->add(($require)($tsdWork));
        }
        $app->get('/api/v1/npr/tsd/screening-signatories', \TriCab\Api\Http\Action\Npr\ListNprScreeningSignatoriesAction::class)
            ->add(($require)($tsdWork));
        $app->put('/api/v1/npr/tsd/requests/{publicId}/assessment', \TriCab\Api\Http\Action\Npr\NprWorkflowAction::class)
            ->setArgument('step', 'tsd.assessment')
            ->add(($require)($tsdWork));
        $app->post('/api/v1/npr/tsd/requests/{publicId}/screening-decision', \TriCab\Api\Http\Action\Npr\NprWorkflowAction::class)
            ->setArgument('step', 'signatory.decide')
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SCREENING_DECIDE));

        $app->get('/api/v1/npr/admin/manager-routes', \TriCab\Api\Http\Action\Npr\NprManagerRouteAction::class)
            ->setArgument('step', 'list')
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_ADMIN));
        $app->post('/api/v1/npr/admin/manager-routes', \TriCab\Api\Http\Action\Npr\NprManagerRouteAction::class)
            ->setArgument('step', 'create')
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_ADMIN));
        $app->put('/api/v1/npr/admin/manager-routes/{id}', \TriCab\Api\Http\Action\Npr\NprManagerRouteAction::class)
            ->setArgument('step', 'update')
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_ADMIN));

        // N2 follow-up: the form structure the editors render, served rather than bundled.
        $app->get('/api/v1/npr/field-dictionary', \TriCab\Api\Http\Action\Npr\GetNprFieldDictionaryAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_READ));
        $app->get('/api/v1/npr/tsd/field-dictionary', \TriCab\Api\Http\Action\Npr\GetNprFieldDictionaryAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_TSD_READ));

        // Section 1's customer and contact suggestions: the RFQ header's lookups over the same
        // synced customer book, opened to whoever may write a request rather than to RFQ editors.
        $app->get('/api/v1/npr/lookups/customers', \TriCab\Api\Http\Action\Rfq\SearchRfqCustomersAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_CREATE));
        $app->get('/api/v1/npr/lookups/contacts', \TriCab\Api\Http\Action\Rfq\ListRfqContactsAction::class)
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_SELF_CREATE));

        // N2.3 attachments: the requester's own door, read-only doors for the manager and TSD, and TSD uploads.
        $files = \TriCab\Api\Http\Action\Npr\NprAttachmentAction::class;
        $selfRead = \TriCab\Core\Npr\Module::PERMISSION_SELF_READ;
        $selfWrite = \TriCab\Core\Npr\Module::PERMISSION_SELF_CREATE;
        $tsdRead = \TriCab\Core\Npr\Module::PERMISSION_TSD_READ;
        foreach ([
            ['get', 'me', 'list', '', $selfRead],
            ['post', 'me', 'upload', '', $selfWrite],
            ['delete', 'me', 'delete', '/{attachmentPublicId}', $selfWrite],
            ['get', 'me', 'download', '/{attachmentPublicId}/download', $selfRead],
            ['get', 'team', 'list', '', $teamPermission],
            ['get', 'team', 'download', '/{attachmentPublicId}/download', $teamPermission],
            ['get', 'tsd', 'list', '', $tsdRead],
            ['post', 'tsd', 'upload', '', $tsdWork],
            ['get', 'tsd', 'download', '/{attachmentPublicId}/download', $tsdRead],
        ] as [$method, $area, $step, $suffix, $permission]) {
            $scope = ['me' => 'own', 'team' => 'team', 'tsd' => 'tsd'][$area];
            $app->map([strtoupper($method)], '/api/v1/npr/' . $area . '/requests/{publicId}/attachments' . $suffix, $files)
                ->setArgument('scope', $scope)
                ->setArgument('step', $step)
                ->add(($require)($permission));
        }

        // N2.4 delivery status of the workflow email.
        $app->get('/api/v1/npr/admin/notifications', \TriCab\Api\Http\Action\Npr\NprNotificationAction::class)
            ->setArgument('step', 'list')
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_ADMIN));
        $app->post('/api/v1/npr/admin/notifications/{id}/retry', \TriCab\Api\Http\Action\Npr\NprNotificationAction::class)
            ->setArgument('step', 'retry')
            ->add(($require)(\TriCab\Core\Npr\Module::PERMISSION_ADMIN));
    }

    /**
     * Onboarding module.
     *
     * Two groups with different gates, deliberately not one:
     *
     *  - `/api/v1/onboarding/*` is self-service. It is reachable by a
     *    pre-start candidate on a guest session and by an employee who has no
     *    TriCab mailbox, so it is gated on ONBOARDING.SELF.* and never on a
     *    role or session type (work order §4.15).
     *  - `/api/v1/admin/onboarding/*` is HR. Separate permissions, and the
     *    self gate grants none of it.
     *
     * Within the self group the split is READ versus SUBMIT, and it is not
     * cosmetic. `ONBOARDING.SELF.READ` opens an induction; `ONBOARDING.SELF.SUBMIT`
     * is what writes evidence. A departed employee who keeps the first and loses
     * the second is the leaver policy expressed as two permissions, and the
     * route table is where that is legible.
     *
     * **Every path below identifies an item, never a person.** There is no user
     * id in any of these routes, no `?forUser=`, and no body field that names a
     * subject — whose record this is follows from the session, which is work
     * order §4.3. The item id is checked against the session's own user inside
     * one SQL statement, so an id belonging to somebody else is not found.
     *
     * Note there is no `/api/v1/onboarding/training/{id}/complete`. Nothing a
     * trainee can call completes an item (invariant 7): passing an assessment
     * does, acknowledging a policy does, and a reviewer does. The absence of the
     * route is the enforcement.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerOnboardingRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $app->get('/api/v1/onboarding/me', GetOnboardingSelfSummaryAction::class)
            ->add(($require)('ONBOARDING.SELF.READ'));

        // Phase 3.3 — opening one allocated item. `itemType` is an argument of
        // the route rather than of the request, so a client cannot ask for a
        // policy "as training" to see what a different code path does with it.
        $app->get('/api/v1/onboarding/training/{assignmentPublicId}', GetOnboardingItemAction::class)
            ->setArgument('itemType', 'training')
            ->add(($require)('ONBOARDING.SELF.READ'));
        $app->get('/api/v1/onboarding/policies/{assignmentPublicId}', GetOnboardingItemAction::class)
            ->setArgument('itemType', 'policy')
            ->add(($require)('ONBOARDING.SELF.READ'));
        $app->get('/api/v1/onboarding/forms/{assignmentPublicId}', GetOnboardingItemAction::class)
            ->setArgument('itemType', 'form')
            ->add(($require)('ONBOARDING.SELF.READ'));

        $app->get('/api/v1/onboarding/documents', GetOnboardingDocumentsAction::class)
            ->add(($require)('ONBOARDING.SELF.READ'));
        $app->get('/api/v1/onboarding/documents/{documentPublicId}/content', GetOnboardingDocumentContentAction::class)
            ->add(($require)('ONBOARDING.SELF.READ'));

        // Phase 3.3 — the write side. All gated on SELF.SUBMIT, which READ alone
        // does not grant.
        $app->post('/api/v1/onboarding/training/{assignmentPublicId}/progress', PostOnboardingPresentationProgressAction::class)
            ->add(($require)('ONBOARDING.SELF.SUBMIT'));
        $app->post('/api/v1/onboarding/training/{assignmentPublicId}/attempts', PostOnboardingAttemptAction::class)
            ->add(($require)('ONBOARDING.SELF.SUBMIT'));
        $app->post('/api/v1/onboarding/training/{assignmentPublicId}/attempts/{attemptPublicId}', PostOnboardingAttemptSubmissionAction::class)
            ->add(($require)('ONBOARDING.SELF.SUBMIT'));
        $app->post('/api/v1/onboarding/policies/{assignmentPublicId}/acknowledgement', PostOnboardingPolicyAcknowledgementAction::class)
            ->add(($require)('ONBOARDING.SELF.SUBMIT'));
        $app->put('/api/v1/onboarding/forms/{assignmentPublicId}/draft', PutOnboardingFormDraftAction::class)
            ->add(($require)('ONBOARDING.SELF.SUBMIT'));
        $app->post('/api/v1/onboarding/forms/{assignmentPublicId}/submission', PostOnboardingFormSubmissionAction::class)
            ->add(($require)('ONBOARDING.SELF.SUBMIT'));

        // Phase 5.4 — a manager's part of their own starters' forms. The
        // trainee-side permission, because managers are ordinary staff; what
        // limits it is the service's rule that the starter's profile names them,
        // and anybody else is answered 404.
        $app->get('/api/v1/onboarding/team/forms', GetOnboardingManagerFormsAction::class)
            ->add(($require)('ONBOARDING.SELF.READ'));
        $app->get('/api/v1/onboarding/team/forms/{assignmentPublicId}', GetOnboardingManagerFormAction::class)
            ->add(($require)('ONBOARDING.SELF.READ'));
        $app->put('/api/v1/onboarding/team/forms/{assignmentPublicId}/contribution', PutOnboardingManagerContributionAction::class)
            ->add(($require)('ONBOARDING.SELF.READ'));

        // Phase 6 — the completion feed for Skill Matrix. Its own permission,
        // held by no person's role: a service identity polls it (owner decision
        // owed on which). Defined and tested; nothing connects to it.
        $app->get('/api/v1/integrations/onboarding/completions', GetOnboardingCompletionFeedAction::class)
            ->add(($require)('ONBOARDING.COMPLETIONS.READ'));

        $app->get('/api/v1/admin/onboarding/overview', GetOnboardingAdminOverviewAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));

        // Phase 4.2 — people and explicit allocation. Administrative, so the
        // permission alone is not enough: RequirePermissionMiddleware also holds
        // it to passkey or Entra assurance (invariant 14), and an emailed code
        // reaches none of these.
        $app->get('/api/v1/admin/onboarding/people', GetOnboardingPeopleAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));
        $app->get('/api/v1/admin/onboarding/people/{personPublicId}', GetOnboardingPersonAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));
        $app->put('/api/v1/admin/onboarding/people/{personPublicId}/allocation', PutOnboardingPersonAllocationAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));
        // Phase 5.4 — a person's generated documents, for the personnel file.
        // Every download is streamed and audited against HR (PersonDocumentsService).
        $app->get('/api/v1/admin/onboarding/people/{personPublicId}/documents', GetOnboardingPersonDocumentsAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));
        $app->get('/api/v1/admin/onboarding/people/{personPublicId}/documents/{documentPublicId}/content', GetOnboardingPersonDocumentContentAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));

        // Phase 4.1 — adding a person, editing their profile, inviting them.
        // Same permission as allocation: choosing who is inducted and choosing
        // what they are given are one job. Creating a person may create a
        // GUEST account (owner decision, 23 Sep 2026) — the only account this
        // module can make, and one that reaches nothing but its own onboarding.
        $app->post('/api/v1/admin/onboarding/people', PostOnboardingPersonAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));
        $app->put('/api/v1/admin/onboarding/people/{personPublicId}/profile', PutOnboardingPersonProfileAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));
        $app->post('/api/v1/admin/onboarding/people/{personPublicId}/invitation', PostOnboardingPersonInvitationAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));
        // Deleting a record nothing is recorded against — a mistake or a test.
        // Anybody who has done anything is given a last day instead.
        $app->delete('/api/v1/admin/onboarding/people/{personPublicId}', DeleteOnboardingPersonAction::class)
            ->add(($require)('ONBOARDING.PEOPLE.MANAGE'));

        // Phase 4.3 — the reviewer queue. ONBOARDING.REVIEW, not PEOPLE.MANAGE:
        // deciding somebody's work and choosing what they are given are separate
        // grants (work order §9, "reviewer and content-manager scopes remain
        // separate"), and holding one opens none of the other's routes.
        $app->get('/api/v1/admin/onboarding/queue', GetOnboardingReviewQueueAction::class)
            ->add(($require)('ONBOARDING.REVIEW'));
        $app->get('/api/v1/admin/onboarding/queue/{assignmentPublicId}', GetOnboardingReviewItemAction::class)
            ->add(($require)('ONBOARDING.REVIEW'));
        $app->post('/api/v1/admin/onboarding/queue/{assignmentPublicId}/decision', PostOnboardingReviewDecisionAction::class)
            ->add(($require)('ONBOARDING.REVIEW'));
        // Phase 5.4 — HR's part of a form (the induction checklist, the vehicle
        // inspection). REVIEW, as the decision is: HR fills and decides.
        $app->get('/api/v1/admin/onboarding/queue/{assignmentPublicId}/contribution', GetOnboardingFormContributionAction::class)
            ->add(($require)('ONBOARDING.REVIEW'));
        $app->put('/api/v1/admin/onboarding/queue/{assignmentPublicId}/contribution', PutOnboardingFormContributionAction::class)
            ->add(($require)('ONBOARDING.REVIEW'));
        $app->post('/api/v1/admin/onboarding/queue/{assignmentPublicId}/photos', PostOnboardingFormPhotoAction::class)
            ->add(($require)('ONBOARDING.REVIEW'));
        $app->get('/api/v1/admin/onboarding/queue/{assignmentPublicId}/photos/{photoPublicId}', GetOnboardingFormPhotoAction::class)
            ->add(($require)('ONBOARDING.REVIEW'));

        // Phase 4.4 — content authoring. ONBOARDING.CONTENT.MANAGE alone, and
        // the only routes that serve an answer key: a version's authored body.
        // The item type is a route argument per path segment, never a body
        // field, as on the trainee side.
        $app->get('/api/v1/admin/onboarding/content', GetOnboardingContentAction::class)
            ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
        foreach (['training' => 'training', 'policies' => 'policy', 'forms' => 'form'] as $segment => $itemType) {
            $base = '/api/v1/admin/onboarding/content/' . $segment;
            $app->post($base, PostOnboardingContentItemAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
            $app->get($base . '/{itemPublicId}', GetOnboardingContentItemAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
            $app->put($base . '/{itemPublicId}', PutOnboardingContentItemAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
            $app->delete($base . '/{itemPublicId}', DeleteOnboardingContentItemAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
            $app->post($base . '/{itemPublicId}/versions', PostOnboardingContentDraftAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
            $app->get($base . '/{itemPublicId}/versions/{versionPublicId}', GetOnboardingContentVersionAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
            $app->put($base . '/{itemPublicId}/versions/{versionPublicId}', PutOnboardingContentVersionAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
            $app->post($base . '/{itemPublicId}/versions/{versionPublicId}/publish', PostOnboardingContentPublishAction::class)
                ->setArgument('itemType', $itemType)
                ->add(($require)('ONBOARDING.CONTENT.MANAGE'));
        }

        // Phase 4.5 — reports. ONBOARDING.REPORTS.READ alone. Counts and names,
        // never an email address, score, note or answer, so a report reader is
        // not handed the roster's contact details or a reviewer's evidence.
        foreach (['summary', 'items', 'overdue'] as $report) {
            $app->get('/api/v1/admin/onboarding/reports/' . $report, GetOnboardingReportAction::class)
                ->setArgument('report', $report)
                ->add(($require)('ONBOARDING.REPORTS.READ'));
        }
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerAdminRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $platformAdmin = new RequirePlatformAdministratorMiddleware(new \TriCab\Api\Http\Response\JsonResponder());
        $app->get('/api/v1/admin/settings/platform', PlatformSettingsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));
        $app->put('/api/v1/admin/settings/platform', PlatformSettingsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));
        $app->get('/api/v1/admin/settings/platform/history', PlatformSettingsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));
        $app->post('/api/v1/admin/settings/platform/restore', PlatformSettingsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));
        $app->post('/api/v1/admin/settings/platform/reset', PlatformSettingsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));

        $app->post('/api/v1/admin/act-as', StartActAsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));
        $app->delete('/api/v1/admin/act-as/{id}', EndActAsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));
        $app->get('/api/v1/admin/act-as', ListActAsSessionsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));
        $app->get('/api/v1/admin/act-as/{id}/actions', ListActAsSessionActionsAction::class)
            ->add($platformAdmin)->add(($require)('ACCOUNT.LOGIN'));

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
        self::registerAdminLegacyMigrationRoutes($app, $require);
    }

    /**
     * @param App<ContainerInterface> $app
     */

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerTriExpenseRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $gate = 'TRIEXPENSE.ACCESS';

        $app->get('/api/v1/tri-expense/config', GetExpenseConfigAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/admin/overview', GetExpenseAdminOverviewAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/admin/users', ListExpenseAdminUsersAction::class)->add(($require)($gate));
        $app->put('/api/v1/tri-expense/admin/users/{publicId}/te-roles', SetExpenseUserTeRolesAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/admin/employee-search', SearchExpenseAdminEmployeesAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/admin/legacy-approver-mapping', DryCheckExpenseLegacyApproverMappingAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/admin/profiles', ListExpenseProfilesAdminAction::class)->add(($require)($gate));
        $app->patch('/api/v1/tri-expense/admin/profiles/{userPublicId}/approver', UpdateExpenseProfileApproverAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/receipts', ListExpenseReceiptsAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts', CreateExpenseReceiptAction::class)->add(($require)($gate));
        // Must be registered before /receipts/{id} so "extract" is not treated as an id.
        $app->post('/api/v1/tri-expense/receipts/extract', ExtractExpenseReceiptDraftAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/receipts/{id}', GetExpenseReceiptAction::class)->add(($require)($gate));
        $app->patch('/api/v1/tri-expense/receipts/{id}', UpdateExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/submit', SubmitExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/approve', ApproveExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/reject', RejectExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/send-back', SendBackExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/delete', SoftDeleteExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/restore', RestoreExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/purge', PurgeExpenseReceiptAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/attachment/initiate', InitiateExpenseAttachmentAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/attachment/complete', CompleteExpenseAttachmentAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/receipts/{id}/file', GetExpenseAttachmentFileAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/receipts/{id}/ocr', RunExpenseReceiptOcrAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/approvals', ListExpenseApprovalsAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/customers', SearchExpenseCustomersAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/customers/{code}/contacts', GetExpenseCustomerContactsAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/duplicate-check', CheckExpenseDuplicateAction::class)->add(($require)($gate));

        $app->get('/api/v1/tri-expense/finance/bank-transactions', ListExpenseFinanceBankTransactionsAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/bank-feeds/import', ImportExpenseBankFeedAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/bank-feeds/match', RunExpenseBankFeedMatchingAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/bank-feeds/{id}', GetExpenseBankFeedAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/bank-feeds/{id}/candidates', ListExpenseBankFeedCandidatesAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/bank-feeds/{id}/manual-match', ManualMatchExpenseBankFeedAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/bank-feeds/{id}/resolve', ResolveExpenseBankFeedAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/bank-feeds/offset', OffsetExpenseBankFeedAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/bank-feeds/{id}/undo-offset', UndoOffsetExpenseBankFeedAction::class)->add(($require)($gate));
        // Suggested aliases from Phase 4 contract
        $app->get('/api/v1/tri-expense/audit', ListExpenseAuditAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/exports/receipts.csv', ExportEmployeeReceiptsCsvAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/exports/reconciliation.csv', ExportFinanceReconciliationCsvAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/exports/summary.pdf', RenderFinanceSummaryPdfAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/exports/full.pdf', RenderFinanceFullPdfAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/receipts/{id}/resolve', ResolveExpenseReceiptFinanceAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/periods', ListExpensePeriodsAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/periods/get-or-create', GetOrCreateExpensePeriodAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/periods/{id}/pre-close', PreCloseExpensePeriodAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/periods/{id}/close', CloseExpensePeriodAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/reconciliation', GetExpenseReconciliationAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/workspace', GetExpenseFinanceWorkspaceAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/employees/{userId}', GetExpenseFinanceEmployeeAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/reminders/missing-receipts', PrepareMissingReceiptRemindersAction::class)->add(($require)($gate));
        $app->post('/api/v1/tri-expense/finance/reminders/managers', PrepareManagerReminderAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/audit', ListExpenseAuditAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/export/employee-receipts', ExportEmployeeReceiptsCsvAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/export/reconciliation', ExportFinanceReconciliationCsvAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/pdf/summary', RenderFinanceSummaryPdfAction::class)->add(($require)($gate));
        $app->get('/api/v1/tri-expense/finance/pdf/full', RenderFinanceFullPdfAction::class)->add(($require)($gate));
    }

    /**
     * Quotes & Orders — Phase 1 read-only (legacy tc MySQL). No POST/PATCH/DELETE.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerQuoteOrderRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $gate = \TriCab\Core\QuoteOrder\Module::PERMISSION_READ;

        $app->get('/api/v1/quotes-orders', \TriCab\Api\Http\Action\QuoteOrder\ListQuoteOrdersAction::class)
            ->add(($require)($gate));
        $app->get('/api/v1/quotes/{id}', \TriCab\Api\Http\Action\QuoteOrder\GetQuoteAction::class)
            ->add(($require)($gate));
        $app->get('/api/v1/quotes/{id}/document', \TriCab\Api\Http\Action\QuoteOrder\GetQuoteTemplateDocumentAction::class)
            ->add(($require)($gate));
        $app->get('/api/v1/orders/{id}', \TriCab\Api\Http\Action\QuoteOrder\GetOrderAction::class)
            ->add(($require)($gate));
    }

    /**
     * Customer Contact reports (the complaint register). Not the person-contact
     * routes under /api/v1/admin/customer-contacts.
     *
     * Registered only when CUSTOMER_CONTACT_MODULE_ENABLED is true. Off, these
     * paths are 404s. /api/v1/me also requires CUSTOMER_CONTACT.ACCESS before
     * it reports the module as available.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerCustomerContactReportRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $module = \TriCab\Core\CustomerContactReport\Module::class;

        $app->get('/api/v1/customer-contacts', \TriCab\Api\Http\Action\CustomerContactReport\ListCustomerContactReportsAction::class)
            ->add(($require)($module::PERMISSION_READ));
        $app->post('/api/v1/customer-contacts', \TriCab\Api\Http\Action\CustomerContactReport\CreateCustomerContactReportAction::class)
            ->add(($require)($module::PERMISSION_CREATE));
        $app->post('/api/v1/customer-contacts/{publicId}/assign', \TriCab\Api\Http\Action\CustomerContactReport\AssignCustomerContactReportAction::class)
            ->add(($require)($module::PERMISSION_ASSIGN));
        $app->post('/api/v1/customer-contacts/{publicId}/complete', \TriCab\Api\Http\Action\CustomerContactReport\CompleteCustomerContactReportAction::class)
            ->add(($require)($module::PERMISSION_COMPLETE));
        $app->post('/api/v1/customer-contacts/{publicId}/close', \TriCab\Api\Http\Action\CustomerContactReport\CloseCustomerContactReportAction::class)
            ->add(($require)($module::PERMISSION_CLOSE));
        $app->post('/api/v1/customer-contacts/{publicId}/reopen', \TriCab\Api\Http\Action\CustomerContactReport\ReopenCustomerContactReportAction::class)
            ->add(($require)($module::PERMISSION_REOPEN));
        $app->post('/api/v1/customer-contacts/{publicId}/approvals/{kind}/request', \TriCab\Api\Http\Action\CustomerContactReport\RequestCustomerContactReportApprovalAction::class)
            ->add(($require)($module::PERMISSION_REMEDY));
        $app->post('/api/v1/customer-contacts/{publicId}/approvals/{kind}/decision', \TriCab\Api\Http\Action\CustomerContactReport\DecideCustomerContactReportApprovalAction::class)
            ->add(($require)($module::PERMISSION_APPROVE));
        $app->post('/api/v1/customer-contacts/{publicId}/attachments', \TriCab\Api\Http\Action\CustomerContactReport\AttachCustomerContactReportEvidenceAction::class)
            ->add(($require)($module::PERMISSION_CREATE));
        $app->get('/api/v1/customer-contacts/{publicId}/attachments', \TriCab\Api\Http\Action\CustomerContactReport\ListCustomerContactReportAttachmentsAction::class)
            ->add(($require)($module::PERMISSION_READ));
        $app->post('/api/v1/customer-contacts/{publicId}/credit-note', \TriCab\Api\Http\Action\CustomerContactReport\CompleteCustomerContactReportSettlementAction::class)
            ->add(($require)($module::PERMISSION_ACCESS));
        $app->post('/api/v1/customer-contacts/{publicId}/icar-signature', \TriCab\Api\Http\Action\CustomerContactReport\SaveCustomerContactReportSignatureAction::class)
            ->add(($require)($module::PERMISSION_ICAR_SIGN));
        $app->get('/api/v1/customer-contacts/{publicId}', \TriCab\Api\Http\Action\CustomerContactReport\GetCustomerContactReportAction::class)
            ->add(($require)($module::PERMISSION_READ));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerLogNoteRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);

        $app->post('/api/v1/log-notes/summarise', \TriCab\Api\Http\Action\LogNote\SummariseLogNoteAction::class)
            ->add(($require)('CUSTOMER_NOTE.CREATE'));
        $app->get('/api/v1/log-notes', \TriCab\Api\Http\Action\LogNote\ListLogNotesAction::class)
            ->add(($require)('CUSTOMER_NOTE.READ'));
        $app->post('/api/v1/log-notes', \TriCab\Api\Http\Action\LogNote\CreateLogNoteAction::class)
            ->add(($require)('CUSTOMER_NOTE.CREATE'));
        $app->get('/api/v1/log-notes/{publicId}', \TriCab\Api\Http\Action\LogNote\GetLogNoteAction::class)
            ->add(($require)('CUSTOMER_NOTE.READ'));
        $app->get('/api/v1/customers/{customerPublicId}/log-notes', \TriCab\Api\Http\Action\LogNote\ListCustomerLogNotesAction::class)
            ->add(($require)('CUSTOMER_NOTE.READ'));
    }

    /**
     * Staff telephone directory — Microsoft Graph / Entra ID (never stored in Azure SQL).
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerTelDirectoryRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $app->get('/api/v1/tel-directory', \TriCab\Api\Http\Action\TelDirectory\ListTelDirectoryAction::class)
            ->add(($require)(\TriCab\Core\TelDirectory\Module::PERMISSION_ACCESS));
    }

    /**
     * Staff PDF catalogues — Azure SQL metadata + Blob PDFs (NOT product catalogue navigation).
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerPdfCatalogueRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $gate = \TriCab\Core\PdfCatalogue\Module::PERMISSION_READ;
        $app->get('/api/v1/catalogues', ListPdfCataloguesAction::class)->add(($require)($gate));
        $app->get('/api/v1/catalogues/{publicId}', GetPdfCatalogueAction::class)->add(($require)($gate));
        $app->get('/api/v1/catalogues/{publicId}/file', GetPdfCatalogueFileAction::class)->add(($require)($gate));
    }

    /**
     * Staff Price Lookup — internal cost/sell calculator (PRICE_LOOKUP.READ).
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerPriceLookupRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $gate = \TriCab\Core\PriceLookup\Module::PERMISSION_READ;
        $app->get('/api/v1/price-lookup/codes', ListPriceLookupCodesAction::class)->add(($require)($gate));
        $app->get('/api/v1/price-lookup/search', SearchPriceLookupAction::class)->add(($require)($gate));
        $app->post('/api/v1/price-lookup/quote', QuotePriceLookupAction::class)->add(($require)($gate));
    }

    /**
     * Technical hub — versioned JSON content packs (manifest / articles / lookup tables).
     * Login required (ACCOUNT.LOGIN). Cable Calculator remains on its existing public route.
     *
     * @param App<ContainerInterface> $app
     */
    private static function registerTechnicalRoutes(App $app, Container $container): void
    {
        $require = new RequirePermission($container);
        $gate = 'ACCOUNT.LOGIN';
        $app->get('/api/v1/technical/sections', ListTechnicalSectionsAction::class)->add(($require)($gate));
        $app->get('/api/v1/technical/articles/{slug}', GetTechnicalArticleAction::class)->add(($require)($gate));
        $app->get('/api/v1/technical/tables/{key}', GetTechnicalTableAction::class)->add(($require)($gate));
        $app->get('/api/v1/technical/product-coding/{mode}', GetTechnicalProductCodingAction::class)->add(($require)($gate));
        $app->get('/api/v1/technical/documents', ListTechnicalDocumentsAction::class)->add(($require)($gate));
    }

    private static function registerAdminLegacyMigrationRoutes(App $app, RequirePermission $require): void
    {
        $gate = 'MIGRATION.ADMIN';
        $action = LegacyMigrationAdminAction::class;
        $app->get('/api/v1/admin/legacy-migration/overview', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/user-workspace', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/staff-readonly-analysis', $action)->add(($require)($gate));
        $app->post('/api/v1/admin/legacy-migration/staff-migration-dry-run', $action)->add(($require)($gate));
        $app->post('/api/v1/admin/legacy-migration/staff-migration/execute', $action)->add(($require)($gate));
        $app->post('/api/v1/admin/legacy-migration/staff-entra/dry-run', $action)->add(($require)($gate));
        $app->post('/api/v1/admin/legacy-migration/staff-entra/execute', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/statistics', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/settings', $action)->add(($require)($gate));
        $app->put('/api/v1/admin/legacy-migration/settings', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/sources', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/sources/{sourceCode}/health', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/logs', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/conflicts', $action)->add(($require)($gate));
        $app->post('/api/v1/admin/legacy-migration/conflicts/{conflictPublicId}/resolve', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/batches', $action)->add(($require)($gate));
        $app->post('/api/v1/admin/legacy-migration/batches', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/batches/{batchPublicId}', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/batches/{batchPublicId}/items', $action)->add(($require)($gate));
        foreach ([
            'extract', 'refresh', 'incremental', 'normalize', 'validate', 'preview',
            'approve', 'execute', 'rollback', 'cancel', 'retry',
        ] as $step) {
            $app->post('/api/v1/admin/legacy-migration/batches/{batchPublicId}/' . $step, $action)
                ->add(($require)($gate));
        }
        $app->get('/api/v1/admin/legacy-migration/batches/{batchPublicId}/conflicts', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/batches/{batchPublicId}/logs', $action)->add(($require)($gate));
        $app->get('/api/v1/admin/legacy-migration/batches/{batchPublicId}/reports', $action)->add(($require)($gate));
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private static function registerPublicProductRoutes(App $app): void
    {
        $app->post('/api/v1/assistant/messages', AssistantMessageAction::class);
        $app->post('/api/v1/assistant/messages/stream', \TriCab\Api\Http\Action\AssistantMessageStreamAction::class);
        $app->get('/api/v1/products/{slug}', GetPublicProductBySlugAction::class);
        $app->get('/api/v1/products/{slug}/customer-view', GetPublicProductCustomerViewAction::class);
        $app->get('/api/v1/customer-view-theme', GetCustomerViewThemeAction::class);
        $app->get('/api/v1/products/{slug}/variants', GetPublicProductVariantsAction::class);
        $app->post('/api/v1/products/{slug}/variants/pricing', PostCustomerVariantPricingBatchAction::class);
        $app->post('/api/v1/products/{slug}/rfq/lines/prepare', PostPrepareRfqLinePricingAction::class);
        $app->post('/api/v1/products/{slug}/cart/items/prepare', PostPrepareCartItemPricingAction::class);
        $app->get('/api/v1/cart', GetCartAction::class);
        $app->post('/api/v1/cart/items', PostCartItemAction::class);
        $app->patch('/api/v1/cart/items/{lineId}', PatchCartItemAction::class);
        $app->delete('/api/v1/cart/items/{lineId}', DeleteCartItemAction::class);
        $app->post('/api/v1/cart/reprice', PostCartRepriceAction::class);
        $app->post('/api/v1/pricing/customer-context', PostPricingCustomerContextAction::class);
        $app->get('/api/v1/pricing/customers', GetPricingCustomersAction::class);
        $app->get('/api/v1/pricing/customers/{customerPublicId}/contacts', GetPricingCustomerContactsAction::class);
        $app->post('/api/v1/pricing/margin-override', PostPricingMarginOverrideAction::class);
        $app->get('/api/v1/products/{slug}/media', GetPublicProductMediaAction::class);
        $app->get('/api/v1/products/{slug}/3d', GetPublicProduct3DAction::class);
        $app->get('/api/v1/products/{slug}/documents', GetPublicProductDocumentsAction::class);
        $app->get('/api/v1/products/{slug}/documents/{documentPublicId}/download', DownloadProductDocumentAction::class);
        $app->get('/api/v1/products/{slug}/relationships', GetPublicProductRelationshipsAction::class);
        $app->get('/api/v1/products/{slug}/specifications', GetPublicProductSpecificationsAction::class);
        $app->get('/api/v1/product-search', SearchPublicProductsAction::class);
        $app->get('/api/v1/product-codes/search', SearchPublicProductCodesAction::class);
        $app->get('/api/v1/catalogue/panel', GetPublicCataloguePanelAction::class);
        $app->get('/api/v1/catalogue/tabs', GetPublicCatalogueTabsAction::class);
        $app->get('/api/v1/catalogue/tabs/{tabSlug}/tree', GetPublicCatalogueTabTreeAction::class);
        $app->get('/api/v1/catalogue/nodes/{nodeSlugOrPublicId}', GetPublicCatalogueNodeAction::class);
        $app->get('/api/v1/media/images/{token}[/{name}]', GetStableMediaImageAction::class);
        $app->post('/api/v1/cable-calculator/calculate', PostCableCalculatorCalculateAction::class);
        $app->post('/api/v1/product-finder', FindProductAction::class);
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
        $app->put('/api/v1/admin/products/{productPublicId}/thumbnail-presentation', UpdateProductThumbnailPresentationAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/page-media-layout', UpdateProductPageMediaLayoutAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->put('/api/v1/admin/products/{productPublicId}/page-content', UpdateProductPageContentAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->put('/api/v1/admin/products/{productPublicId}/pricing/default-unit', SetProductDefaultPricingUnitAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->put('/api/v1/admin/products/{productPublicId}/pricing', UpsertProductPricingAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->get('/api/v1/admin/products/{productPublicId}/pricing/history', GetProductPricingAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->get('/api/v1/admin/products/{productPublicId}/pricing/legacy-comparison', CompareLegacyBaseCostAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->get('/api/v1/admin/products/{productPublicId}/pricing/legacy-identities', VariantLegacyPricingIdentityAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->post('/api/v1/admin/products/{productPublicId}/pricing/legacy-identity-audit', VariantLegacyPricingIdentityAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->post('/api/v1/admin/products/{productPublicId}/pricing/{pricingPublicId}/activate', SetProductPricingActiveAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/pricing/{pricingPublicId}/deactivate', SetProductPricingActiveAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/slugs', ChangeProductSlugAction::class)
            ->add(($require)('PRODUCT.UPDATE'));
        $app->get('/api/v1/admin/products/{productPublicId}/seo', GetProductSeoAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/products/{productPublicId}/pricing', GetProductPricingAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));

        $app->get('/api/v1/admin/product-price-lists', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->post('/api/v1/admin/product-price-lists', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->get('/api/v1/admin/product-price-lists/{priceListPublicId}', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->put('/api/v1/admin/product-price-lists/{priceListPublicId}', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->post('/api/v1/admin/product-price-lists/{priceListPublicId}/activate', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->post('/api/v1/admin/product-price-lists/{priceListPublicId}/deactivate', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->post('/api/v1/admin/product-price-lists/{priceListPublicId}/archive', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->post('/api/v1/admin/product-price-lists/{priceListPublicId}/restore', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->get('/api/v1/admin/product-price-lists/{priceListPublicId}/usage', ProductPriceListsAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->post('/api/v1/admin/product-price-lists/{priceListPublicId}/verify-legacy', ProductPriceListsAdminAction::class)
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

        $app->get('/api/v1/admin/product-styles', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->get('/api/v1/admin/product-styles/tree', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/reorder', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/discovery/preview', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles/discovery/evidence-review', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles/discovery/product-mapping', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles/discovery/product-code-mapping', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles/discovery/family-tree', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles/discovery/apply-product-code-mapping', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/discovery/apply-assignments', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/discovery/apply-hierarchy', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->get('/api/v1/admin/product-styles/{stylePublicId}', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->put('/api/v1/admin/product-styles/{stylePublicId}', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/parent', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->put('/api/v1/admin/product-styles/{stylePublicId}/inheritance', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.INHERITANCE.MANAGE'));
        $app->put('/api/v1/admin/product-styles/{stylePublicId}/specifications', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.INHERITANCE.MANAGE'));
        $app->get('/api/v1/admin/product-styles/{stylePublicId}/effective-resources', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/make-local-copy', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.INHERITANCE.MANAGE'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/activate', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/deactivate', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/archive', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/restore', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));
        $app->get('/api/v1/admin/product-styles/{stylePublicId}/usage', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.READ'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/media/upload-session', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.INHERITANCE.MANAGE'));
        $app->post('/api/v1/admin/product-styles/{stylePublicId}/media/{assetPublicId}/complete-upload', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.INHERITANCE.MANAGE'));
        $app->delete('/api/v1/admin/product-styles/{stylePublicId}/media/{assetPublicId}', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.INHERITANCE.MANAGE'));
        $app->get('/api/v1/admin/products/{productPublicId}/style-inheritance', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->put('/api/v1/admin/products/{productPublicId}/style-inheritance', ProductStylesAdminAction::class)
            ->add(($require)('PRODUCT.STYLES.MANAGE'));

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
        $app->get('/api/v1/admin/catalogue-nodes/{nodePublicId}/product-styles', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->post('/api/v1/admin/catalogue-nodes/{nodePublicId}/product-styles', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->delete('/api/v1/admin/catalogue-nodes/{nodePublicId}/product-styles/{stylePublicId}', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.LOOKUPS.MANAGE'));
        $app->get('/api/v1/admin/catalogue-nodes/{nodePublicId}/resolved-products', CatalogueNodesAdminAction::class)
            ->add(($require)('PRODUCT.READ'));

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
        $app->get('/api/v1/admin/product-migration/master-data-discovery', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/catalogue-mapping-readiness', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/master-data-plan-dry-run', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batch-a-classification', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batch-a-classification/decisions', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batch-a-classification/bulk-validate', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batch-b-specification-masters', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batch-b-specification-masters/decisions', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batch-c-style-field-mappings', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batch-c-style-field-mappings/decisions', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batch-c1-unresolved-concepts', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batch-c1-unresolved-concepts/decisions', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/batch-c2-final-package', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/batch-c2-final-package/decisions', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/cable-spec-field-mappings', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->post('/api/v1/admin/product-migration/cable-spec-field-mappings/decisions', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/cable-spec-d2-review', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/cable-spec-d2-decisions', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/cable-spec-d3-final', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/cable-spec-d4-apply', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/cable-spec-d5-review', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/cable-spec-d5-1-final', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/pilot-dry-run', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/catalogue-navigation-dry-run', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/pilot-f12-apply', ProductMigrationAdminAction::class)
            ->add(($require)('PRODUCT.MIGRATION.MANAGE'));
        $app->get('/api/v1/admin/product-migration/pilot-f12-live-sku', ProductMigrationAdminAction::class)
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
        $app->get('/api/v1/admin/product-variants/{variantPublicId}/pricing/legacy-identity', VariantLegacyPricingIdentityAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->post('/api/v1/admin/product-variants/{variantPublicId}/pricing/legacy-identity/verify', VariantLegacyPricingIdentityAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.VIEW'));
        $app->put('/api/v1/admin/product-variants/{variantPublicId}/pricing/legacy-identity', VariantLegacyPricingIdentityAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));
        $app->delete('/api/v1/admin/product-variants/{variantPublicId}/pricing/legacy-identity', VariantLegacyPricingIdentityAdminAction::class)
            ->add(($require)('PRODUCT.PRICING.MANAGE'));

        $app->post('/api/v1/admin/product-media/{mediaPublicId}/set-primary', SetPrimaryProductImageAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/product-media/{mediaPublicId}/role', UpdateProductMediaRoleAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->delete('/api/v1/admin/products/{productPublicId}/presentation-media-roles/{roleCode}', ClearProductPresentationMediaRoleAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));

        $app->post('/api/v1/admin/products/{productPublicId}/360-sets', CreateProduct360SetAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->post('/api/v1/admin/products/{productPublicId}/ar-models/upload-session', InitiateArModelUploadAction::class)
            ->add(($require)('PRODUCT.MEDIA.MANAGE'));
        $app->get('/api/v1/admin/products/{productPublicId}/3d-models', Product3DModelsAdminAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/products/{productPublicId}/3d/specification-source', GetProduct3DSpecificationSourceAction::class)
            ->add(($require)('PRODUCT.READ'));
        $app->get('/api/v1/admin/products/{productPublicId}/3d/accessory-specification-source', GetProduct3DAccessorySpecificationSourceAction::class)
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
