<?php
declare(strict_types=1);
/**
 * TriExpense integration readiness report.
 *
 * Answers "is this environment wired?" — never "what is the secret?". Every
 * credential is reported as CONFIGURED / NOT_CONFIGURED, never echoed, not even
 * truncated: a prefix of a client secret is still material in a support ticket.
 *
 * Read-only. Sends no mail, performs no OCR, writes nothing.
 *
 * Usage: php bin/tri-expense-integration-health.php [--json] [--probe-container]
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags;
use TriCab\Core\Expense\Contract\ExpenseCaptureAssistPort;
use TriCab\Core\Expense\Contract\ExpenseFinanceCapabilityResolver;
use TriCab\Core\Expense\Contract\ExpenseNotificationPort;
use TriCab\Core\Expense\Module;
use TriCab\Core\Product\Contract\ProductBlobStore;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$argvList = $argv ?? [];
$asJson = in_array('--json', $argvList, true);
$probeContainer = in_array('--probe-container', $argvList, true);

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);

$flag = static fn (bool $value): string => $value ? 'YES' : 'NO';
$configured = static fn (bool $value): string => $value ? 'CONFIGURED' : 'NOT_CONFIGURED';

$containerName = trim($settings->blobContainerTriexpense) !== ''
    ? trim($settings->blobContainerTriexpense)
    : Module::BLOB_CONTAINER;

$containerReachable = 'NOT_PROBED';
if ($probeContainer && $settings->isProductBlobEnabled()) {
    try {
        /** @var ProductBlobStore $blobs */
        $blobs = $container->get(ProductBlobStore::class);
        // HEAD on a path that should not exist: a clean miss still proves the
        // container answered, without touching any receipt.
        $blobs->headObject($containerName, 'phase5-validation/.health-probe-absent');
        $containerReachable = 'REACHABLE';
    } catch (Throwable $e) {
        $containerReachable = 'UNREACHABLE: ' . $e->getMessage();
    }
} elseif ($probeContainer) {
    $containerReachable = 'SKIPPED_BLOB_DISABLED';
}

$notificationPort = $container->get(ExpenseNotificationPort::class);
$capturePort = $container->get(ExpenseCaptureAssistPort::class);
$financeResolver = $container->get(ExpenseFinanceCapabilityResolver::class);
$featureFlags = $container->get(ExpenseFeatureFlags::class);

$schedulers = [
    'monthly-manager-digest' => 'bin/tri-expense-monthly-manager-digest.php',
    'purge-expired-receipts' => 'bin/tri-expense-purge-expired-receipts.php',
    'missing-receipt-reminders (manual)' => 'bin/tri-expense-missing-receipt-reminders.php',
    'validate-blob' => 'bin/tri-expense-validate-blob.php',
];
$schedulerStatus = [];
foreach ($schedulers as $label => $relative) {
    $schedulerStatus[$label] = is_file($basePath . '/' . $relative) ? 'PRESENT' : 'MISSING';
}

$report = [
    'appEnv' => $settings->appEnv,
    'email' => [
        'graphConfigured' => $flag($settings->isTriexpenseGraphConfigured()),
        'graphTenant' => $configured(trim($settings->graphTenantId) !== ''),
        'graphClientId' => $configured(trim($settings->graphClientId) !== ''),
        'graphClientSecret' => $configured(trim($settings->graphClientSecret) !== ''),
        'mailFrom' => $settings->triexpenseMailFrom,
        'emailEnabledFlag' => $flag($settings->triexpenseEmailEnabled),
        'sendingEffective' => $flag($settings->isTriexpenseEmailSendingEnabled()),
        'testRecipientOverride' => $flag($settings->triexpenseEmailTestRecipientOrNull() !== null),
        'appBaseUrl' => $settings->triexpenseAppBaseUrl !== '' ? $settings->triexpenseAppBaseUrl : 'NOT_SET',
        'connectTimeoutSeconds' => $settings->triexpenseGraphConnectTimeoutSeconds,
        'requestTimeoutSeconds' => $settings->triexpenseGraphRequestTimeoutSeconds,
        'boundAdapter' => $notificationPort::class,
    ],
    'blob' => [
        'authMode' => $settings->blobAuthMode,
        'accountConfigured' => $configured(trim($settings->blobAccountName) !== ''),
        'credentialConfigured' => $configured(trim($settings->blobAccountKey) !== ''),
        'container' => $containerName,
        'blobEnabled' => $flag($settings->isProductBlobEnabled()),
        'containerReachable' => $containerReachable,
    ],
    'ocr' => [
        'ocrEnabledFlag' => $flag($settings->triexpenseOcrEnabled),
        'docIntelEndpoint' => $configured($settings->triexpenseDocIntelEndpoint !== ''),
        'docIntelKey' => $configured($settings->triexpenseDocIntelKey !== ''),
        'openAiEndpoint' => $configured($settings->triexpenseOpenAiEndpoint !== ''),
        'openAiKey' => $configured($settings->triexpenseOpenAiKey !== ''),
        'openAiDeployment' => $settings->triexpenseOpenAiDeployment,
        'ocrEffective' => $flag($settings->isTriexpenseOcrConfigured()),
        'portEnabled' => $flag($capturePort->isEnabled()),
        'connectTimeoutSeconds' => $settings->triexpenseOcrConnectTimeoutSeconds,
        'requestTimeoutSeconds' => $settings->triexpenseOcrRequestTimeoutSeconds,
        'boundAdapter' => $capturePort::class,
        'maxBytes' => Module::OCR_MAX_BYTES,
        'maxPdfPages' => Module::OCR_MAX_PDF_PAGES,
    ],
    'financeAuthorization' => [
        'resolver' => $financeResolver::class,
        'decision' => 'ExpenseProfile.IsFinance is authoritative at request time; '
            . 'Graph Tricab-Finance group is diagnostic/sync only.',
    ],
    'clientFeatureFlags' => $featureFlags->toArray(),
    'schedulerCommands' => $schedulerStatus,
];

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

foreach ($report as $section => $values) {
    if (!is_array($values)) {
        echo strtoupper((string) $section) . "={$values}\n";
        continue;
    }
    echo "\n[" . strtoupper((string) $section) . "]\n";
    foreach ($values as $key => $value) {
        echo sprintf("  %-24s %s\n", $key, is_bool($value) ? ($value ? 'YES' : 'NO') : (string) $value);
    }
}

echo "\nSECRETS_PRINTED=0\nMAIL_SENT=0\nOCR_CALLS=0\n";
echo "INTEGRATION_HEALTH_OK\n";
