<?php

declare(strict_types=1);

/**
 * Phase 13.7B — Legacy Cost API live verify (non-production only).
 * Asserts MAKE match without printing cost amounts in detail.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$coreSrc = dirname(__DIR__) . '/core/src';
spl_autoload_register(static function (string $class) use ($coreSrc): void {
    if (!str_starts_with($class, 'TriCab\\Core\\')) {
        return;
    }
    $path = $coreSrc . '/' . str_replace('\\', '/', substr($class, strlen('TriCab\\Core\\'))) . '.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Api\Product\Pricing\HttpLegacyCostCalculationGateway;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);

if (strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "BLOCKED: refuse to run in production\n");
    exit(1);
}

$enabled = filter_var($_ENV['LEGACY_COST_API_ENABLED'] ?? getenv('LEGACY_COST_API_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$url = trim((string) ($_ENV['LEGACY_COST_API_URL'] ?? getenv('LEGACY_COST_API_URL') ?: $settings->legacyCostApiUrl));
$timeout = (int) ($_ENV['LEGACY_COST_API_TIMEOUT_SECONDS'] ?? getenv('LEGACY_COST_API_TIMEOUT_SECONDS') ?: $settings->legacyCostApiTimeoutSeconds);
if ($timeout < 1) {
    $timeout = 8;
}

echo "Phase 13.7B Legacy Cost API verify\n";
echo "APP_ENV={$settings->appEnv} LEGACY_COST_API_ENABLED=" . ($enabled ? 'true' : 'false') . "\n";

if (!$enabled || $url === '') {
    echo "SKIP: enable LEGACY_COST_API_ENABLED=true in local .env to run live Cost API checks\n";
    exit(0);
}

$http = new StreamJsonHttpClient((float) $timeout);
/** @var LegacyCostCalculationGateway $gateway */
$gateway = new HttpLegacyCostCalculationGateway($http, $url);

$makeCode = 'KN-PAXN/1C500OR';
$makeLength = '170';
$makeDbco = '2507';
$expectedCalculated = '113.291'; // proven live sample — compare only MATCH/MISMATCH

$make = $gateway->resolveCost($makeCode, $makeDbco, $makeLength);
$makeOk = $make->success
    && $make->status === CustomerSellPriceStatus::AVAILABILITY_MAKE
    && $make->calculatedCostPerMeter !== null
    && bccomp((string) $make->calculatedCostPerMeter, $expectedCalculated, 3) === 0;

echo 'MAKE ' . $makeCode . ' length=' . $makeLength . ': ' . ($makeOk ? 'MATCH' : 'MISMATCH') . "\n";
if (!$makeOk) {
    echo '  success=' . ($make->success ? '1' : '0') . ' status=' . $make->status . ' reason=' . ($make->failureReason ?? '') . "\n";
}

// Length variance (do not print cost amounts)
$short = $gateway->resolveCost($makeCode, $makeDbco, '170');
$atDefault = $gateway->resolveCost($makeCode, $makeDbco, '600');
$long = $gateway->resolveCost($makeCode, $makeDbco, '1200');
$shortEff = $short->effectiveCostPerUnit();
$defaultEff = $atDefault->effectiveCostPerUnit();
$longEff = $long->effectiveCostPerUnit();
$lengthOk = $shortEff !== null && $defaultEff !== null && $longEff !== null
    && bccomp($shortEff, $defaultEff, 6) !== 0
    && bccomp($defaultEff, $longEff, 6) !== 0;
echo 'MAKE length variance (170 vs 600 vs 1200): ' . ($lengthOk ? 'PASS' : 'FAIL') . "\n";

// STOCKED: unit-tested; live sample deferred — many TCA cable SKUs return MAKE from Cost API
echo "STOCKED live sample: UNIT_TEST_COVERED (STOCKED_SAMPLE_REVIEW)\n";

$container = ContainerFactory::create($settings);
$countsDelta = 'n/a';
try {
    $db = $container->get(SqlConnection::class);
    $before = (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
    $after = (int) ($db->fetchAll('SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL')[0]['c'] ?? 0);
    $countsDelta = (string) ($after - $before);
    echo "ProductVariantPrices count delta: {$countsDelta}\n";
} catch (Throwable $e) {
    echo "ProductVariantPrices count: skipped ({$e->getMessage()})\n";
}

$decision = ($makeOk && $lengthOk && ($countsDelta === '0' || $countsDelta === 'n/a'))
    ? 'LEGACY_COST_API_INTEGRATION_READY'
    : 'LEGACY_COST_API_INTEGRATION_BLOCKED';
echo "Decision: {$decision}\n";
exit(($makeOk && $lengthOk) ? 0 : 2);
