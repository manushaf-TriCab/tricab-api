<?php

declare(strict_types=1);

/**
 * Cable calculator staging validation runner (read-only, non-production).
 * Usage: php bin/cable-calculator-staging-validation.php [--api=http://127.0.0.1:8080]
 */

$apiBase = 'http://127.0.0.1:8080';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--api=')) {
        $apiBase = substr($arg, 6);
    }
}

$projectsRoot = dirname(__DIR__, 3);
$fixtureRoot = getenv('TRICAB_TC_WEB_FIXTURES') ?: $projectsRoot . '/tc-web/tests/fixtures/cable-calculator';
$localRatings = $projectsRoot . '/tc-web/data/cable-calculator/runtime/ratings.json';
$localSpecs = $projectsRoot . '/tc-web/data/cable-calculator/runtime/cableSpecs.json';

$report = [
    'startedAt' => gmdate('c'),
    'apiBase' => $apiBase,
    'fixtureRoot' => $fixtureRoot,
    'sections' => [],
];

function section(array &$report, string $name, array $data): void
{
    $report['sections'][$name] = $data;
    echo "\n=== $name ===\n";
    foreach ($data as $k => $v) {
        if (is_array($v) && count($v) > 8) {
            echo "$k: " . json_encode(array_slice($v, 0, 5)) . ' ... (' . count($v) . " items)\n";
        } else {
            echo "$k: " . json_encode($v) . "\n";
        }
    }
}

function postCalculate(string $apiBase, array $body): array
{
    $ch = curl_init($apiBase . '/api/v1/cable-calculator/calculate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_TIMEOUT => 120,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    if ($raw === false) {
        return ['httpStatus' => 0, 'error' => $err, 'body' => null];
    }

    $decoded = json_decode($raw, true);

    return ['httpStatus' => $status, 'raw' => $raw, 'body' => $decoded];
}

function mapLegacyToApiBody(array $input): array
{
    return [
        'loadAmps' => $input['load'],
        'runLengthM' => $input['length'],
        'powerFactor' => $input['pf'],
        'voltDropPercent' => $input['maxvd'],
        'voltage' => $input['voltage'],
        'phaseClass' => $input['phase'],
        'ambientC' => $input['ambient'],
        'maxOperatingTempC' => $input['max_op_temp'],
        'activeConductor' => strtolower((string) ($input['activeConductor'] ?? 'Cu')) === 'al' ? 'aluminium' : 'copper',
        'earthConductor' => strtolower((string) ($input['earthConductor'] ?? 'Cu')) === 'al' ? 'aluminium' : 'copper',
        'selectedCable' => $input['cabletype'] ?? $input['type'] ?? 1,
        'installation' => $input['installation'],
        'cableRuns' => (string) ($input['minruns'] ?? 2),
        'cableSize' => $input['acablesize'] ?? 'N/A',
        'earthResistanceMode' => $input['Erh'] ?? 'auto',
        'mftime' => $input['mftime'] ?? 1,
    ];
}

function bandByTemp(array $bands, string $label): ?array
{
    foreach ($bands as $band) {
        if (($band['temperatureBand'] ?? '') === $label) {
            return $band;
        }
    }

    return null;
}

// --- Environment ---
$appEnv = getenv('APP_ENV') ?: 'unknown';
$dbHost = getenv('DB_HOST') ?: 'unknown';
$dbName = getenv('DB_DATABASE') ?: 'unknown';
$isProduction = $appEnv === 'production';

section($report, 'environment', [
    'APP_ENV' => $appEnv,
    'IS_PRODUCTION' => $isProduction ? 'YES' : 'NO',
    'DB_HOST' => $dbHost,
    'DB_DATABASE' => $dbName,
    'calculatorDataSource' => 'legacy MySQL via tc-web calculate-json.php (not Azure SQL direct)',
    'webTarget' => 'http://127.0.0.1:5173 (local Vite)',
    'apiTarget' => $apiBase,
]);

if ($isProduction) {
    section($report, 'verdict', ['status' => 'BLOCKED_PRODUCTION_TARGET']);
    echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
    exit(2);
}

// --- CLI bridge local check ---
$script = realpath($projectsRoot . '/tc-web/tools/legacy-cable-calculator/calculate-json.php') ?: '';
section($report, 'cliBridgeLocal', [
    'calculateJsonExists' => $script !== '' && is_readable($script),
    'calculateJsonPath' => $script,
    'azureDeployNote' => 'tc-web script path assumes sibling repo layout; separate API/web Azure deploy would need TRICAB_TC_WEB_CALCULATE_JSON',
]);

// --- Local dataset baseline ---
$ratingsData = is_readable($localRatings) ? json_decode((string) file_get_contents($localRatings), true) : null;
$specsData = is_readable($localSpecs) ? json_decode((string) file_get_contents($localSpecs), true) : null;
$ratingRows = is_array($ratingsData) ? (isset($ratingsData['rows']) ? count($ratingsData['rows']) : count($ratingsData)) : 0;
$specRows = is_array($specsData) ? (isset($specsData['rows']) ? count($specsData['rows']) : count($specsData)) : 0;

section($report, 'localDatasetBaseline', [
    'ratingsPath' => $localRatings,
    'ratingsRows' => $ratingRows,
    'ratingsSha256' => is_readable($localRatings) ? hash_file('sha256', $localRatings) : null,
    'cableSpecsPath' => $localSpecs,
    'cableSpecsRows' => $specRows,
    'cableSpecsSha256' => is_readable($localSpecs) ? hash_file('sha256', $localSpecs) : null,
    'label' => 'LOCAL_LEGACY_PARITY_BASELINE',
]);

// --- Screenshot case ---
$screenshotBody = mapLegacyToApiBody([
    'load' => 1,
    'length' => 50,
    'pf' => 0.85,
    'maxvd' => 5,
    'voltage' => 400,
    'phase' => 3,
    'ambient' => 40,
    'max_op_temp' => 90,
    'cabletype' => 1,
    'installation' => 1,
    'minruns' => 1,
    'activeConductor' => 'Cu',
    'earthConductor' => 'Cu',
    'Erh' => 'auto',
    'mftime' => 1,
    'acablesize' => 'N/A',
]);
$screenshotBody['cableRuns'] = '1';

$screenshot = postCalculate($apiBase, $screenshotBody);
$screenshotPass = false;
$screenshotBands = [];
if (($screenshot['httpStatus'] ?? 0) === 200 && is_array($screenshot['body']['data'] ?? null)) {
    $data = $screenshot['body']['data'];
    $bands = $data['bands'] ?? [];
    $b90 = bandByTemp($bands, '90');
    $b110 = bandByTemp($bands, '110');
    $screenshotBands = ['90' => $b90, '110' => $b110];
    $screenshotPass = $b90
        && (float) $b90['activeSizeMm2'] === 6.0
        && (float) $b90['currentRatingA'] === 49.0
        && (float) $b90['voltageDropPercent'] === 0.07
        && $b90['productCodeActive'] === 'XL-PAXN/1C6OR'
        && (float) ($b90['earthSizeMm2'] ?? 0) === 2.5
        && ($b90['productCodeEarth'] ?? '') === 'LP-XXXD/1C2.5EA';
}

section($report, 'screenshotCase', [
    'httpStatus' => $screenshot['httpStatus'] ?? 0,
    'pass' => $screenshotPass,
    'bands' => $screenshotBands,
    'statusLabel' => $screenshot['body']['data']['statusLabel'] ?? null,
    'parityBaseline' => $screenshot['body']['data']['parityBaseline'] ?? null,
    'deprecatedResultMustNotAppear' => '1.5mm² / 3.6A / 0.26% / 1.04V',
]);

// --- 200A anchor ---
$anchorBody = mapLegacyToApiBody([
    'load' => 200,
    'length' => 10,
    'pf' => 0.85,
    'maxvd' => 5,
    'voltage' => 400,
    'phase' => 3,
    'ambient' => 40,
    'max_op_temp' => 110,
    'cabletype' => 1,
    'installation' => 1,
    'minruns' => 2,
    'activeConductor' => 'Cu',
    'earthConductor' => 'Cu',
    'Erh' => 'auto',
    'mftime' => 1,
    'acablesize' => 'N/A',
]);

$anchor = postCalculate($apiBase, $anchorBody);
$anchorPass = false;
$anchorBands = [];
if (($anchor['httpStatus'] ?? 0) === 200 && is_array($anchor['body']['data'] ?? null)) {
    $bands = $anchor['body']['data']['bands'] ?? [];
    $b90 = bandByTemp($bands, '90');
    $b110 = bandByTemp($bands, '110');
    $anchorBands = ['90' => $b90, '110' => $b110];
    $anchorPass = $b90 && $b110
        && (float) $b90['activeSizeMm2'] === 70.0
        && (float) $b90['earthSizeMm2'] === 25.0
        && (float) $b90['currentRatingA'] === 241.0
        && (float) $b90['voltageDropPercent'] === 0.29
        && $b90['productCodeActive'] === 'XL-PAXN/1C70OR'
        && $b110['productCodeActive'] === 'KL-PAXN/1C50OR'
        && (float) $b110['activeSizeMm2'] === 50.0;
}

section($report, 'anchor200A', [
    'httpStatus' => $anchor['httpStatus'] ?? 0,
    'pass' => $anchorPass,
    'bands' => $anchorBands,
]);

// --- Fixture-003 ---
$fixture003Path = $fixtureRoot . '/legacy-case-source-003.json';
$fixture003 = is_readable($fixture003Path) ? json_decode((string) file_get_contents($fixture003Path), true) : null;
$fixture003Input = $fixture003['input'] ?? null;
$fixture003Runtime = null;
$fixture003Resolution = 'UNRESOLVED';
if (is_array($fixture003Input)) {
    $f003Body = mapLegacyToApiBody([
        'load' => $fixture003Input['load'],
        'length' => $fixture003Input['length'],
        'pf' => $fixture003Input['pf'] ?? 0.85,
        'maxvd' => $fixture003Input['maxvd'] ?? 5,
        'voltage' => $fixture003Input['voltage'],
        'phase' => $fixture003Input['phase'],
        'ambient' => $fixture003Input['ambient'] ?? 40,
        'max_op_temp' => $fixture003Input['max_op_temp'],
        'cabletype' => $fixture003Input['cabletype'],
        'installation' => $fixture003Input['installation'],
        'minruns' => $fixture003Input['minruns'],
        'activeConductor' => 'Cu',
        'earthConductor' => 'Cu',
        'Erh' => 'auto',
        'mftime' => 1,
        'acablesize' => 'N/A',
    ]);
    $f003Resp = postCalculate($apiBase, $f003Body);
    if (($f003Resp['httpStatus'] ?? 0) === 200) {
        $bands = $f003Resp['body']['data']['bands'] ?? [];
        $b110 = bandByTemp($bands, '110');
        $fixture003Runtime = $b110;
        $legacy110 = $fixture003['legacyOutput']['result110'] ?? null;
        $sourceExpected = $fixture003['sourceExpected'] ?? null;
        if (is_array($b110) && is_array($legacy110)) {
            $matchesLegacy = (float) $b110['activeSizeMm2'] === (float) $legacy110['xa']
                && (float) $b110['currentRatingA'] === (float) $legacy110['cr'];
            $matchesSource = is_array($sourceExpected)
                && (float) $b110['activeSizeMm2'] !== (float) ($sourceExpected['xa'] ?? 0);
            $fixture003Resolution = $matchesLegacy && $matchesSource ? 'STALE_TEST_ASSERTION' : 'UNRESOLVED';
        }
    }
}

section($report, 'fixture003', [
    'historicalSourceExpected' => $fixture003['sourceExpected'] ?? null,
    'localLegacyOutput' => $fixture003['legacyOutput']['result110'] ?? null,
    'stagingRuntime110' => $fixture003Runtime,
    'resolution' => $fixture003Resolution,
    'metaFailureReason' => $fixture003['meta']['failureReason'] ?? null,
]);

// --- Matrix regression via HTTP ---
$matrixDir = $fixtureRoot . '/matrix';
$files = is_dir($matrixDir) ? glob($matrixDir . '/matrix-*.json') : [];
$regression = ['total' => 0, 'exact' => 0, 'failed' => 0, 'failures' => []];
$latencies = [];

foreach ($files as $file) {
    $fixture = json_decode((string) file_get_contents($file), true);
    if (!is_array($fixture)) {
        continue;
    }
    $input = $fixture['input'] ?? null;
    $legacyOutput = $fixture['legacyOutput'] ?? null;
    if (!is_array($input) || !is_array($legacyOutput)) {
        continue;
    }

    $regression['total']++;
    $body = mapLegacyToApiBody($input);
    $start = microtime(true);
    $resp = postCalculate($apiBase, $body);
    $latencies[] = (microtime(true) - $start) * 1000;

    if (($resp['httpStatus'] ?? 0) !== 200) {
        $regression['failed']++;
        $regression['failures'][] = basename($file) . ': HTTP ' . ($resp['httpStatus'] ?? 0);
        continue;
    }

    $bands = $resp['body']['data']['bands'] ?? [];
    $mismatches = [];
    foreach (['90', '110'] as $label) {
        $legacyBand = $legacyOutput['result' . $label] ?? null;
        if (!is_array($legacyBand)) {
            continue;
        }
        $actual = bandByTemp($bands, $label);
        if ($actual === null) {
            $mismatches[] = "missing band $label";
            continue;
        }
        $fieldMap = [
            'style' => 'style',
            'activeSizeMm2' => 'xa',
            'earthSizeMm2' => 'earth_size',
            'runs' => 'runs',
            'currentRatingA' => 'cr',
            'voltageDropPercent' => 'vd',
            'productCodeActive' => 'productCodeActive',
            'productCodeEarth' => 'productCodeEarth',
        ];
        foreach ($fieldMap as $apiKey => $legacyKey) {
            $exp = $legacyBand[$legacyKey] ?? null;
            $act = $actual[$apiKey] ?? null;
            if ($exp === null && $act === null) {
                continue;
            }
            if (is_numeric($exp) && is_numeric($act) && abs((float) $exp - (float) $act) > 0.0001) {
                $mismatches[] = "$label.$legacyKey exp=$exp act=$act";
            } elseif ($exp !== $act) {
                $mismatches[] = "$label.$legacyKey exp=$exp act=$act";
            }
        }
    }

    if ($mismatches === []) {
        $regression['exact']++;
    } else {
        $regression['failed']++;
        $regression['failures'][] = basename($file) . ': ' . implode('; ', array_slice($mismatches, 0, 3));
    }
}

sort($latencies);
$perf = [
    'sampleCount' => count($latencies),
    'minMs' => $latencies[0] ?? null,
    'medianMs' => $latencies[(int) floor(count($latencies) / 2)] ?? null,
    'p95Ms' => $latencies[(int) floor(count($latencies) * 0.95)] ?? null,
    'maxMs' => $latencies[count($latencies) - 1] ?? null,
];

section($report, 'regression', $regression);
section($report, 'performance', $perf);

// --- Error handling ---
$errors = [];
$e1 = postCalculate($apiBase, ['runLengthM' => 10, 'powerFactor' => 0.85, 'voltDropPercent' => 5]);
$errors['missingLoad'] = $e1['httpStatus'];
$e2 = postCalculate($apiBase, array_merge($screenshotBody, ['loadAmps' => 0]));
$errors['loadZero'] = $e2['httpStatus'];
$e3 = postCalculate($apiBase, array_merge($screenshotBody, ['earthResistanceMode' => '0']));
$errors['erhZero'] = $e3['httpStatus'];
section($report, 'errorHandling', $errors);

// --- Concurrency (5 then 10) ---
function concurrentPosts(string $apiBase, array $body, int $count): array
{
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $ch = curl_init($apiBase . '/api/v1/cable-calculator/calculate');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 120,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    $statuses = [];
    foreach ($handles as $ch) {
        $statuses[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    return $statuses;
}

$conc5 = concurrentPosts($apiBase, $screenshotBody, 5);
$conc10 = concurrentPosts($apiBase, $screenshotBody, 10);
section($report, 'concurrency', [
    'fiveRequests' => $conc5,
    'tenRequests' => $conc10,
    'fiveAll200' => count(array_filter($conc5, fn ($s) => $s === 200)) === 5,
    'tenAll200' => count(array_filter($conc10, fn ($s) => $s === 200)) === 10,
]);

// --- Verdict ---
$verdict = 'STAGING_PARITY_VALIDATED';
if (!$screenshotPass || !$anchorPass) {
    $verdict = 'STAGING_PARITY_PARTIAL';
}
if ($regression['failed'] > 0) {
    $verdict = 'STAGING_PARITY_PARTIAL';
}
if (($screenshot['httpStatus'] ?? 0) === 0) {
    $verdict = 'BLOCKED';
}

section($report, 'verdict', [
    'status' => $verdict,
    'note' => 'Local non-prod validation; no Azure App Service staging slot deployed in this run',
]);

$report['finishedAt'] = gmdate('c');
$outPath = dirname(__DIR__) . '/docs/cable-calculator-staging-validation-report.json';
file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT) . "\n");
echo "\nReport written: $outPath\n";
