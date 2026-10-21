<?php

declare(strict_types=1);

namespace TriCab\Api\Config;

use InvalidArgumentException;
use TriCab\Api\Infrastructure\Cache\ApcuApplicationCache;
use TriCab\Core\Finance\Ap\ApAiService;

final readonly class Settings
{
    public const USER_REPOSITORY_MEMORY = 'memory';
    public const USER_REPOSITORY_AZURE_SQL = 'azure-sql';

    public const DB_AUTH_MODE_SQL_LOGIN = 'sql-login';
    public const DB_AUTH_MODE_ENTRA_CLI = 'entra-cli';
    public const DB_AUTH_MODE_MANAGED_IDENTITY = 'managed-identity';

    public const GUEST_EMAIL_PROVIDER_POSTMARK = 'postmark';
    public const GUEST_EMAIL_PROVIDER_DEVELOPMENT = 'development';
    public const GUEST_EMAIL_PROVIDER_MICROSOFT_GRAPH = 'microsoft-graph';

    public const DEFAULT_GUEST_JWT_TTL_SECONDS = 86400;
    public const MAX_GUEST_JWT_TTL_SECONDS = 86400;
    public const MIN_GUEST_JWT_SECRET_BYTES = 32;

    public const DEFAULT_TRUSTED_DEVICE_TTL_DAYS = 90;
    public const MIN_TRUSTED_DEVICE_TTL_DAYS = 1;
    public const MAX_TRUSTED_DEVICE_TTL_DAYS = 365;

    public const BLOB_AUTH_MODE_DISABLED = 'disabled';
    public const BLOB_AUTH_MODE_CONNECTION_STRING = 'connection-string';
    public const BLOB_AUTH_MODE_MEMORY = 'memory';

    public const BLOB_SCAN_MODE_AUTO_CLEAN = 'auto-clean';
    public const BLOB_SCAN_MODE_PENDING = 'pending-scan';
    public const BLOB_SCAN_MODE_DEFENDER = 'defender';

    public const CACHE_DRIVER_APCU = 'apcu';
    public const CACHE_DRIVER_ARRAY = 'array';
    public const CACHE_DRIVER_FILE = 'file';

    public const FOUNDRY_AUTH_MODE_MANAGED_IDENTITY = 'managed-identity';
    public const FOUNDRY_AUTH_MODE_API_KEY = 'api-key';
    public const RFQ_MAIL_MODE_OFF = 'off';
    public const RFQ_MAIL_MODE_SHADOW = 'shadow';
    public const RFQ_MAIL_MODE_LIVE = 'live';

    public const PRICING_COST_SOURCE_LEGACY_COST_API = 'legacy-cost-api';
    public const PRICING_COST_SOURCE_LEGACY_FALLBACK = 'legacy-fallback';
    public const PRICING_COST_SOURCE_AZURE_FIRST = 'azure-first';

    /**
     * The product finder is a loopback-only sidecar. Anything else is a
     * misconfiguration, not a deployment option.
     *
     * @var list<string>
     */
    public const PRODUCT_FINDER_ALLOWED_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    /**
     * @param list<string> $corsAllowedOrigins
     * @param list<string> $passkeyAllowedOrigins
     * @param list<string> $financeApInternalEmailDomains
     */
    public function __construct(
        public string $appEnv,
        public string $appName,
        public bool $appDebug,
        public bool $devAuthEnabled,
        public string $devAuthDefaultTenant,
        public string $devAuthDefaultSubject,
        public string $devAuthDefaultEmail,
        public string $entraTenantId,
        public string $entraClientId,
        public string $entraAudience,
        public string $entraRequiredScope,
        public string $entraSubjectClaim,
        public string $googleClientId,
        public string $googleIosClientId,
        public string $googleAndroidClientId,
        public string $appleClientId,
        public string $appleNativeClientId,
        public string $appleTeamId,
        public string $appleKeyId,
        public string $applePrivateKey,
        public string $appleRedirectUri,
        public array $corsAllowedOrigins,
        public string $userRepository,
        public string $dbAuthMode,
        public string $azureCliBinary,
        public string $dbDriver,
        public string $dbHost,
        public int $dbPort,
        public string $dbDatabase,
        public string $dbUsername,
        public string $dbPassword,
        public bool $dbEncrypt,
        public bool $dbTrustServerCertificate,
        public string $guestEmailProvider,
        public bool $guestOtpDevReveal,
        public string $postmarkServerToken,
        public string $postmarkFromEmail,
        public string $postmarkFromName,
        public string $postmarkMessageStream,
        public string $graphTenantId,
        public string $graphClientId,
        public string $graphClientSecret,
        public string $rfqMailbox,
        public string $guestOtpEmailLogoUrl,
        public string $guestJwtIssuer,
        public string $guestJwtAudience,
        public string $guestJwtSecret,
        public int $guestJwtTtlSeconds,
        public int $guestJwtClockSkewSeconds,
        public int $trustedDeviceTtlDays,
        public string $blobAuthMode,
        public string $blobConnectionString,
        public string $blobAccountName,
        public string $blobAccountKey,
        public string $blobEndpoint,
        public string $blobContainerProducts,
        public string $blobContainerTriexpense,
        public string $blobContainerCatalogues,
        public string $blobCdnBaseUrl,
        public string $blobScanMode,
        public int $blobUploadSasTtlSeconds,
        public int $blobDownloadSasTtlSeconds,
        public bool $performanceLogging,
        public bool $performanceSqlVerbose,
        public string $appCacheDriver,
        public string $appCachePath,
        public int $dbLoginTimeoutSeconds,
        public bool $dbConnectRetryEnabled,
        public int $dbConnectRetryMaxAttempts,
        public int $dbConnectRetryDelayMs,
        public bool $product3dEnabled,
        public string $product3dProvider,
        public bool $product3dGenerationEnabled,
        public bool $foundryEnabled,
        public string $foundryEndpoint,
        public string $foundryModelDeployment,
        public string $foundryAuthMode,
        public string $foundryApiKey,
        public int $foundryTimeoutSeconds,
        public int $foundryMaxToolCalls,
        public int $foundryTurnDeadlineSeconds,
        public bool $passkeyEnabled,
        public string $passkeyRpId,
        public string $passkeyRpName,
        public array $passkeyAllowedOrigins,
        public string $passkeyPublicAppBaseUrl,
        public int $passkeySetupTokenTtlSeconds,
        public int $passkeyRegistrationChallengeTtlSeconds,
        public int $passkeyAuthChallengeTtlSeconds,
        public bool $passkeyFakeCeremony,
        public bool $legacyCostApiEnabled = false,
        public string $legacyCostApiUrl = 'https://www.tricab.com/cost-api/',
        public int $legacyCostApiTimeoutSeconds = 8,
        public string $pricingCostSource = self::PRICING_COST_SOURCE_LEGACY_FALLBACK,
        public string $pricingDefaultTmmktmMargin = '0',
        public bool $productFinderEnabled = false,
        public string $productFinderBaseUrl = 'http://127.0.0.1:8001',
        public int $productFinderTimeoutSeconds = 20,
        public bool $customerContactModuleEnabled = false,
        public string $customerContactAccountsLogin = '',
        public string $customerContactCoordinatorLogin = '',
        public string $customerContactAccountsMailbox = '',
        public string $customerContactWebBaseUrl = '',
        public string $customerContactMailFrom = '',
        public bool $customerContactEmailEnabled = false,
        public bool $rfqParserEnabled = false,
        public string $rfqParserBaseUrl = 'http://127.0.0.1:8002',
        public string $rfqParserInternalToken = '',
        public bool $rfqParserAllowModel = false,
        public string $rfqReaderWakePath = 'var/rfq-reader',
        public string $blobContainerRfq = 'rfq-documents',
        public string $rfqDefaultPayTermsCode = 'EOM030ND',
        public bool $rfqSyncEnabled = false,
        public string $triexpenseMailFrom = 'ap@tricab.com',
        public bool $triexpenseEmailEnabled = false,
        public string $triexpenseEmailTestRecipient = '',
        public string $onboardingReminderEmails = '',
        public string $onboardingEmailTestRecipient = '',
        public string $blobContainerOnboarding = 'onboarding',
        public string $blobContainerFinance = 'finance-attachments',
        public string $triexpenseAppBaseUrl = '',
        public int $triexpenseGraphConnectTimeoutSeconds = 5,
        public int $triexpenseGraphRequestTimeoutSeconds = 15,
        public bool $triexpenseOcrEnabled = false,
        public string $triexpenseDocIntelEndpoint = '',
        public string $triexpenseDocIntelKey = '',
        public string $triexpenseOpenAiEndpoint = '',
        public string $triexpenseOpenAiKey = '',
        public string $triexpenseOpenAiDeployment = 'gpt-4o-mini',
        public int $triexpenseOcrConnectTimeoutSeconds = 5,
        public int $triexpenseOcrRequestTimeoutSeconds = 30,
        public string $triexpensePermissionMode = 'hybrid',
        public bool $triexpenseLegacyReadEnabled = false,
        public bool $quotesOrdersLegacyReadEnabled = false,
        public bool $quotesOrdersLegacyWriteEnabled = false,
        public ?string $quotesOrdersLegacyWriteDsn = null,
        public ?string $quotesOrdersLegacyWriteUser = null,
        public ?string $quotesOrdersLegacyWritePassword = null,
        public ?string $quotesOrdersLegacyWriteSslMode = null,
        public ?string $quotesOrdersLegacyWriteTableSuffix = null,
        public bool $quotesOrdersLegacyWriteTableSuffixConfigured = false,
        public bool $customerContactE2eMode = false,
        public bool $financeModuleEnabled = false,
        public bool $financeTaskEnabled = false,
        public bool $financeCreditEnabled = false,
        public bool $financeCollectEnabled = false,
        public bool $financeArEnabled = false,
        public bool $financeApEnabled = false,
        public bool $financeMailEnabled = false,
        public string $financeMailTestRecipient = '',
        public string $financeGraphTenantId = '',
        public string $financeGraphClientId = '',
        public string $financeGraphClientSecret = '',
        public bool $financeMailboxReadEnabled = false,
        public string $odooUrl = '',
        public string $odooApiKey = '',
        public string $odooCompanyIds = '',
        public string $odooApiKeyExpires = '',
        public bool $financeAiEnabled = false,
        public string $financeAiBaseUrl = 'http://127.0.0.1:8003',
        public string $financeAiInternalToken = '',
        public string $financeTaskTimezone = 'Australia/Melbourne',
        public string $financeTaskMailFrom = '',
        public bool $financeTaskPreparerDigestEnabled = false,
        public bool $financeTaskSendAsUser = true,
        public string $financeArMailbox = 'ar@tricab.com',
        public string $financeArCreditNotesUrl = '',
        public string $financeArTimezone = 'Australia/Melbourne',
        public bool $financeArSendingEnabled = false,
        public string $financeArPublicOrigin = '',
        public int $financeArFormLinkHours = 72,
        public bool $financeArSampleFiles = false,
        public string $financeApTimezone = 'Australia/Melbourne',
        public string $financeApMailbox = 'ap@tricab.com',
        public string $financeApPublicOrigin = '',
        public bool $financeApSendingEnabled = false,
        public bool $financeApSuggestReplies = true,
        public float $financeApConfidenceThreshold = 0.80,
        public array $financeApInternalEmailDomains = [],
        public int $financeApMinAttachmentBytes = 25600,
        public int $financeApMaxScanMessages = 0,
        public bool $financeApSampleFiles = false,
        // Foundry `output_config.effort`; '' sends none, so the model's own default applies.
        public string $foundryEffort = '',
        public bool $mailModuleEnabled = false,
        public string $rfqMailGraphTenantId = '',
        public string $rfqMailGraphClientId = '',
        public string $rfqMailGraphClientSecret = '',
        public bool $rfqMailReadEnabled = false,
        public string $rfqMailMode = self::RFQ_MAIL_MODE_OFF,
        public string $rfqMailMailbox = 'melbourne@tricab.com',
        public int $rfqMailFirstRunDays = 2,
        public string $rfqMailStatePath = 'var/rfq-mail',
        public int $rfqMailPassSeconds = 100,
        public int $rfqMailHeartbeatMinutes = 360,
        public string $rfqMailClassifierModel = 'claude-haiku-5-5',
        public int $rfqMailClassifierTimeoutSeconds = 30,
        /** @var list<string> */
        public array $rfqMailOwnAddresses = [],
        // HMAC key for stable product image addresses; '' derives one from the blob account key.
        public string $mediaUrlSigningSecret = '',
        public string $publicApiOrigin = '',
        // NPR screening signatories (lower-case emails). Empty until TSD confirms the signing policy:
        // requesting screening and deciding stay blocked.
        /** @var list<string> */
        public array $nprScreeningSignatoryEmails = [],
        // NPR attachments: a private container on the existing storage connection (N2.3).
        public string $nprAttachmentsContainer = 'npr-attachments',
        // NPR workflow email (N2.4). Mode: off (written as suppressed, never sent), test (every message goes to
        // nprMailTestRecipient) or live. Addresses are settings, never code.
        public string $nprMailMode = 'off',
        public string $nprMailTestRecipient = '',
        public string $nprMailSender = '',
        public string $nprDeputyCeoEmail = '',
        public string $nprTsdQueueEmail = '',
        public string $nprAppBaseUrl = 'https://tricab.ai',
        public string $nprMailWakePath = 'var/npr-mail',
        // CableFit research agent (C3): advisory web research, run by the cablefit-research WebJob.
        public bool $cablefitResearchEnabled = false,
        public string $cablefitResearchModel = 'claude-sonnet-5-5',
        public int $cablefitResearchMaxSearches = 8,
        public int $cablefitResearchDailyRunsPerUser = 10,
        public int $cablefitResearchTimeoutSeconds = 300,
        public int $cablefitResearchIdleCheckMinutes = 1440,
        public string $cablefitResearchWakePath = 'var/cablefit-research',
    ) {
    }

    /**
     * The finance AI service answers over loopback inside the App Service, and like
     * the RFQ parser it has no authentication of its own beyond the internal token, so
     * enabled with an empty token counts as disabled: the service refuses every call
     * without one, and switching it on could only ever fail. Same `FINANCE_AI_ENABLED`
     * setting as `startup.sh`, which starts the process. The base URL is checked
     * to be loopback when the service is enabled, at load.
     */
    public function isFinanceAiEnabled(): bool
    {
        if (!$this->financeAiEnabled || $this->financeAiInternalToken === '') {
            return false;
        }

        $host = parse_url($this->financeAiBaseUrl, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::PRODUCT_FINDER_ALLOWED_HOSTS, true);
    }

    /**
     * Finance mail sends only when switched on AND the finance Graph registration
     * (its own tenant, client id and secret, never the platform's `GRAPH_*`) is
     * complete. Enabled with any of the three empty counts as disabled, as the RFQ
     * parser's setting does.
     */
    public function isFinanceMailSendingEnabled(): bool
    {
        return $this->financeMailEnabled
            && trim($this->financeGraphTenantId) !== ''
            && trim($this->financeGraphClientId) !== ''
            && trim($this->financeGraphClientSecret) !== '';
    }

    /**
     * Mailbox reading is its own switch (F1.11): reading real mail needs the registration's
     * Mail.Read consent, which sending does not, so FINANCE_MAIL_ENABLED never turns it on.
     * On with any of the three finance Graph settings empty is treated as off.
     */
    public function isFinanceMailboxReadingEnabled(): bool
    {
        return $this->financeMailboxReadEnabled
            && trim($this->financeGraphTenantId) !== ''
            && trim($this->financeGraphClientId) !== ''
            && trim($this->financeGraphClientSecret) !== '';
    }
    /**
     * The Odoo base URL, only when it is an `https://` address with a host and no embedded
     * credentials (the API key goes in a header, never a URL); null otherwise, which
     * counts as not configured. An `http` Odoo would send the key in clear.
     */
    public function odooBaseUrlOrNull(): ?string
    {
        $url = trim($this->odooUrl);
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * `ODOO_COMPANY_IDS` as numbers, `2,4` as `[2, 4]`; anything that is not a whole number is dropped.
     *
     * @return list<int>
     */
    public function odooCompanyIdList(): array
    {
        $ids = [];
        foreach (explode(',', $this->odooCompanyIds) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The Odoo reference-list sync is configured: an https URL, a key, and at least one
     * company id (the portal's rule). Not configured, the sync refuses and no job is registered.
     */
    public function isOdooConfigured(): bool
    {
        return $this->odooBaseUrlOrNull() !== null && trim($this->odooApiKey) !== '' && $this->odooCompanyIdList() !== [];
    }

    public function financeMailTestRecipientOrNull(): ?string
    {
        $recipient = strtolower(trim($this->financeMailTestRecipient));

        return $recipient !== '' ? $recipient : null;
    }

    /**
     * The inbox opens a message's body and attachments from Exchange through the RFQ Graph registration:
     * `RFQ_MAIL_GRAPH_*` when all three are set, else the platform's `GRAPH_*` (`ai-rfq-parser-mail`, 19 Oct
     * 2026). Never Finance's. With any of the three empty, opening a body answers 503 and nothing touches a network.
     */
    public function isMailBodyReadingConfigured(): bool
    {
        return $this->rfqMailGraphTenantId !== ''
            && $this->rfqMailGraphClientId !== ''
            && $this->rfqMailGraphClientSecret !== '';
    }

    /**
     * The RFQ mail reader (M3) and classifier (M4). Two switches: `RFQ_MAIL_READ_ENABLED` is the pause
     * switch, `RFQ_MAIL_MODE` says what a running reader does (`off` nothing at all, `shadow` classify and
     * record, `live` the same until drafts exist, M6). Both must say go. Nothing is read from Exchange
     * otherwise.
     */
    public function isRfqMailReaderRunning(): bool
    {
        return $this->rfqMailReadEnabled && $this->rfqMailMode !== self::RFQ_MAIL_MODE_OFF;
    }

    /**
     * The CableFit research agent calls Foundry with the web search tool. Switched on by
     * `CABLEFIT_RESEARCH_ENABLED`; it needs the platform's Foundry connection, a deployment name, and (in
     * api-key mode) the key.
     */
    public function isCableFitResearchConfigured(): bool
    {
        return $this->cablefitResearchEnabled
            && $this->foundryEnabled
            && $this->foundryEndpoint !== ''
            && $this->cablefitResearchModel !== ''
            && ($this->foundryAuthMode !== self::FOUNDRY_AUTH_MODE_API_KEY || $this->foundryApiKey !== '');
    }

    /** The classifier calls Foundry; it needs the platform's Foundry connection and a deployment name. */
    public function isRfqMailClassifierConfigured(): bool
    {
        return $this->foundryEnabled && $this->foundryEndpoint !== '' && $this->rfqMailClassifierModel !== '';
    }

    /**
     * Finance (the five finance apps). An app switch does nothing while the
     * module switch is off, so a half-built app can never be reached by turning
     * one setting on.
     */
    public function financeAppEnabled(string $appKey): bool
    {
        if (!$this->financeModuleEnabled) {
            return false;
        }

        return match ($appKey) {
            'TASK' => $this->financeTaskEnabled,
            'CREDIT' => $this->financeCreditEnabled,
            'COLLECT' => $this->financeCollectEnabled,
            'AR' => $this->financeArEnabled,
            'AP' => $this->financeApEnabled,
            default => false,
        };
    }

    /**
     * Graph credentials are shared with guest OTP delivery; TriExpense only needs
     * the client-credentials triple plus its own from-mailbox.
     */
    public function isTriexpenseGraphConfigured(): bool
    {
        return trim($this->graphTenantId) !== ''
            && trim($this->graphClientId) !== ''
            && trim($this->graphClientSecret) !== ''
            && trim($this->triexpenseMailFrom) !== '';
    }

    /**
     * True only when Graph can actually deliver. TRIEXPENSE_EMAIL_ENABLED alone
     * never sends: the adapter records intent when credentials are absent.
     */
    public function isTriexpenseEmailSendingEnabled(): bool
    {
        return $this->triexpenseEmailEnabled && $this->isTriexpenseGraphConfigured();
    }

    public function triexpenseEmailTestRecipientOrNull(): ?string
    {
        $recipient = strtolower(trim($this->triexpenseEmailTestRecipient));

        return $recipient !== '' ? $recipient : null;
    }

    public function isTriexpenseDocumentIntelligenceConfigured(): bool
    {
        return trim($this->triexpenseDocIntelEndpoint) !== '' && trim($this->triexpenseDocIntelKey) !== '';
    }

    public function isTriexpenseOpenAiConfigured(): bool
    {
        return trim($this->triexpenseOpenAiEndpoint) !== ''
            && trim($this->triexpenseOpenAiKey) !== ''
            && trim($this->triexpenseOpenAiDeployment) !== '';
    }

    /**
     * OCR needs Document Intelligence. OpenAI normalisation is optional polish
     * on top, so it is deliberately not part of this gate.
     */
    public function isTriexpenseOcrConfigured(): bool
    {
        return $this->triexpenseOcrEnabled && $this->isTriexpenseDocumentIntelligenceConfigured();
    }

    /**
     * The finder answers over loopback inside the App Service, so — unlike
     * `isTrustedFoundryEndpoint`, which guards a call out to Azure AI over the
     * public internet — this checks the opposite thing: that the URL has *not*
     * been pointed off-box. A misconfigured value here would send a customer's
     * requirement to a third party, and the process it is meant to reach has no
     * authentication because it is only ever reachable from this instance.
     */
    public function isProductFinderEnabled(): bool
    {
        if (!$this->productFinderEnabled) {
            return false;
        }

        $host = parse_url($this->productFinderBaseUrl, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::PRODUCT_FINDER_ALLOWED_HOSTS, true);
    }

    /**
     * The RFQ parser is the finder's loopback pattern with one addition: a
     * bearer token (`RFQ_PARSER_INTERNAL_TOKEN`) the parser checks on every
     * `/v1` call. Both are required; the parser refuses everything without a
     * token, so switching it on without one could only ever fail. Same
     * `RFQ_PARSER_ENABLED` setting as `startup.sh`, which starts the process.
     */
    public function isRfqParserEnabled(): bool
    {
        if (!$this->rfqParserEnabled || $this->rfqParserInternalToken === '') {
            return false;
        }

        $host = parse_url($this->rfqParserBaseUrl, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::PRODUCT_FINDER_ALLOWED_HOSTS, true);
    }

    public function isLegacyCostApiEnabled(): bool
    {
        return $this->legacyCostApiEnabled && trim($this->legacyCostApiUrl) !== '';
    }

    public function isLegacyCostApiPrimaryCostSource(): bool
    {
        return $this->isLegacyCostApiEnabled()
            && $this->pricingCostSource === self::PRICING_COST_SOURCE_LEGACY_COST_API;
    }

    public function isDevelopment(): bool
    {
        return $this->appEnv === 'development';
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }

    public function isDevelopmentAuthActive(): bool
    {
        return $this->isDevelopment() && $this->devAuthEnabled;
    }

    public function usesAzureSqlUserRepository(): bool
    {
        return $this->userRepository === self::USER_REPOSITORY_AZURE_SQL;
    }

    /**
     * Fake WebAuthn is opt-in for in-memory development tests only.
     * Azure SQL, production, and any environment without PASSKEY_FAKE_CEREMONY=true
     * always use web-auth/webauthn-lib.
     */
    public function usesFakePasskeyCeremony(): bool
    {
        return $this->passkeyFakeCeremony
            && $this->isDevelopment()
            && !$this->usesAzureSqlUserRepository();
    }

    public function usesEntraCliAuth(): bool
    {
        return $this->dbAuthMode === self::DB_AUTH_MODE_ENTRA_CLI;
    }

    public function usesManagedIdentityAuth(): bool
    {
        return $this->dbAuthMode === self::DB_AUTH_MODE_MANAGED_IDENTITY;
    }

    public function usesSqlLoginAuth(): bool
    {
        return $this->dbAuthMode === self::DB_AUTH_MODE_SQL_LOGIN;
    }

    public function isEntraJwtConfigured(): bool
    {
        return $this->entraTenantId !== '' && $this->entraAudience !== '';
    }

    public function usesPostmarkGuestEmail(): bool
    {
        return $this->guestEmailProvider === self::GUEST_EMAIL_PROVIDER_POSTMARK;
    }

    public function usesDevelopmentGuestEmail(): bool
    {
        return $this->guestEmailProvider === self::GUEST_EMAIL_PROVIDER_DEVELOPMENT;
    }

    public function usesMicrosoftGraphGuestEmail(): bool
    {
        return $this->guestEmailProvider === self::GUEST_EMAIL_PROVIDER_MICROSOFT_GRAPH;
    }

    public function isGuestJwtConfigured(): bool
    {
        return $this->guestJwtIssuer !== ''
            && $this->guestJwtAudience !== ''
            && strlen($this->guestJwtSecret) >= self::MIN_GUEST_JWT_SECRET_BYTES;
    }

    public function isGoogleAuthConfigured(): bool
    {
        return $this->isGuestJwtConfigured() && $this->googleAllowedClientIds() !== [];
    }

    /**
     * @return list<string>
     */
    public function googleAllowedClientIds(): array
    {
        $ids = [];
        foreach ([$this->googleClientId, $this->googleIosClientId, $this->googleAndroidClientId] as $id) {
            $trimmed = trim($id);
            if ($trimmed !== '' && !in_array($trimmed, $ids, true)) {
                $ids[] = $trimmed;
            }
        }

        return $ids;
    }

    public function isAppleNativeAuthConfigured(): bool
    {
        return $this->isGuestJwtConfigured() && trim($this->appleNativeClientId) !== '';
    }

    public function isAppleAuthConfigured(): bool
    {
        if (!$this->isGuestJwtConfigured()) {
            return false;
        }

        if (
            trim($this->appleClientId) === ''
            || trim($this->appleTeamId) === ''
            || trim($this->appleKeyId) === ''
            || trim($this->applePrivateKey) === ''
            || trim($this->appleRedirectUri) === ''
        ) {
            return false;
        }

        if (!str_starts_with(strtolower(trim($this->appleRedirectUri)), 'https://')) {
            return false;
        }

        try {
            $this->resolvedApplePrivateKeyPem();
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    public function isPasskeyAuthConfigured(): bool
    {
        return $this->passkeyEnabled
            && $this->isGuestJwtConfigured()
            && trim($this->passkeyRpId) !== ''
            && $this->passkeyAllowedOrigins !== []
            && trim($this->passkeyPublicAppBaseUrl) !== '';
    }

    /**
     * Resolves APPLE_PRIVATE_KEY from an inline PEM (with escaped \\n) or file://absolute/path.
     * Never log or expose the returned material.
     */
    public function resolvedApplePrivateKeyPem(): string
    {
        $raw = trim($this->applePrivateKey);
        if ($raw === '') {
            throw new InvalidArgumentException('APPLE_PRIVATE_KEY is required.');
        }

        if (str_starts_with($raw, 'file://')) {
            $path = substr($raw, strlen('file://'));
            if ($path === '' || !str_starts_with($path, '/')) {
                throw new InvalidArgumentException('APPLE_PRIVATE_KEY file:// path must be absolute.');
            }
            if (!is_readable($path)) {
                throw new InvalidArgumentException('APPLE_PRIVATE_KEY file path is not readable.');
            }
            $contents = file_get_contents($path);
            if (!is_string($contents) || trim($contents) === '') {
                throw new InvalidArgumentException('APPLE_PRIVATE_KEY file is empty.');
            }

            return self::normalizePem($contents);
        }

        return self::normalizePem($raw);
    }

    private static function normalizePem(string $value): string
    {
        $pem = str_replace(["\\n", "\r\n", "\r"], "\n", trim($value));
        if (
            !str_contains($pem, 'BEGIN PRIVATE KEY')
            && !str_contains($pem, 'BEGIN EC PRIVATE KEY')
        ) {
            throw new InvalidArgumentException('APPLE_PRIVATE_KEY must be a PEM private key.');
        }

        return $pem;
    }

    public function isProductBlobEnabled(): bool
    {
        return $this->blobAuthMode === self::BLOB_AUTH_MODE_CONNECTION_STRING
            || $this->blobAuthMode === self::BLOB_AUTH_MODE_MEMORY;
    }

    public function usesMemoryProductBlobStore(): bool
    {
        return $this->blobAuthMode === self::BLOB_AUTH_MODE_MEMORY
            || ($this->blobAuthMode === self::BLOB_AUTH_MODE_DISABLED && !$this->isProduction());
    }

    /**
     * Gated local delivery for in-memory product blobs (img tags cannot send Bearer tokens).
     * Requires APP_ENV=development and the memory blob adapter (BLOB_AUTH_MODE=memory,
     * or disabled→memory outside production). Never true in production.
     */
    public function isDevMemoryProductFileDeliveryEnabled(): bool
    {
        return $this->isDevelopment() && $this->usesMemoryProductBlobStore();
    }

    public function productFileScanAutoCleans(): bool
    {
        return $this->blobScanMode === self::BLOB_SCAN_MODE_AUTO_CLEAN;
    }

    public function isFoundryConfigured(): bool
    {
        return $this->foundryEnabled
            && $this->foundryEndpoint !== ''
            && $this->foundryModelDeployment !== '';
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env): self
    {
        $appEnv = self::string($env, 'APP_ENV', 'production');

        // Default differs by environment: production must not silently use in-memory.
        $defaultUserRepository = $appEnv === 'production'
            ? self::USER_REPOSITORY_AZURE_SQL
            : self::USER_REPOSITORY_MEMORY;
        $userRepositoryRaw = self::string($env, 'USER_REPOSITORY', $defaultUserRepository);
        $userRepository = strtolower($userRepositoryRaw);
        if (
            $userRepository !== self::USER_REPOSITORY_MEMORY
            && $userRepository !== self::USER_REPOSITORY_AZURE_SQL
        ) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported USER_REPOSITORY "%s". Supported values: %s, %s.',
                $userRepositoryRaw,
                self::USER_REPOSITORY_MEMORY,
                self::USER_REPOSITORY_AZURE_SQL,
            ));
        }

        if ($appEnv === 'production' && $userRepository === self::USER_REPOSITORY_MEMORY) {
            $allowMemoryInProduction = strtolower(
                self::string($env, 'ALLOW_IN_MEMORY_REPOSITORY_IN_PRODUCTION', 'false'),
            ) === 'true';
            if (!$allowMemoryInProduction) {
                throw new InvalidArgumentException(
                    'USER_REPOSITORY=memory is not allowed when APP_ENV=production.',
                );
            }
        }

        $dbAuthModeRaw = self::string($env, 'DB_AUTH_MODE', self::DB_AUTH_MODE_SQL_LOGIN);
        $dbAuthMode = strtolower(trim($dbAuthModeRaw));
        if (
            $dbAuthMode !== self::DB_AUTH_MODE_SQL_LOGIN
            && $dbAuthMode !== self::DB_AUTH_MODE_ENTRA_CLI
            && $dbAuthMode !== self::DB_AUTH_MODE_MANAGED_IDENTITY
        ) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported DB_AUTH_MODE "%s". Supported values: %s, %s, %s.',
                $dbAuthModeRaw,
                self::DB_AUTH_MODE_SQL_LOGIN,
                self::DB_AUTH_MODE_ENTRA_CLI,
                self::DB_AUTH_MODE_MANAGED_IDENTITY,
            ));
        }

        $subjectClaim = strtolower(self::string($env, 'ENTRA_SUBJECT_CLAIM', 'oid'));
        if ($subjectClaim !== 'oid' && $subjectClaim !== 'sub') {
            $subjectClaim = 'oid';
        }

        $guestEmailProvider = strtolower(
            self::string(
                $env,
                'GUEST_EMAIL_PROVIDER',
                $appEnv === 'production'
                    ? self::GUEST_EMAIL_PROVIDER_POSTMARK
                    : self::GUEST_EMAIL_PROVIDER_DEVELOPMENT,
            ),
        );
        if (
            $guestEmailProvider !== self::GUEST_EMAIL_PROVIDER_POSTMARK
            && $guestEmailProvider !== self::GUEST_EMAIL_PROVIDER_DEVELOPMENT
            && $guestEmailProvider !== self::GUEST_EMAIL_PROVIDER_MICROSOFT_GRAPH
        ) {
            $guestEmailProvider = $appEnv === 'production'
                ? self::GUEST_EMAIL_PROVIDER_POSTMARK
                : self::GUEST_EMAIL_PROVIDER_DEVELOPMENT;
        }

        $guestOtpDevReveal = self::bool($env, 'GUEST_OTP_DEV_REVEAL', false);
        $postmarkServerToken = self::string($env, 'POSTMARK_SERVER_TOKEN', '');
        $postmarkFromEmail = self::string($env, 'POSTMARK_FROM_EMAIL', '');
        $postmarkFromName = self::string($env, 'POSTMARK_FROM_NAME', 'TriCab');
        $postmarkMessageStream = self::string($env, 'POSTMARK_MESSAGE_STREAM', 'outbound');

        $graphTenantId = self::string($env, 'GRAPH_TENANT_ID', '');
        $graphClientId = self::string($env, 'GRAPH_CLIENT_ID', '');
        $graphClientSecret = self::string($env, 'GRAPH_CLIENT_SECRET', '');
        $rfqMailbox = self::string($env, 'RFQ_MAILBOX', '');
        $guestOtpEmailLogoUrl = self::string($env, 'GUEST_OTP_EMAIL_LOGO_URL', '');

        $guestJwtIssuer = self::string($env, 'GUEST_JWT_ISSUER', 'https://api.tricab.local');
        $guestJwtAudience = self::string($env, 'GUEST_JWT_AUDIENCE', 'tricab-api');
        $guestJwtSecret = self::string($env, 'GUEST_JWT_SECRET', '');
        $guestJwtTtlSeconds = self::int($env, 'GUEST_JWT_TTL_SECONDS', self::DEFAULT_GUEST_JWT_TTL_SECONDS);
        $guestJwtClockSkewSeconds = self::int($env, 'GUEST_JWT_CLOCK_SKEW_SECONDS', 30);
        $trustedDeviceTtlDays = self::boundedInt(
            $env,
            'TRUSTED_DEVICE_TTL_DAYS',
            self::DEFAULT_TRUSTED_DEVICE_TTL_DAYS,
            self::MIN_TRUSTED_DEVICE_TTL_DAYS,
            self::MAX_TRUSTED_DEVICE_TTL_DAYS,
        );

        if ($guestJwtTtlSeconds < 1 || $guestJwtTtlSeconds > self::MAX_GUEST_JWT_TTL_SECONDS) {
            throw new InvalidArgumentException(
                'GUEST_JWT_TTL_SECONDS must be between 1 and ' . self::MAX_GUEST_JWT_TTL_SECONDS . '.',
            );
        }

        if ($guestJwtClockSkewSeconds < 0 || $guestJwtClockSkewSeconds > 300) {
            throw new InvalidArgumentException('GUEST_JWT_CLOCK_SKEW_SECONDS must be between 0 and 300.');
        }

        if ($appEnv === 'production' && $guestEmailProvider === self::GUEST_EMAIL_PROVIDER_DEVELOPMENT) {
            throw new InvalidArgumentException(
                'GUEST_EMAIL_PROVIDER=development is not allowed when APP_ENV=production.',
            );
        }

        if ($appEnv === 'production' && $guestOtpDevReveal) {
            throw new InvalidArgumentException(
                'GUEST_OTP_DEV_REVEAL cannot be enabled when APP_ENV=production.',
            );
        }

        if ($guestJwtSecret !== '' && strlen($guestJwtSecret) < self::MIN_GUEST_JWT_SECRET_BYTES) {
            throw new InvalidArgumentException(
                'GUEST_JWT_SECRET must be at least 32 bytes when provided.',
            );
        }

        $blobAuthMode = strtolower(self::string($env, 'BLOB_AUTH_MODE', self::BLOB_AUTH_MODE_DISABLED));
        if (
            $blobAuthMode !== self::BLOB_AUTH_MODE_DISABLED
            && $blobAuthMode !== self::BLOB_AUTH_MODE_CONNECTION_STRING
            && $blobAuthMode !== self::BLOB_AUTH_MODE_MEMORY
        ) {
            throw new InvalidArgumentException(
                'Unsupported BLOB_AUTH_MODE. Supported values: disabled, connection-string, memory.',
            );
        }

        if ($appEnv === 'production' && $blobAuthMode === self::BLOB_AUTH_MODE_MEMORY) {
            throw new InvalidArgumentException(
                'BLOB_AUTH_MODE=memory is not allowed when APP_ENV=production.',
            );
        }

        $blobConnectionString = self::string($env, 'BLOB_CONNECTION_STRING', '');
        $blobAccountName = self::string($env, 'BLOB_ACCOUNT_NAME', '');
        $blobAccountKey = self::string($env, 'BLOB_ACCOUNT_KEY', '');
        $blobEndpoint = self::string($env, 'BLOB_ENDPOINT', '');

        if ($blobConnectionString !== '') {
            $parsed = self::parseBlobConnectionString($blobConnectionString);
            $blobAccountName = $blobAccountName !== '' ? $blobAccountName : $parsed['accountName'];
            $blobAccountKey = $blobAccountKey !== '' ? $blobAccountKey : $parsed['accountKey'];
            $blobEndpoint = $blobEndpoint !== '' ? $blobEndpoint : $parsed['blobEndpoint'];
        }

        if ($blobAuthMode === self::BLOB_AUTH_MODE_CONNECTION_STRING) {
            if ($blobAccountName === '' || $blobAccountKey === '' || $blobEndpoint === '') {
                throw new InvalidArgumentException(
                    'BLOB_CONNECTION_STRING or BLOB_ACCOUNT_NAME/BLOB_ACCOUNT_KEY/BLOB_ENDPOINT are required when BLOB_AUTH_MODE=connection-string.',
                );
            }
        }

        $blobScanMode = strtolower(self::string(
            $env,
            'BLOB_SCAN_MODE',
            $appEnv === 'production' ? self::BLOB_SCAN_MODE_DEFENDER : self::BLOB_SCAN_MODE_AUTO_CLEAN,
        ));
        if (
            $blobScanMode !== self::BLOB_SCAN_MODE_AUTO_CLEAN
            && $blobScanMode !== self::BLOB_SCAN_MODE_PENDING
            && $blobScanMode !== self::BLOB_SCAN_MODE_DEFENDER
        ) {
            $blobScanMode = self::BLOB_SCAN_MODE_AUTO_CLEAN;
        }

        $blobUploadSasTtlSeconds = self::int($env, 'BLOB_UPLOAD_SAS_TTL_SECONDS', 900);
        if ($blobUploadSasTtlSeconds < 60 || $blobUploadSasTtlSeconds > 3600) {
            throw new InvalidArgumentException('BLOB_UPLOAD_SAS_TTL_SECONDS must be between 60 and 3600.');
        }
        $blobDownloadSasTtlSeconds = self::int($env, 'BLOB_DOWNLOAD_SAS_TTL_SECONDS', 300);
        if ($blobDownloadSasTtlSeconds < 60 || $blobDownloadSasTtlSeconds > 3600) {
            throw new InvalidArgumentException('BLOB_DOWNLOAD_SAS_TTL_SECONDS must be between 60 and 3600.');
        }

        $foundryEnabled = self::bool($env, 'FOUNDRY_ENABLED', false);
        $foundryEndpoint = rtrim(self::string($env, 'FOUNDRY_ENDPOINT', ''), '/');
        $foundryModelDeployment = self::string($env, 'FOUNDRY_MODEL_DEPLOYMENT', '');
        $foundryAuthMode = strtolower(self::string(
            $env,
            'FOUNDRY_AUTH_MODE',
            self::FOUNDRY_AUTH_MODE_MANAGED_IDENTITY,
        ));
        if (
            $foundryAuthMode !== self::FOUNDRY_AUTH_MODE_MANAGED_IDENTITY
            && $foundryAuthMode !== self::FOUNDRY_AUTH_MODE_API_KEY
        ) {
            throw new InvalidArgumentException(
                'Unsupported FOUNDRY_AUTH_MODE. Supported values: managed-identity, api-key.',
            );
        }

        $foundryApiKey = self::string($env, 'FOUNDRY_API_KEY', '');
        $foundryTimeoutSeconds = self::int($env, 'FOUNDRY_TIMEOUT_SECONDS', 30);
        if ($foundryTimeoutSeconds < 1 || $foundryTimeoutSeconds > 120) {
            throw new InvalidArgumentException('FOUNDRY_TIMEOUT_SECONDS must be between 1 and 120.');
        }
        $foundryMaxToolCalls = self::int($env, 'FOUNDRY_MAX_TOOL_CALLS', 5);
        if ($foundryMaxToolCalls < 1 || $foundryMaxToolCalls > 10) {
            throw new InvalidArgumentException('FOUNDRY_MAX_TOOL_CALLS must be between 1 and 10.');
        }
        $foundryTurnDeadlineSeconds = self::int($env, 'FOUNDRY_TURN_DEADLINE_SECONDS', 45);
        if ($foundryTurnDeadlineSeconds < 5 || $foundryTurnDeadlineSeconds > 300) {
            throw new InvalidArgumentException('FOUNDRY_TURN_DEADLINE_SECONDS must be between 5 and 300.');
        }
        $foundryEffort = strtolower(trim(self::string($env, 'FOUNDRY_EFFORT', '')));
        if (!in_array($foundryEffort, ['', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
            throw new InvalidArgumentException('FOUNDRY_EFFORT must be empty or one of low, medium, high, xhigh, max.');
        }

        if ($foundryEnabled && ($foundryEndpoint === '' || $foundryModelDeployment === '')) {
            throw new InvalidArgumentException(
                'FOUNDRY_ENDPOINT and FOUNDRY_MODEL_DEPLOYMENT are required when FOUNDRY_ENABLED=true.',
            );
        }

        if ($foundryEnabled && !self::isTrustedFoundryEndpoint($foundryEndpoint)) {
            throw new InvalidArgumentException(
                'FOUNDRY_ENDPOINT must be an HTTPS root endpoint on services.ai.azure.com.',
            );
        }

        if (
            $foundryEnabled
            && $foundryAuthMode === self::FOUNDRY_AUTH_MODE_API_KEY
            && $foundryApiKey === ''
        ) {
            throw new InvalidArgumentException(
                'FOUNDRY_API_KEY is required when FOUNDRY_ENABLED=true and FOUNDRY_AUTH_MODE=api-key.',
            );
        }

        if ($appEnv === 'production' && $foundryEnabled && $foundryAuthMode === self::FOUNDRY_AUTH_MODE_API_KEY) {
            throw new InvalidArgumentException(
                'FOUNDRY_AUTH_MODE=api-key is not allowed when APP_ENV=production.',
            );
        }

        $corsAllowedOrigins = self::csvList($env, 'CORS_ALLOWED_ORIGINS', 'http://localhost:5173');
        $passkeyEnabled = self::bool($env, 'PASSKEY_ENABLED', $appEnv !== 'production');
        $passkeyRpId = strtolower(trim(self::string(
            $env,
            'PASSKEY_RP_ID',
            $appEnv === 'production' ? 'tricab.com' : 'localhost',
        )));
        $passkeyRpName = self::string($env, 'PASSKEY_RP_NAME', 'TriCab');
        $passkeyAllowedOrigins = self::csvList(
            $env,
            'PASSKEY_ALLOWED_ORIGINS',
            $appEnv === 'production'
                ? 'https://www.tricab.com,https://tricab.com'
                : implode(',', $corsAllowedOrigins),
        );
        $passkeyPublicAppBaseUrl = rtrim(self::string(
            $env,
            'PASSKEY_PUBLIC_APP_BASE_URL',
            $appEnv === 'production'
                ? 'https://www.tricab.com'
                : ($corsAllowedOrigins[0] ?? 'http://localhost:5173'),
        ), '/');
        $passkeySetupTokenTtlSeconds = self::boundedInt($env, 'PASSKEY_SETUP_TOKEN_TTL_SECONDS', 900, 60, 3600);
        $passkeyRegistrationChallengeTtlSeconds = self::boundedInt(
            $env,
            'PASSKEY_REGISTRATION_CHALLENGE_TTL_SECONDS',
            300,
            30,
            600,
        );
        $passkeyAuthChallengeTtlSeconds = self::boundedInt(
            $env,
            'PASSKEY_AUTH_CHALLENGE_TTL_SECONDS',
            300,
            30,
            600,
        );
        $passkeyFakeCeremony = self::bool($env, 'PASSKEY_FAKE_CEREMONY', false);

        if ($passkeyEnabled) {
            self::assertPasskeyRpConfiguration(
                $appEnv,
                $passkeyRpId,
                $passkeyAllowedOrigins,
                $passkeyPublicAppBaseUrl,
            );
        }

        $legacyCostApiUrl = self::string($env, 'LEGACY_COST_API_URL', 'https://www.tricab.com/cost-api/');
        $legacyCostApiTimeoutSeconds = self::boundedInt($env, 'LEGACY_COST_API_TIMEOUT_SECONDS', 8, 1, 30);
        $legacyCostApiEnabled = self::bool($env, 'LEGACY_COST_API_ENABLED', false);
        $pricingCostSource = strtolower(trim(self::string($env, 'PRICING_COST_SOURCE', '')));
        if ($pricingCostSource === '') {
            $pricingCostSource = $appEnv === 'production'
                ? self::PRICING_COST_SOURCE_LEGACY_FALLBACK
                : ($legacyCostApiEnabled
                    ? self::PRICING_COST_SOURCE_LEGACY_COST_API
                    : self::PRICING_COST_SOURCE_AZURE_FIRST);
        }
        self::assertPricingCostSource($pricingCostSource);
        $pricingDefaultTmmktmMargin = trim(self::string($env, 'PRICING_DEFAULT_TMMKTM_MARGIN', '0'));
        self::assertPricingDefaultTmmktmMargin($pricingDefaultTmmktmMargin);

        $customerContactModuleEnabled = self::bool($env, 'CUSTOMER_CONTACT_MODULE_ENABLED', false);
        $customerContactCoordinatorLogin = strtolower(trim(self::string($env, 'CUSTOMER_CONTACT_COORDINATOR_LOGIN', '')));
        $rfqMailMode = strtolower(trim(self::string($env, 'RFQ_MAIL_MODE', self::RFQ_MAIL_MODE_OFF)));
        if (!in_array($rfqMailMode, [self::RFQ_MAIL_MODE_OFF, self::RFQ_MAIL_MODE_SHADOW, self::RFQ_MAIL_MODE_LIVE], true)) {
            throw new InvalidArgumentException('RFQ_MAIL_MODE must be one of: off, shadow, live.');
        }
        $rfqMailMailbox = strtolower(trim(self::string($env, 'RFQ_MAIL_MAILBOX', 'melbourne@tricab.com')));
        // The RFQ mail reader uses the RFQ Graph registration (Manusha, 19 Oct 2026): the platform's GRAPH_* app,
        // `ai-rfq-parser-mail`, which already holds Mail.Read. Setting all three RFQ_MAIL_GRAPH_* overrides it, so a
        // registration of its own can replace it later; a partial set is used as it is (and so stays unconfigured).
        $rfqMailGraph = [
            trim(self::string($env, 'RFQ_MAIL_GRAPH_TENANT_ID', '')),
            trim(self::string($env, 'RFQ_MAIL_GRAPH_CLIENT_ID', '')),
            trim(self::string($env, 'RFQ_MAIL_GRAPH_CLIENT_SECRET', '')),
        ];
        if ($rfqMailGraph === ['', '', '']) {
            $rfqMailGraph = [trim($graphTenantId), trim($graphClientId), trim($graphClientSecret)];
        }
        if (!str_contains($rfqMailMailbox, '@')) {
            throw new InvalidArgumentException('RFQ_MAIL_MAILBOX must be a mailbox address.');
        }
        $rfqMailStatePath = trim(self::string($env, 'RFQ_MAIL_STATE_PATH', 'var/rfq-mail'));
        if ($rfqMailStatePath === '') {
            $rfqMailStatePath = 'var/rfq-mail';
        }
        $rfqMailClassifierModel = trim(self::string($env, 'RFQ_MAIL_CLASSIFIER_MODEL', 'claude-haiku-5-5'));
        $customerContactAccountsMailbox = strtolower(trim(self::string($env, 'CUSTOMER_CONTACT_ACCOUNTS_MAILBOX', '')));
        if ($customerContactAccountsMailbox !== '' && !str_contains($customerContactAccountsMailbox, '@')) {
            throw new InvalidArgumentException('CUSTOMER_CONTACT_ACCOUNTS_MAILBOX must be a mailbox address when set.');
        }
        $customerContactWebBaseUrl = rtrim(trim(self::string($env, 'CUSTOMER_CONTACT_WEB_BASE_URL', '')), '/');
        if ($customerContactWebBaseUrl !== '' && !str_starts_with(strtolower($customerContactWebBaseUrl), 'https://')) {
            if ($appEnv === 'production' || !str_starts_with(strtolower($customerContactWebBaseUrl), 'http://')) {
                throw new InvalidArgumentException(
                    'CUSTOMER_CONTACT_WEB_BASE_URL must be an HTTPS URL (plain HTTP is accepted outside production only).',
                );
            }
        }
        $customerContactMailFrom = strtolower(trim(self::string($env, 'CUSTOMER_CONTACT_MAIL_FROM', '')));
        if ($customerContactMailFrom !== '' && !str_contains($customerContactMailFrom, '@')) {
            throw new InvalidArgumentException('CUSTOMER_CONTACT_MAIL_FROM must be a mailbox address when set.');
        }
        $customerContactEmailEnabled = self::bool($env, 'CUSTOMER_CONTACT_EMAIL_ENABLED', false);
        $customerContactE2eMode = self::bool($env, 'CUSTOMER_CONTACT_E2E_MODE', false);
        if ($customerContactE2eMode && ($appEnv === 'production' || $userRepository === self::USER_REPOSITORY_AZURE_SQL)) {
            throw new InvalidArgumentException(
                'CUSTOMER_CONTACT_E2E_MODE cannot be enabled when APP_ENV=production or USER_REPOSITORY=azure-sql. '
                . 'E2E mode does not replace Azure Customer Contact persistence. Refusing to start.',
            );
        }
        $rfqParserEnabled = self::bool($env, 'RFQ_PARSER_ENABLED', false);
        $rfqParserBaseUrl = rtrim(self::string($env, 'RFQ_PARSER_BASE_URL', 'http://127.0.0.1:8002'), '/');
        $rfqParserInternalToken = trim(self::string($env, 'RFQ_PARSER_INTERNAL_TOKEN', ''));
        // The pay term a new quote's dropdown starts on: the source's MAIL_DEFAULT_PAYTERMS.
        $rfqDefaultPayTermsCode = trim(self::string($env, 'RFQ_DEFAULT_PAY_TERMS', 'EOM030ND'));
        if ($rfqParserEnabled) {
            $rfqParserHost = parse_url($rfqParserBaseUrl, PHP_URL_HOST);
            if (!is_string($rfqParserHost) || !in_array(strtolower($rfqParserHost), self::PRODUCT_FINDER_ALLOWED_HOSTS, true)) {
                throw new InvalidArgumentException(
                    'RFQ_PARSER_BASE_URL must point at the loopback interface; '
                    . 'the RFQ parser runs inside this App Service and is not exposed.'
                );
            }
        }

        // Exactly `true`, as startup.sh tests it: "yes" or "1" must not make PHP expect a service that never started.
        $financeAiEnabled = ($env['FINANCE_AI_ENABLED'] ?? '') === 'true';
        $financeAiBaseUrl = rtrim(self::string($env, 'FINANCE_AI_BASE_URL', 'http://127.0.0.1:8003'), '/');
        if ($financeAiEnabled) {
            $financeAiHost = parse_url($financeAiBaseUrl, PHP_URL_HOST);
            if (!is_string($financeAiHost) || !in_array(strtolower($financeAiHost), self::PRODUCT_FINDER_ALLOWED_HOSTS, true)) {
                throw new InvalidArgumentException(
                    'FINANCE_AI_BASE_URL must point at the loopback interface; '
                    . 'the finance AI service runs inside this App Service and is not exposed.'
                );
            }
        }

        // Tri-Task decides "today" and "overdue" in this zone (decision D17), replacing the source's APP_TIMEZONE.
        $financeTaskTimezone = trim(self::string($env, 'FINANCE_TASK_TIMEZONE', 'Australia/Melbourne'));
        if ($financeTaskTimezone === '') {
            $financeTaskTimezone = 'Australia/Melbourne';
        }
        if (!in_array($financeTaskTimezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('FINANCE_TASK_TIMEZONE must be a time zone name such as Australia/Melbourne.');
        }

        // Tri-AR decides "today", "overdue" and the register's local days in this zone (decision D17, H4).
        $financeArTimezone = trim(self::string($env, 'FINANCE_AR_TIMEZONE', 'Australia/Melbourne'));
        if ($financeArTimezone === '') {
            $financeArTimezone = 'Australia/Melbourne';
        }
        if (!in_array($financeArTimezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('FINANCE_AR_TIMEZONE must be a time zone name such as Australia/Melbourne.');
        }
        $financeApTimezone = trim(self::string($env, 'FINANCE_AP_TIMEZONE', 'Australia/Melbourne'));
        if ($financeApTimezone === '') {
            $financeApTimezone = 'Australia/Melbourne';
        }
        if (!in_array($financeApTimezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('FINANCE_AP_TIMEZONE must be a time zone name such as Australia/Melbourne.');
        }
        $financeApThresholdText = trim(self::string($env, 'FINANCE_AP_CONFIDENCE_THRESHOLD', '0.80'));
        if (!is_numeric($financeApThresholdText) || (float) $financeApThresholdText < 0.0 || (float) $financeApThresholdText > 1.0) {
            throw new InvalidArgumentException('FINANCE_AP_CONFIDENCE_THRESHOLD must be a number from 0 to 1, such as 0.80.');
        }
        // Tri-AP's own mailbox: the default the vendor email, replies and approval mail are sent from (the source's AP_MAILBOX).
        $financeApMailbox = strtolower(trim(self::string($env, 'FINANCE_AP_MAILBOX', 'ap@tricab.com')));
        if ($financeApMailbox === '') {
            $financeApMailbox = 'ap@tricab.com';
        }
        // The origin the link in Tri-AP's approval mail is built from. Empty falls back to the site's public address
        // (PASSKEY_PUBLIC_APP_BASE_URL). An origin only, as FINANCE_AR_PUBLIC_ORIGIN.
        $financeApPublicOrigin = rtrim(trim(self::string($env, 'FINANCE_AP_PUBLIC_ORIGIN', '')), '/');
        if ($financeApPublicOrigin !== '') {
            $apOriginParts = parse_url($financeApPublicOrigin);
            $apOriginScheme = is_array($apOriginParts) ? strtolower((string) ($apOriginParts['scheme'] ?? '')) : '';
            $apOriginHost = is_array($apOriginParts) ? strtolower((string) ($apOriginParts['host'] ?? '')) : '';
            $apOriginLocal = in_array($apOriginHost, ['localhost', '127.0.0.1'], true);
            if (
                !is_array($apOriginParts) || $apOriginHost === '' || ($apOriginScheme !== 'https' && !($apOriginScheme === 'http' && $apOriginLocal))
                || isset($apOriginParts['path']) || isset($apOriginParts['query']) || isset($apOriginParts['fragment']) || isset($apOriginParts['user']) || isset($apOriginParts['pass'])
            ) {
                throw new InvalidArgumentException('FINANCE_AP_PUBLIC_ORIGIN must be an origin such as https://tricab.ai (https, no path).');
            }
        }
        $financeArMailbox = strtolower(trim(self::string($env, 'FINANCE_AR_MAILBOX', 'ar@tricab.com')));
        if ($financeArMailbox === '') {
            $financeArMailbox = 'ar@tricab.com';
        }

        // The origin the credit-note form links are built from (H3, decision D57): no default, because a wrong one mails
        // a rep a dead link. An origin only (scheme, host, port); https, or http for a developer's own machine.
        $financeArPublicOrigin = rtrim(trim(self::string($env, 'FINANCE_AR_PUBLIC_ORIGIN', '')), '/');
        if ($financeArPublicOrigin !== '') {
            $originParts = parse_url($financeArPublicOrigin);
            $originScheme = is_array($originParts) ? strtolower((string) ($originParts['scheme'] ?? '')) : '';
            $originHost = is_array($originParts) ? strtolower((string) ($originParts['host'] ?? '')) : '';
            $originLocal = in_array($originHost, ['localhost', '127.0.0.1'], true);
            if (
                !is_array($originParts) || $originHost === '' || ($originScheme !== 'https' && !($originScheme === 'http' && $originLocal))
                || isset($originParts['path']) || isset($originParts['query']) || isset($originParts['fragment']) || isset($originParts['user']) || isset($originParts['pass'])
            ) {
                throw new InvalidArgumentException('FINANCE_AR_PUBLIC_ORIGIN must be an origin such as https://tricab.ai (https, no path).');
            }
        }

        // Stable product image addresses are absolute so they still work when the client is on another origin. Without
        // an override they are built from the request's own host.
        $publicApiOrigin = rtrim(trim(self::string($env, 'PUBLIC_API_ORIGIN', '')), '/');
        if ($publicApiOrigin !== '') {
            $apiOriginParts = parse_url($publicApiOrigin);
            $apiOriginScheme = is_array($apiOriginParts) ? strtolower((string) ($apiOriginParts['scheme'] ?? '')) : '';
            if (
                !is_array($apiOriginParts) || ($apiOriginParts['host'] ?? '') === '' || !in_array($apiOriginScheme, ['http', 'https'], true)
                || isset($apiOriginParts['path']) || isset($apiOriginParts['query']) || isset($apiOriginParts['fragment']) || isset($apiOriginParts['user']) || isset($apiOriginParts['pass'])
            ) {
                throw new InvalidArgumentException('PUBLIC_API_ORIGIN must be an origin such as https://api.tricab.ai (no path).');
            }
        }

        // Tri-AR's invented sample PDFs, put into the in-memory blob store as the container is built (decision D52). They
        // are for a developer's own machine: refused in production and with any blob store but the memory one, so they can
        // never be written to a shared store.
        $financeArSampleFiles = self::bool($env, 'FINANCE_AR_SAMPLE_FILES', false);
        if ($financeArSampleFiles) {
            $sampleBlobMode = strtolower(self::string($env, 'BLOB_AUTH_MODE', self::BLOB_AUTH_MODE_DISABLED));
            if (
                strtolower(self::string($env, 'APP_ENV', 'development')) === 'production'
                || !in_array($sampleBlobMode, [self::BLOB_AUTH_MODE_MEMORY, self::BLOB_AUTH_MODE_DISABLED], true)
            ) {
                throw new InvalidArgumentException('FINANCE_AR_SAMPLE_FILES is for local development with the in-memory blob store only (APP_ENV not production, BLOB_AUTH_MODE memory or disabled).');
            }
        }

        // Tri-AP's invented sample documents (T6.12), put into the in-memory blob store the same way and refused in the same places.
        $financeApSampleFiles = self::bool($env, 'FINANCE_AP_SAMPLE_FILES', false);
        if ($financeApSampleFiles) {
            $sampleBlobMode = strtolower(self::string($env, 'BLOB_AUTH_MODE', self::BLOB_AUTH_MODE_DISABLED));
            if (
                strtolower(self::string($env, 'APP_ENV', 'development')) === 'production'
                || !in_array($sampleBlobMode, [self::BLOB_AUTH_MODE_MEMORY, self::BLOB_AUTH_MODE_DISABLED], true)
            ) {
                throw new InvalidArgumentException('FINANCE_AP_SAMPLE_FILES is for local development with the in-memory blob store only (APP_ENV not production, BLOB_AUTH_MODE memory or disabled).');
            }
        }

        $productFinderEnabled = self::bool($env, 'PRODUCT_FINDER_ENABLED', false);
        $productFinderBaseUrl = rtrim(self::string($env, 'PRODUCT_FINDER_BASE_URL', 'http://127.0.0.1:8001'), '/');
        $productFinderTimeoutSeconds = self::boundedInt($env, 'PRODUCT_FINDER_TIMEOUT_SECONDS', 20, 1, 120);
        if ($productFinderEnabled) {
            $productFinderHost = parse_url($productFinderBaseUrl, PHP_URL_HOST);
            if (!is_string($productFinderHost)
                || !in_array(strtolower($productFinderHost), self::PRODUCT_FINDER_ALLOWED_HOSTS, true)
            ) {
                throw new InvalidArgumentException(
                    'PRODUCT_FINDER_BASE_URL must point at the loopback interface; '
                    . 'the finder runs inside this App Service and is not exposed.'
                );
            }
        }

        $triexpenseMailFrom = strtolower(trim(self::string($env, 'TRIEXPENSE_MAIL_FROM', 'ap@tricab.com')));
        if ($triexpenseMailFrom === '' || !str_contains($triexpenseMailFrom, '@')) {
            throw new InvalidArgumentException('TRIEXPENSE_MAIL_FROM must be a mailbox address.');
        }
        // Default false in every environment: an accidental deploy must not mail employees.
        $triexpenseEmailEnabled = self::bool($env, 'TRIEXPENSE_EMAIL_ENABLED', false);
        $triexpenseEmailTestRecipient = strtolower(trim(self::string($env, 'TRIEXPENSE_EMAIL_TEST_RECIPIENT', '')));
        if ($triexpenseEmailTestRecipient !== '' && !str_contains($triexpenseEmailTestRecipient, '@')) {
            throw new InvalidArgumentException('TRIEXPENSE_EMAIL_TEST_RECIPIENT must be a mailbox address when set.');
        }
        // Onboarding reminders (5.2). Kept raw: OnboardingReminderDelivery treats
        // anything but exactly "on" as off, so a typo cannot switch them on.
        $onboardingReminderEmails = self::string($env, 'ONBOARDING_REMINDER_EMAILS', '');
        $onboardingEmailTestRecipient = strtolower(trim(self::string($env, 'ONBOARDING_EMAIL_TEST_RECIPIENT', '')));
        if ($onboardingEmailTestRecipient !== '' && !str_contains($onboardingEmailTestRecipient, '@')) {
            throw new InvalidArgumentException('ONBOARDING_EMAIL_TEST_RECIPIENT must be a mailbox address when set.');
        }
        $triexpenseAppBaseUrl = rtrim(trim(self::string($env, 'TRIEXPENSE_APP_BASE_URL', '')), '/');
        if ($triexpenseAppBaseUrl !== '' && !str_starts_with(strtolower($triexpenseAppBaseUrl), 'https://')) {
            if ($appEnv === 'production' || !str_starts_with(strtolower($triexpenseAppBaseUrl), 'http://')) {
                throw new InvalidArgumentException(
                    'TRIEXPENSE_APP_BASE_URL must be an HTTPS URL (plain HTTP is accepted outside production only).',
                );
            }
        }

        $triexpenseOcrEnabled = self::bool($env, 'TRIEXPENSE_OCR_ENABLED', false);
        $triexpenseDocIntelEndpoint = rtrim(trim(self::string($env, 'TRIEXPENSE_DOC_INTEL_ENDPOINT', '')), '/');
        $triexpenseDocIntelKey = trim(self::string($env, 'TRIEXPENSE_DOC_INTEL_KEY', ''));
        $triexpenseOpenAiEndpoint = rtrim(trim(self::string($env, 'TRIEXPENSE_OPENAI_ENDPOINT', '')), '/');
        $triexpenseOpenAiKey = trim(self::string($env, 'TRIEXPENSE_OPENAI_KEY', ''));
        $triexpenseOpenAiDeployment = trim(self::string($env, 'TRIEXPENSE_OPENAI_DEPLOYMENT', 'gpt-4o-mini'));
        $triexpensePermissionMode = strtolower(trim(self::string($env, 'TRIEXPENSE_PERMISSION_MODE', 'hybrid')));
        \TriCab\Core\Expense\Application\ExpensePermissionMode::fromString($triexpensePermissionMode);
        $triexpenseLegacyReadEnabled = self::bool($env, 'TRIEXPENSE_LEGACY_READ_ENABLED', false);
        $quotesOrdersLegacyReadEnabled = self::bool($env, 'QUOTES_ORDERS_LEGACY_READ_ENABLED', false);
        $writeConfig = \TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig::fromEnvironment($env);
        // Fail-closed when write enabled + incomplete credentials/suffix (throws from fromEnvironment).
        foreach ([
            'TRIEXPENSE_DOC_INTEL_ENDPOINT' => $triexpenseDocIntelEndpoint,
            'TRIEXPENSE_OPENAI_ENDPOINT' => $triexpenseOpenAiEndpoint,
        ] as $key => $endpoint) {
            if ($endpoint !== '' && !str_starts_with(strtolower($endpoint), 'https://')) {
                throw new InvalidArgumentException($key . ' must be an HTTPS endpoint.');
            }
        }
        if ($triexpenseOcrEnabled && ($triexpenseDocIntelEndpoint === '' || $triexpenseDocIntelKey === '')) {
            throw new InvalidArgumentException(
                'TRIEXPENSE_DOC_INTEL_ENDPOINT and TRIEXPENSE_DOC_INTEL_KEY are required when TRIEXPENSE_OCR_ENABLED=true.',
            );
        }

        return new self(
            appEnv: $appEnv,
            appName: self::string($env, 'APP_NAME', 'tricab-api'),
            appDebug: self::bool($env, 'APP_DEBUG', false),
            devAuthEnabled: self::bool($env, 'DEV_AUTH_ENABLED', false),
            devAuthDefaultTenant: self::string($env, 'DEV_AUTH_DEFAULT_TENANT', 'development-tenant'),
            devAuthDefaultSubject: self::string($env, 'DEV_AUTH_DEFAULT_SUBJECT', 'development-thilan'),
            devAuthDefaultEmail: self::string($env, 'DEV_AUTH_DEFAULT_EMAIL', 'thilanh@tricab.com'),
            entraTenantId: self::string($env, 'ENTRA_TENANT_ID', ''),
            entraClientId: self::string($env, 'ENTRA_CLIENT_ID', ''),
            entraAudience: self::string($env, 'ENTRA_AUDIENCE', ''),
            entraRequiredScope: self::string($env, 'ENTRA_REQUIRED_SCOPE', 'access_as_user'),
            entraSubjectClaim: $subjectClaim,
            googleClientId: self::string($env, 'GOOGLE_CLIENT_ID', ''),
            googleIosClientId: self::string($env, 'GOOGLE_IOS_CLIENT_ID', ''),
            googleAndroidClientId: self::string($env, 'GOOGLE_ANDROID_CLIENT_ID', ''),
            appleClientId: self::string($env, 'APPLE_CLIENT_ID', ''),
            appleNativeClientId: self::string($env, 'APPLE_NATIVE_CLIENT_ID', 'com.tricab.TriCab'),
            appleTeamId: self::string($env, 'APPLE_TEAM_ID', ''),
            appleKeyId: self::string($env, 'APPLE_KEY_ID', ''),
            applePrivateKey: self::string($env, 'APPLE_PRIVATE_KEY', ''),
            appleRedirectUri: self::string($env, 'APPLE_REDIRECT_URI', ''),
            corsAllowedOrigins: $corsAllowedOrigins,
            userRepository: $userRepository,
            dbAuthMode: $dbAuthMode,
            azureCliBinary: self::string($env, 'AZURE_CLI_BINARY', 'az'),
            dbDriver: self::string($env, 'DB_DRIVER', 'sqlsrv'),
            dbHost: self::string($env, 'DB_HOST', ''),
            dbPort: self::int($env, 'DB_PORT', 1433),
            dbDatabase: self::string($env, 'DB_DATABASE', ''),
            dbUsername: self::string($env, 'DB_USERNAME', ''),
            dbPassword: self::string($env, 'DB_PASSWORD', ''),
            dbEncrypt: self::bool($env, 'DB_ENCRYPT', true),
            dbTrustServerCertificate: self::bool($env, 'DB_TRUST_SERVER_CERTIFICATE', false),
            guestEmailProvider: $guestEmailProvider,
            guestOtpDevReveal: $guestOtpDevReveal,
            postmarkServerToken: $postmarkServerToken,
            postmarkFromEmail: $postmarkFromEmail,
            postmarkFromName: $postmarkFromName,
            postmarkMessageStream: $postmarkMessageStream,
            graphTenantId: $graphTenantId,
            graphClientId: $graphClientId,
            graphClientSecret: $graphClientSecret,
            rfqMailbox: $rfqMailbox,
            guestOtpEmailLogoUrl: $guestOtpEmailLogoUrl,
            guestJwtIssuer: $guestJwtIssuer,
            guestJwtAudience: $guestJwtAudience,
            guestJwtSecret: $guestJwtSecret,
            guestJwtTtlSeconds: $guestJwtTtlSeconds,
            guestJwtClockSkewSeconds: $guestJwtClockSkewSeconds,
            trustedDeviceTtlDays: $trustedDeviceTtlDays,
            blobAuthMode: $blobAuthMode,
            blobConnectionString: $blobConnectionString,
            blobAccountName: $blobAccountName,
            blobAccountKey: $blobAccountKey,
            blobEndpoint: $blobEndpoint,
            blobContainerProducts: self::string($env, 'BLOB_CONTAINER_PRODUCTS', 'products'),
            blobContainerTriexpense: self::string($env, 'BLOB_CONTAINER_TRIEXPENSE', 'triexpense'),
            blobContainerCatalogues: self::string($env, 'BLOB_CONTAINER_CATALOGUES', 'catalogues'),
            blobCdnBaseUrl: rtrim(self::string($env, 'BLOB_CDN_BASE_URL', ''), '/'),
            blobScanMode: $blobScanMode,
            blobUploadSasTtlSeconds: $blobUploadSasTtlSeconds,
            blobDownloadSasTtlSeconds: $blobDownloadSasTtlSeconds,
            performanceLogging: self::bool(
                $env,
                'APP_PERFORMANCE_LOGGING',
                $appEnv === 'development',
            ),
            performanceSqlVerbose: self::bool($env, 'APP_PERFORMANCE_SQL_VERBOSE', false),
            appCacheDriver: self::resolveCacheDriver($env),
            appCachePath: self::string($env, 'APP_CACHE_PATH', 'var/cache/app'),
            dbLoginTimeoutSeconds: self::boundedInt($env, 'DB_LOGIN_TIMEOUT_SECONDS', 20, 5, 60),
            dbConnectRetryEnabled: self::bool($env, 'DB_CONNECT_RETRY_ENABLED', true),
            dbConnectRetryMaxAttempts: self::boundedInt($env, 'DB_CONNECT_RETRY_MAX_ATTEMPTS', 2, 1, 2),
            dbConnectRetryDelayMs: self::boundedInt($env, 'DB_CONNECT_RETRY_DELAY_MS', 1500, 200, 5000),
            product3dEnabled: self::bool($env, 'PRODUCT_3D_ENABLED', true),
            product3dProvider: self::resolveProduct3dProvider($env),
            product3dGenerationEnabled: self::bool($env, 'PRODUCT_3D_GENERATION_ENABLED', false),
            foundryEnabled: $foundryEnabled,
            foundryEndpoint: $foundryEndpoint,
            foundryModelDeployment: $foundryModelDeployment,
            foundryAuthMode: $foundryAuthMode,
            foundryApiKey: $foundryApiKey,
            foundryTimeoutSeconds: $foundryTimeoutSeconds,
            foundryMaxToolCalls: $foundryMaxToolCalls,
            foundryTurnDeadlineSeconds: $foundryTurnDeadlineSeconds,
            passkeyEnabled: $passkeyEnabled,
            passkeyRpId: $passkeyRpId,
            passkeyRpName: $passkeyRpName,
            passkeyAllowedOrigins: $passkeyAllowedOrigins,
            passkeyPublicAppBaseUrl: $passkeyPublicAppBaseUrl,
            passkeySetupTokenTtlSeconds: $passkeySetupTokenTtlSeconds,
            passkeyRegistrationChallengeTtlSeconds: $passkeyRegistrationChallengeTtlSeconds,
            passkeyAuthChallengeTtlSeconds: $passkeyAuthChallengeTtlSeconds,
            passkeyFakeCeremony: $passkeyFakeCeremony,
            legacyCostApiEnabled: $legacyCostApiEnabled,
            legacyCostApiUrl: $legacyCostApiUrl,
            legacyCostApiTimeoutSeconds: $legacyCostApiTimeoutSeconds,
            pricingCostSource: $pricingCostSource,
            pricingDefaultTmmktmMargin: $pricingDefaultTmmktmMargin,
            productFinderEnabled: $productFinderEnabled,
            productFinderBaseUrl: $productFinderBaseUrl,
            productFinderTimeoutSeconds: $productFinderTimeoutSeconds,
            customerContactModuleEnabled: $customerContactModuleEnabled,
            customerContactAccountsLogin: strtolower(trim(self::string($env, 'CUSTOMER_CONTACT_ACCOUNTS_LOGIN', ''))),
            customerContactCoordinatorLogin: $customerContactCoordinatorLogin,
            customerContactAccountsMailbox: $customerContactAccountsMailbox,
            customerContactWebBaseUrl: $customerContactWebBaseUrl,
            customerContactMailFrom: $customerContactMailFrom,
            customerContactEmailEnabled: $customerContactEmailEnabled,
            rfqParserEnabled: $rfqParserEnabled,
            rfqParserBaseUrl: $rfqParserBaseUrl,
            rfqParserInternalToken: $rfqParserInternalToken,
            // Plan 3.5: interactive parses may call a model only when this says so;
            // reader jobs are model reads by definition and are not gated by it.
            rfqParserAllowModel: self::bool($env, 'RFQ_PARSER_ALLOW_MODEL', false),
            // Where an upload leaves word for the reader WebJob, so its idle passes
            // never touch (and so never wake) the serverless database. Relative paths
            // are under the app root, which the web app and the WebJob share.
            rfqReaderWakePath: self::string($env, 'RFQ_READER_WAKE_PATH', 'var/rfq-reader'),
            blobContainerRfq: self::string($env, 'BLOB_CONTAINER_RFQ', 'rfq-documents'),
            rfqDefaultPayTermsCode: $rfqDefaultPayTermsCode,
            // Sending a quote to 4js is an external production write. Only the exact text `true` turns it on:
            // not `1`, `yes`, `on` or `TRUE`, which `bool()` would accept. Owner decision, parity plan E7.3.
            rfqSyncEnabled: ($env['RFQ_SYNC_ENABLED'] ?? '') === 'true',
            triexpenseMailFrom: $triexpenseMailFrom,
            triexpenseEmailEnabled: $triexpenseEmailEnabled,
            triexpenseEmailTestRecipient: $triexpenseEmailTestRecipient,
            onboardingReminderEmails: $onboardingReminderEmails,
            onboardingEmailTestRecipient: $onboardingEmailTestRecipient,
            blobContainerOnboarding: self::string($env, 'BLOB_CONTAINER_ONBOARDING', 'onboarding'),
            blobContainerFinance: self::string($env, 'BLOB_CONTAINER_FINANCE', 'finance-attachments'),
            triexpenseAppBaseUrl: $triexpenseAppBaseUrl,
            triexpenseGraphConnectTimeoutSeconds: self::boundedInt($env, 'TRIEXPENSE_GRAPH_CONNECT_TIMEOUT_SECONDS', 5, 1, 30),
            triexpenseGraphRequestTimeoutSeconds: self::boundedInt($env, 'TRIEXPENSE_GRAPH_REQUEST_TIMEOUT_SECONDS', 15, 1, 60),
            triexpenseOcrEnabled: $triexpenseOcrEnabled,
            triexpenseDocIntelEndpoint: $triexpenseDocIntelEndpoint,
            triexpenseDocIntelKey: $triexpenseDocIntelKey,
            triexpenseOpenAiEndpoint: $triexpenseOpenAiEndpoint,
            triexpenseOpenAiKey: $triexpenseOpenAiKey,
            triexpenseOpenAiDeployment: $triexpenseOpenAiDeployment,
            triexpenseOcrConnectTimeoutSeconds: self::boundedInt($env, 'TRIEXPENSE_OCR_CONNECT_TIMEOUT_SECONDS', 5, 1, 30),
            triexpenseOcrRequestTimeoutSeconds: self::boundedInt($env, 'TRIEXPENSE_OCR_REQUEST_TIMEOUT_SECONDS', 30, 1, 120),
            triexpensePermissionMode: $triexpensePermissionMode,
            triexpenseLegacyReadEnabled: $triexpenseLegacyReadEnabled,
            quotesOrdersLegacyReadEnabled: $quotesOrdersLegacyReadEnabled,
            quotesOrdersLegacyWriteEnabled: $writeConfig->enabled,
            quotesOrdersLegacyWriteDsn: $writeConfig->dsn,
            quotesOrdersLegacyWriteUser: $writeConfig->user,
            quotesOrdersLegacyWritePassword: $writeConfig->password,
            quotesOrdersLegacyWriteSslMode: $writeConfig->sslMode,
            quotesOrdersLegacyWriteTableSuffix: $writeConfig->tableSuffix,
            quotesOrdersLegacyWriteTableSuffixConfigured: $writeConfig->tableSuffixConfigured,
            customerContactE2eMode: $customerContactE2eMode,
            financeModuleEnabled: self::bool($env, 'FINANCE_MODULE_ENABLED', false),
            financeTaskEnabled: self::bool($env, 'FINANCE_TASK_ENABLED', false),
            financeCreditEnabled: self::bool($env, 'FINANCE_CREDIT_ENABLED', false),
            financeCollectEnabled: self::bool($env, 'FINANCE_COLLECT_ENABLED', false),
            financeArEnabled: self::bool($env, 'FINANCE_AR_ENABLED', false),
            financeApEnabled: self::bool($env, 'FINANCE_AP_ENABLED', false),
            financeMailEnabled: self::bool($env, 'FINANCE_MAIL_ENABLED', false),
            financeMailTestRecipient: trim(self::string($env, 'FINANCE_MAIL_TEST_RECIPIENT', '')),
            financeGraphTenantId: trim(self::string($env, 'FINANCE_GRAPH_TENANT_ID', '')),
            financeGraphClientId: trim(self::string($env, 'FINANCE_GRAPH_CLIENT_ID', '')),
            financeGraphClientSecret: trim(self::string($env, 'FINANCE_GRAPH_CLIENT_SECRET', '')),
            financeMailboxReadEnabled: self::bool($env, 'FINANCE_MAILBOX_READ_ENABLED', false),
            odooUrl: trim(self::string($env, 'ODOO_URL', '')),
            odooApiKey: trim(self::string($env, 'ODOO_API_KEY', '')),
            odooCompanyIds: trim(self::string($env, 'ODOO_COMPANY_IDS', '')),
            odooApiKeyExpires: trim(self::string($env, 'ODOO_API_KEY_EXPIRES', '')),
            financeAiEnabled: $financeAiEnabled,
            financeAiBaseUrl: $financeAiBaseUrl,
            financeAiInternalToken: trim(self::string($env, 'FINANCE_AI_INTERNAL_TOKEN', '')),
            financeTaskTimezone: $financeTaskTimezone,
            financeTaskMailFrom: strtolower(trim(self::string($env, 'FINANCE_TASK_MAIL_FROM', ''))),
            financeTaskPreparerDigestEnabled: self::bool($env, 'FINANCE_TASK_PREPARER_DIGEST_ENABLED', false),
            financeTaskSendAsUser: self::bool($env, 'FINANCE_TASK_SEND_AS_USER', true),
            financeArMailbox: $financeArMailbox,
            financeArCreditNotesUrl: rtrim(trim(self::string($env, 'FINANCE_AR_CREDIT_NOTES_URL', '')), '/'),
            financeArTimezone: $financeArTimezone,
            financeArSendingEnabled: self::bool($env, 'FINANCE_AR_SENDING_ENABLED', false),
            financeArPublicOrigin: $financeArPublicOrigin,
            financeArFormLinkHours: self::boundedInt($env, 'FINANCE_AR_FORM_LINK_HOURS', 72, 1, 720),
            financeArSampleFiles: $financeArSampleFiles,
            financeApTimezone: $financeApTimezone,
            financeApMailbox: $financeApMailbox,
            financeApPublicOrigin: $financeApPublicOrigin,
            financeApSendingEnabled: self::bool($env, 'FINANCE_AP_SENDING_ENABLED', false),
            financeApSuggestReplies: ApAiService::suggestRepliesEnabled($env['FINANCE_AP_SUGGEST_REPLIES'] ?? null),
            financeApConfidenceThreshold: (float) $financeApThresholdText,
            financeApInternalEmailDomains: array_map(
                static fn (string $domain): string => ltrim(strtolower($domain), '@'),
                self::csvList($env, 'FINANCE_AP_INTERNAL_EMAIL_DOMAINS', ''),
            ),
            financeApMinAttachmentBytes: self::boundedInt($env, 'FINANCE_AP_MIN_ATTACHMENT_BYTES', 25600, 0, 33554432),
            financeApMaxScanMessages: self::boundedInt($env, 'FINANCE_AP_MAX_SCAN_MESSAGES', 0, 0, 100000),
            financeApSampleFiles: $financeApSampleFiles,
            foundryEffort: $foundryEffort,
            mailModuleEnabled: self::bool($env, 'MAIL_MODULE_ENABLED', false),
            rfqMailGraphTenantId: $rfqMailGraph[0],
            rfqMailGraphClientId: $rfqMailGraph[1],
            rfqMailGraphClientSecret: $rfqMailGraph[2],
            rfqMailReadEnabled: self::bool($env, 'RFQ_MAIL_READ_ENABLED', false),
            rfqMailMode: $rfqMailMode,
            rfqMailMailbox: $rfqMailMailbox,
            rfqMailFirstRunDays: self::boundedInt($env, 'RFQ_MAIL_FIRST_RUN_DAYS', 2, 1, 30),
            rfqMailStatePath: $rfqMailStatePath,
            rfqMailPassSeconds: self::boundedInt($env, 'RFQ_MAIL_PASS_SECONDS', 100, 10, 600),
            rfqMailHeartbeatMinutes: self::boundedInt($env, 'RFQ_MAIL_HEARTBEAT_MINUTES', 360, 5, 1440),
            rfqMailClassifierModel: $rfqMailClassifierModel,
            rfqMailClassifierTimeoutSeconds: self::boundedInt($env, 'RFQ_MAIL_CLASSIFIER_TIMEOUT_SECONDS', 30, 5, 120),
            rfqMailOwnAddresses: array_values(array_filter(array_map(
                static fn (string $a): string => strtolower(trim($a)),
                self::csvList($env, 'RFQ_MAIL_OWN_ADDRESSES', ''),
            ), static fn (string $a): bool => $a !== '')),
            mediaUrlSigningSecret: trim(self::string($env, 'MEDIA_URL_SIGNING_SECRET', '')),
            publicApiOrigin: $publicApiOrigin,
            nprScreeningSignatoryEmails: array_values(array_filter(array_map(
                static fn (string $a): string => strtolower(trim($a)),
                self::csvList($env, 'NPR_SCREENING_SIGNATORY_EMAILS', ''),
            ), static fn (string $a): bool => $a !== '')),
            nprAttachmentsContainer: self::string($env, 'NPR_ATTACHMENTS_CONTAINER', 'npr-attachments'),
            nprMailMode: self::nprMailMode($env),
            nprMailTestRecipient: strtolower(trim(self::string($env, 'NPR_MAIL_TEST_RECIPIENT', ''))),
            nprMailSender: strtolower(trim(self::string($env, 'NPR_MAIL_SENDER', ''))),
            nprDeputyCeoEmail: strtolower(trim(self::string($env, 'NPR_DEPUTY_CEO_EMAIL', ''))),
            nprTsdQueueEmail: strtolower(trim(self::string($env, 'NPR_TSD_QUEUE_EMAIL', ''))),
            nprAppBaseUrl: rtrim(self::string($env, 'NPR_APP_BASE_URL', 'https://tricab.ai'), '/'),
            nprMailWakePath: self::string($env, 'NPR_MAIL_WAKE_PATH', 'var/npr-mail'),
            cablefitResearchEnabled: self::bool($env, 'CABLEFIT_RESEARCH_ENABLED', false),
            cablefitResearchModel: trim(self::string($env, 'CABLEFIT_RESEARCH_MODEL', 'claude-sonnet-5-5')) ?: 'claude-sonnet-5-5',
            cablefitResearchMaxSearches: self::boundedInt($env, 'CABLEFIT_RESEARCH_MAX_SEARCHES', 8, 1, 20),
            cablefitResearchDailyRunsPerUser: self::boundedInt($env, 'CABLEFIT_RESEARCH_DAILY_RUNS_PER_USER', 10, 1, 100),
            cablefitResearchTimeoutSeconds: self::boundedInt($env, 'CABLEFIT_RESEARCH_TIMEOUT_SECONDS', 300, 30, 900),
            cablefitResearchIdleCheckMinutes: self::boundedInt($env, 'CABLEFIT_RESEARCH_IDLE_CHECK_MINUTES', 1440, 1, 10080),
            cablefitResearchWakePath: trim(self::string($env, 'CABLEFIT_RESEARCH_WAKE_PATH', 'var/cablefit-research')) ?: 'var/cablefit-research',
        );
    }

    /** @param array<string, string|null> $env */
    private static function nprMailMode(array $env): string
    {
        $mode = strtolower(self::string($env, 'NPR_MAIL_MODE', 'off'));
        if (!in_array($mode, ['off', 'test', 'live'], true)) {
            throw new \InvalidArgumentException('Unsupported NPR_MAIL_MODE. Supported values: off, test, live.');
        }

        return $mode;
    }

    public function legacyQuoteWriteConfig(): \TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig
    {
        return new \TriCab\Core\QuoteOrder\Live\LegacyQuoteWriteConfig(
            enabled: $this->quotesOrdersLegacyWriteEnabled,
            dsn: $this->quotesOrdersLegacyWriteDsn,
            user: $this->quotesOrdersLegacyWriteUser,
            password: $this->quotesOrdersLegacyWritePassword,
            sslMode: $this->quotesOrdersLegacyWriteSslMode,
            tableSuffix: $this->quotesOrdersLegacyWriteTableSuffix,
            tableSuffixConfigured: $this->quotesOrdersLegacyWriteTableSuffixConfigured,
        );
    }

    private static function assertPricingCostSource(string $mode): void
    {
        $allowed = [
            self::PRICING_COST_SOURCE_LEGACY_COST_API,
            self::PRICING_COST_SOURCE_LEGACY_FALLBACK,
            self::PRICING_COST_SOURCE_AZURE_FIRST,
        ];
        if (!in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException(
                'PRICING_COST_SOURCE must be one of: legacy-cost-api, legacy-fallback, azure-first.',
            );
        }
    }

    private static function assertPricingDefaultTmmktmMargin(string $margin): void
    {
        if ($margin === '') {
            return;
        }
        if (!is_numeric($margin) || bccomp(bcsub('100', $margin, 12), '0', 12) <= 0) {
            throw new InvalidArgumentException(
                'PRICING_DEFAULT_TMMKTM_MARGIN must be numeric and less than 100, or empty to disable.',
            );
        }
    }

    /**
     * @param list<string> $allowedOrigins
     */
    private static function assertPasskeyRpConfiguration(
        string $appEnv,
        string $rpId,
        array $allowedOrigins,
        string $publicAppBaseUrl,
    ): void {
        if ($rpId === '') {
            throw new InvalidArgumentException('PASSKEY_RP_ID is required when PASSKEY_ENABLED=true.');
        }

        if ($allowedOrigins === []) {
            throw new InvalidArgumentException('PASSKEY_ALLOWED_ORIGINS is required when PASSKEY_ENABLED=true.');
        }

        if ($publicAppBaseUrl === '') {
            throw new InvalidArgumentException('PASSKEY_PUBLIC_APP_BASE_URL is required when PASSKEY_ENABLED=true.');
        }

        if ($appEnv !== 'production') {
            return;
        }

        if ($rpId !== 'tricab.com') {
            throw new InvalidArgumentException(
                'PASSKEY_RP_ID must be the production registrable domain (tricab.com), not localhost, an Azure hostname, a deployment slot, the request Host header, or an arbitrary subdomain.',
            );
        }

        $normalizedOrigins = [];
        foreach ($allowedOrigins as $origin) {
            $normalizedOrigins[] = rtrim(strtolower(trim($origin)), '/');
        }
        $uniqueOrigins = array_values(array_unique($normalizedOrigins));
        sort($uniqueOrigins);
        $expectedOrigins = ['https://tricab.com', 'https://www.tricab.com'];
        sort($expectedOrigins);
        if ($uniqueOrigins !== $expectedOrigins) {
            throw new InvalidArgumentException(
                'PASSKEY_ALLOWED_ORIGINS in production must be exactly https://www.tricab.com and https://tricab.com.',
            );
        }

        $appNormalized = rtrim(strtolower(trim($publicAppBaseUrl)), '/');
        if ($appNormalized !== 'https://www.tricab.com' && $appNormalized !== 'https://tricab.com') {
            throw new InvalidArgumentException(
                'PASSKEY_PUBLIC_APP_BASE_URL in production must be https://www.tricab.com or https://tricab.com.',
            );
        }
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function resolveProduct3dProvider(array $env): string
    {
        $provider = strtolower(trim(self::string($env, 'PRODUCT_3D_PROVIDER', 'disabled')));
        $allowed = ['disabled', 'procedural-cable', 'img2threejs'];
        if (!in_array($provider, $allowed, true)) {
            return 'disabled';
        }

        return $provider;
    }

    /**
     * @return array{accountName: string, accountKey: string, blobEndpoint: string}
     */
    private static function parseBlobConnectionString(string $connectionString): array
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $segment) {
            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $segment, 2);
            $parts[strtolower(trim($key))] = trim($value);
        }

        $accountName = $parts['accountname'] ?? '';
        $accountKey = $parts['accountkey'] ?? '';
        $blobEndpoint = $parts['blobendpoint'] ?? '';
        if ($blobEndpoint === '' && $accountName !== '') {
            $protocol = $parts['defaultendpointsprotocol'] ?? 'https';
            $blobEndpoint = $protocol . '://' . $accountName . '.blob.core.windows.net';
        }

        return [
            'accountName' => $accountName,
            'accountKey' => $accountKey,
            'blobEndpoint' => rtrim($blobEndpoint, '/'),
        ];
    }

    private static function isTrustedFoundryEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return $scheme === 'https'
            && str_ends_with($host, '.services.ai.azure.com')
            && strlen($host) > strlen('.services.ai.azure.com')
            && ($path === '' || $path === '/')
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function resolveCacheDriver(array $env): string
    {
        $raw = strtolower(self::string($env, 'APP_CACHE_DRIVER', ''));
        if ($raw === '' || $raw === 'auto') {
            return ApcuApplicationCache::isUsable()
                ? self::CACHE_DRIVER_APCU
                : self::CACHE_DRIVER_FILE;
        }

        if (
            $raw !== self::CACHE_DRIVER_APCU
            && $raw !== self::CACHE_DRIVER_ARRAY
            && $raw !== self::CACHE_DRIVER_FILE
        ) {
            throw new InvalidArgumentException(
                'Unsupported APP_CACHE_DRIVER "' . $raw . '". Use apcu, file, or array.',
            );
        }

        if ($raw === self::CACHE_DRIVER_APCU && !ApcuApplicationCache::isUsable()) {
            throw new InvalidArgumentException(
                'APP_CACHE_DRIVER=apcu requires the APCu extension. For php -S / CLI, start PHP with -d apc.enable_cli=1 (do not assume static PHP properties survive HTTP requests).',
            );
        }

        return $raw;
    }

    /**
     * @param array<string, string|null> $env
     * @return list<string>
     */
    private static function csvList(array $env, string $key, string $default): array
    {
        $raw = self::string($env, $key, $default);
        $parts = array_map('trim', explode(',', $raw));
        $filtered = array_values(array_filter($parts, static fn (string $value): bool => $value !== ''));

        return $filtered;
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function string(array $env, string $key, string $default): string
    {
        $value = $env[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function bool(array $env, string $key, bool $default): bool
    {
        $value = $env[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function int(array $env, string $key, int $default): int
    {
        $value = $env[$key] ?? null;

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @param array<string, string|null> $env
     */
    private static function boundedInt(array $env, string $key, int $default, int $min, int $max): int
    {
        $value = self::int($env, $key, $default);
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }
}
