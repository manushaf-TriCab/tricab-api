<?php

declare(strict_types=1);

/**
 * Read-only legacy variant pricing identity audit.
 * Requires legacy tc DSN + Azure SQL. Does not write to legacy MySQL.
 *
 * Usage:
 *   php bin/legacy-variant-pricing-identity-audit.php [--product=UUID] [--limit=100] [--offset=0]
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Product\Migration\GuardedReadOnlyMysqlConnection;
use TriCab\Api\Product\Migration\MysqlLegacyTcRepository;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Application\Command\AuditLegacyVariantPricingIdentityCommand;
use TriCab\Core\Product\Application\Service\AuditLegacyVariantPricingIdentityService;
use TriCab\Core\Product\Domain\ValueObject\LegacyPricingResolutionStatus;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$legacyConfig = LiveLegacySourceConfig::fromEnvironment($env);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);

$productPublicId = null;
$limit = 100;
$offset = 0;
$priceListPublicId = $env['PHASE2B_AUDIT_PRICE_LIST_PUBLIC_ID'] ?? '';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--product=')) {
        $productPublicId = substr($arg, 10);
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    }
    if (str_starts_with($arg, '--offset=')) {
        $offset = max(0, (int) substr($arg, 9));
    }
    if (str_starts_with($arg, '--price-list=')) {
        $priceListPublicId = substr($arg, 13);
    }
}

if ($productPublicId === null || $productPublicId === '') {
    fwrite(STDERR, "Provide --product=UUID and PHASE2B_AUDIT_PRICE_LIST_PUBLIC_ID or --price-list=UUID\n");
    exit(1);
}
if ($priceListPublicId === '') {
    fwrite(STDERR, "Price list public ID required.\n");
    exit(1);
}

if (!$legacyConfig->tcConfigured()) {
    fwrite(STDERR, "Legacy tc DSN not configured — audit will run without live legacy verification.\n");
}

/** @var AuditLegacyVariantPricingIdentityService $audit */
$audit = $container->get(AuditLegacyVariantPricingIdentityService::class);
$summary = $audit->audit(new AuditLegacyVariantPricingIdentityCommand(
    productPublicId: $productPublicId,
    priceListPublicId: $priceListPublicId,
    offset: $offset,
    limit: $limit,
));

echo json_encode([
    'productPublicId' => $productPublicId,
    'priceListPublicId' => $priceListPublicId,
    'variantsScanned' => $summary->variantsScanned,
    'explicit' => $summary->explicitCount,
    'exact' => $summary->exactCount,
    'mask' => $summary->maskCount,
    'unresolved' => $summary->unresolvedCount,
    'ambiguous' => $summary->ambiguousCount,
    'invalidExplicit' => $summary->invalidExplicitCount,
    'zeroCost' => $summary->zeroCostCount,
    'priceRevisionAmbiguous' => $summary->priceRevisionAmbiguousCount,
    'byDbco' => $summary->byDbco,
    'legacyQueryCount' => $summary->legacyQueryCount,
    'executionSeconds' => $summary->executionSeconds,
], JSON_PRETTY_PRINT) . PHP_EOL;
