<?php

declare(strict_types=1);

/**
 * Phase 4J — execute ONLY the 4 SAFE_EXECUTION_SET actions from
 * docs/technical/phase4i/phase4j-execution-plan.json
 *
 * Usage:
 *   php bin/phase4j-deterministic-remediation.php revalidate
 *   PHASE4J_EXECUTE=true php bin/phase4j-deterministic-remediation.php execute
 *   php bin/phase4j-deterministic-remediation.php idempotency-check
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Technical\Document\TechnicalDocumentLibraryRepository;
use TriCab\Api\Technical\Persistence\AzureSqlTechnicalDocumentLibraryRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$docsRoot = '/Users/thilanh/Documents/Projects/tc-web/docs/technical';
$planPath = $docsRoot . '/phase4i/phase4j-execution-plan.json';
$outDir = $docsRoot . '/phase4j';
@mkdir($outDir, 0775, true);

$command = $argv[1] ?? '';
$settings = Environment::settings($basePath);

if ($settings->appEnv === 'production') {
    fwrite(STDERR, "STOP: APP_ENV=production\n");
    exit(1);
}
if (strtolower((string) $settings->userRepository) !== 'azure-sql') {
    fwrite(STDERR, "STOP: USER_REPOSITORY must be azure-sql\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$identity = $db->fetchAll('SELECT DB_NAME() AS db_name, @@SERVERNAME AS server_name, SUSER_SNAME() AS login_name')[0];
$dbName = strtolower((string) $identity['db_name']);
$server = strtolower((string) $identity['server_name']);
if ($dbName !== 'tc' || !str_contains($server, 'tricab-mel')) {
    fwrite(STDERR, "STOP: target identity not NON_PRODUCTION tricab-mel/tc\n");
    fwrite(STDERR, json_encode($identity, JSON_PRETTY_PRINT) . "\n");
    exit(1);
}

/** @var TechnicalDocumentLibraryRepository $library */
$library = $container->get(TechnicalDocumentLibraryRepository::class);
if (!$library instanceof AzureSqlTechnicalDocumentLibraryRepository) {
    fwrite(STDERR, "STOP: Technical library repository is not Azure SQL adapter\n");
    exit(1);
}

if (!is_file($planPath)) {
    fwrite(STDERR, "STOP: missing allow-list {$planPath}\n");
    exit(1);
}

$planRaw = file_get_contents($planPath);
if ($planRaw === false) {
    fwrite(STDERR, "STOP: cannot read allow-list\n");
    exit(1);
}
$planHash = hash('sha256', $planRaw);
/** @var array<string,mixed> $plan */
$plan = json_decode($planRaw, true, 512, JSON_THROW_ON_ERROR);
$safeSet = $plan['SAFE_EXECUTION_SET'] ?? null;
if (!is_array($safeSet) || count($safeSet) !== 4) {
    fwrite(STDERR, "STOP: SAFE_EXECUTION_SET must contain exactly 4 actions\n");
    exit(1);
}

$reuse = [];
$styleLinks = [];
foreach ($safeSet as $action) {
    if (!is_array($action)) {
        fwrite(STDERR, "STOP: invalid action entry\n");
        exit(1);
    }
    $type = (string) ($action['action'] ?? '');
    if ($type === 'REUSE_EXISTING_DOCUMENT') {
        $reuse[] = $action;
    } elseif ($type === 'ADD_STYLE_LINK') {
        $styleLinks[] = $action;
    } else {
        fwrite(STDERR, "STOP: unexpected action type {$type}\n");
        exit(1);
    }
}
if (count($reuse) !== 2 || count($styleLinks) !== 2) {
    fwrite(STDERR, "STOP: expected REUSE=2 and ADD_STYLE_LINK=2\n");
    exit(1);
}
foreach ($styleLinks as $action) {
    if (($action['productPublicIds'] ?? []) !== [] || count($action['stylePublicIds'] ?? []) !== 1) {
        fwrite(STDERR, "STOP: ADD_STYLE_LINK must have exactly one style and no products\n");
        exit(1);
    }
}
foreach ($reuse as $action) {
    if (($action['stylePublicIds'] ?? []) !== [] || ($action['productPublicIds'] ?? []) !== []) {
        fwrite(STDERR, "STOP: REUSE must not carry style/product links\n");
        exit(1);
    }
}

$baseline = snapshotCounts($db);
$expected = [
    'documents' => 204,
    'categoryLinks' => 205,
    'styleLinks' => 54,
    'productLinks' => 14,
    'productDocuments' => 11,
];
if ($command !== 'idempotency-check') {
    foreach ($expected as $key => $want) {
        if ((int) $baseline[$key] !== $want) {
            fwrite(STDERR, "STOP: baseline mismatch {$key} expected {$want} got {$baseline[$key]}\n");
            exit(1);
        }
    }
}

$pre = [
    'generatedAt' => gmdate('c'),
    'phase' => '4J',
    'environment' => [
        'appEnv' => $settings->appEnv,
        'userRepository' => $settings->userRepository,
        'dbName' => (string) $identity['db_name'],
        'serverName' => (string) $identity['server_name'],
        'loginName' => (string) $identity['login_name'],
    ],
    'baseline' => $baseline,
    'allowListPath' => 'docs/technical/phase4i/phase4j-execution-plan.json',
    'allowListSha256' => $planHash,
    'SAFE_EXECUTION_COUNT' => 4,
    'actions' => [
        'REUSE_EXISTING_DOCUMENT' => array_map(static fn (array $a): array => [
            'legacyId' => $a['legacyId'],
            'canonicalLegacyId' => $a['canonicalLegacyId'],
            'existingTechnicalDocumentPublicId' => $a['existingTechnicalDocumentPublicId'],
            'sha256' => $a['sha256'],
        ], $reuse),
        'ADD_STYLE_LINK' => array_map(static fn (array $a): array => [
            'legacyId' => $a['legacyId'],
            'canonicalLegacyId' => $a['canonicalLegacyId'],
            'existingTechnicalDocumentPublicId' => $a['existingTechnicalDocumentPublicId'],
            'stylePublicIds' => $a['stylePublicIds'],
            'sha256' => $a['sha256'],
        ], $styleLinks),
    ],
    'productDocuments' => (int) $baseline['productDocuments'],
];
file_put_contents($outDir . '/pre-execution-snapshot.json', json_encode($pre, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$reuseResults = [];
foreach ($reuse as $action) {
    $reuseResults[] = revalidateReuse($library, $db, $action);
}

$styleResults = [];
foreach ($styleLinks as $action) {
    $styleResults[] = revalidateStyleLink($library, $db, $action);
}

foreach (array_merge($reuseResults, $styleResults) as $row) {
    if (($row['status'] ?? '') === 'FAIL') {
        fwrite(STDERR, "STOP: revalidation failed for legacy {$row['legacyId']}: {$row['reason']}\n");
        exit(1);
    }
}

echo "REVALIDATION_OK\n";
echo json_encode([
    'environment' => $pre['environment'],
    'baseline' => $baseline,
    'allowListSha256' => $planHash,
    'reuse' => $reuseResults,
    'styleLinks' => $styleResults,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($command === 'revalidate') {
    exit(0);
}

if ($command === 'idempotency-check') {
    $pendingInserts = 0;
    foreach ($styleResults as $row) {
        if (($row['linkState'] ?? '') === 'MISSING') {
            $pendingInserts++;
        }
    }
    $out = [
        'generatedAt' => gmdate('c'),
        'phase' => '4J_IDEMPOTENCY',
        'allowListSha256' => $planHash,
        'reuse' => $reuseResults,
        'styleLinks' => $styleResults,
        'pendingExecutableInserts' => $pendingInserts,
        'pass' => $pendingInserts === 0,
        'counts' => snapshotCounts($db),
    ];
    file_put_contents($outDir . '/idempotency-check.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo ($out['pass'] ? "IDEMPOTENCY_PASS\n" : "IDEMPOTENCY_FAIL\n");
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($out['pass'] ? 0 : 1);
}

if ($command !== 'execute') {
    fwrite(STDERR, "Usage: revalidate | execute | idempotency-check\n");
    exit(1);
}

if (getenv('PHASE4J_EXECUTE') !== 'true') {
    fwrite(STDERR, "STOP: set PHASE4J_EXECUTE=true to perform writes\n");
    exit(1);
}

$createdLinks = [];
$styleExec = [];
foreach ($styleResults as $row) {
    $legacyId = (int) $row['legacyId'];
    $docId = (int) $row['documentId'];
    $styleId = (int) $row['productStyleId'];
    $docPublicId = (string) $row['documentPublicId'];
    $stylePublicId = (string) $row['stylePublicId'];

    $exists = $db->fetchAll(
        'SELECT [DocumentStyleLinkId] AS [Id] FROM [technical].[DocumentStyleLinks] WHERE [DocumentId]=:d AND [ProductStyleId]=:s AND [DeletedAtUtc] IS NULL',
        [':d' => $docId, ':s' => $styleId],
    );
    if ($exists !== []) {
        $styleExec[] = [
            'legacyId' => $legacyId,
            'result' => 'ALREADY_PRESENT',
            'documentPublicId' => $docPublicId,
            'stylePublicId' => $stylePublicId,
            'linkId' => (int) $exists[0]['Id'],
        ];
        continue;
    }

    $db->beginTransaction();
    try {
        $inserted = $db->fetchAll(
            'INSERT INTO [technical].[DocumentStyleLinks] ([DocumentId], [ProductStyleId]) OUTPUT INSERTED.[DocumentStyleLinkId] AS [Id] VALUES (:d, :s)',
            [':d' => $docId, ':s' => $styleId],
        );
        $linkId = (int) $inserted[0]['Id'];
        $db->commit();
        $createdLinks[] = [
            'linkId' => $linkId,
            'documentId' => $docId,
            'documentPublicId' => $docPublicId,
            'productStyleId' => $styleId,
            'stylePublicId' => $stylePublicId,
            'sourceLegacyPeer' => $legacyId,
            'canonicalLegacyId' => $row['canonicalLegacyId'],
            'evidence' => $row['evidence'] ?? [],
        ];
        $styleExec[] = [
            'legacyId' => $legacyId,
            'result' => 'CREATED',
            'documentPublicId' => $docPublicId,
            'stylePublicId' => $stylePublicId,
            'linkId' => $linkId,
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "STOP DURING EXECUTION: style link failed for {$legacyId}: {$e->getMessage()}\n");
        exit(1);
    }
}

$after = snapshotCounts($db);
$delta = [
    'documents' => (int) $after['documents'] - (int) $baseline['documents'],
    'categoryLinks' => (int) $after['categoryLinks'] - (int) $baseline['categoryLinks'],
    'styleLinks' => (int) $after['styleLinks'] - (int) $baseline['styleLinks'],
    'productLinks' => (int) $after['productLinks'] - (int) $baseline['productLinks'],
    'productDocuments' => (int) $after['productDocuments'] - (int) $baseline['productDocuments'],
];

$createdCount = count(array_filter($styleExec, static fn (array $r): bool => $r['result'] === 'CREATED'));
$alreadyCount = count(array_filter($styleExec, static fn (array $r): bool => $r['result'] === 'ALREADY_PRESENT'));

if ($delta['documents'] !== 0
    || $delta['categoryLinks'] !== 0
    || $delta['productLinks'] !== 0
    || $delta['productDocuments'] !== 0
    || $delta['styleLinks'] !== $createdCount
) {
    fwrite(STDERR, "STOP: unexpected DB delta " . json_encode($delta) . " created={$createdCount}\n");
    exit(1);
}

$rollback = [
    'generatedAt' => gmdate('c'),
    'phase' => '4J',
    'createdDocumentStyleLinks' => $createdLinks,
    'blobWrites' => 0,
    'documentsCreated' => 0,
];
file_put_contents($outDir . '/rollback-manifest.json', json_encode($rollback, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$reuseAudit = [
    'generatedAt' => gmdate('c'),
    'phase' => '4J',
    'action' => 'REUSE_EXISTING_DOCUMENT',
    'results' => array_map(static function (array $r): array {
        return [
            'legacyId' => $r['legacyId'],
            'sourceSha256' => $r['expectedSha256'],
            'canonicalTechnicalDocumentPublicId' => $r['documentPublicId'],
            'canonicalLegacyId' => $r['canonicalLegacyId'],
            'canonicalSourceLegacyId' => $r['canonicalSourceLegacyId'],
            'action' => 'REUSE_EXISTING_DOCUMENT',
            'result' => 'RECONCILED',
            'blobWrite' => false,
            'documentWrite' => false,
        ];
    }, $reuseResults),
];
file_put_contents($outDir . '/reuse-reconciliation.json', json_encode($reuseAudit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$post = [
    'generatedAt' => gmdate('c'),
    'phase' => '4J',
    'allowListSha256' => $planHash,
    'REUSE_attempted' => 2,
    'REUSE_successful' => count($reuseResults),
    'ADD_STYLE_attempted' => 2,
    'ADD_STYLE_created' => $createdCount,
    'ADD_STYLE_already_present' => $alreadyCount,
    'ADD_STYLE_failed' => 0,
    'before' => $baseline,
    'after' => $after,
    'delta' => $delta,
    'blobWrites' => 0,
    'productDocumentsDelta' => $delta['productDocuments'],
    'duplicateTechnicalDocumentsCreated' => 0,
    'styleExec' => $styleExec,
    'reuse' => $reuseResults,
    'REVIEW' => [
        'original' => 34,
        'deterministicallyClosed' => 2,
        'unresolved' => 32,
    ],
    'UNKNOWN' => 30,
    'BLOCKED' => 45,
];
file_put_contents($outDir . '/post-execution-reconciliation.json', json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "PHASE4J_EXECUTE_OK\n";
echo json_encode($post, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);

/**
 * @return array{documents:int,categoryLinks:int,styleLinks:int,productLinks:int,productDocuments:int}
 */
function snapshotCounts(SqlConnection $db): array
{
    $row = $db->fetchAll(<<<'SQL'
SELECT
  (SELECT COUNT(*) FROM [technical].[Documents] WHERE [DeletedAtUtc] IS NULL) AS documents,
  (SELECT COUNT(*) FROM [technical].[DocumentCategoryLinks] WHERE [DeletedAtUtc] IS NULL) AS categoryLinks,
  (SELECT COUNT(*) FROM [technical].[DocumentStyleLinks] WHERE [DeletedAtUtc] IS NULL) AS styleLinks,
  (SELECT COUNT(*) FROM [technical].[DocumentProductLinks] WHERE [DeletedAtUtc] IS NULL) AS productLinks,
  (SELECT COUNT(*) FROM [product].[ProductDocuments] WHERE [DeletedAtUtc] IS NULL) AS productDocuments
SQL)[0];

    return [
        'documents' => (int) $row['documents'],
        'categoryLinks' => (int) $row['categoryLinks'],
        'styleLinks' => (int) $row['styleLinks'],
        'productLinks' => (int) $row['productLinks'],
        'productDocuments' => (int) $row['productDocuments'],
    ];
}

/**
 * @param array<string,mixed> $action
 * @return array<string,mixed>
 */
function revalidateReuse(
    AzureSqlTechnicalDocumentLibraryRepository $library,
    SqlConnection $db,
    array $action,
): array {
    $legacyId = (int) $action['legacyId'];
    $expectedSha = strtolower((string) $action['sha256']);
    $expectedPublicId = strtoupper((string) $action['existingTechnicalDocumentPublicId']);
    $canonicalLegacyId = (int) $action['canonicalLegacyId'];

    $byChecksum = $library->findActiveByChecksum($expectedSha);
    if ($byChecksum === null) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'findActiveByChecksum returned null'];
    }
    if (strtoupper($byChecksum->publicId) !== $expectedPublicId) {
        return [
            'legacyId' => $legacyId,
            'status' => 'FAIL',
            'reason' => 'checksum resolved unexpected PublicId ' . $byChecksum->publicId,
        ];
    }
    if (!$byChecksum->isActive) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'document not active'];
    }
    $actualSha = strtolower((string) ($byChecksum->checksumSha256 ?? ''));
    if ($actualSha !== $expectedSha) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'SHA mismatch'];
    }

    $meta = $db->fetchAll(
        'SELECT [DocumentId], [SourceLegacyId], [DeletedAtUtc], [IsActive] FROM [technical].[Documents] WHERE [PublicId]=:p',
        [':p' => $byChecksum->publicId],
    );
    if ($meta === [] || $meta[0]['DeletedAtUtc'] !== null || (int) $meta[0]['IsActive'] !== 1) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'document missing/inactive/deleted'];
    }
    if ((string) $meta[0]['SourceLegacyId'] !== (string) $canonicalLegacyId) {
        return [
            'legacyId' => $legacyId,
            'status' => 'FAIL',
            'reason' => 'canonical SourceLegacyId mismatch got ' . (string) $meta[0]['SourceLegacyId'],
        ];
    }

    return [
        'legacyId' => $legacyId,
        'status' => 'OK',
        'expectedSha256' => $expectedSha,
        'documentPublicId' => $byChecksum->publicId,
        'documentId' => (int) $meta[0]['DocumentId'],
        'canonicalLegacyId' => $canonicalLegacyId,
        'canonicalSourceLegacyId' => (string) $meta[0]['SourceLegacyId'],
        'categories' => $byChecksum->categoryCodes,
    ];
}

/**
 * @param array<string,mixed> $action
 * @return array<string,mixed>
 */
function revalidateStyleLink(
    AzureSqlTechnicalDocumentLibraryRepository $library,
    SqlConnection $db,
    array $action,
): array {
    $legacyId = (int) $action['legacyId'];
    $expectedSha = strtolower((string) $action['sha256']);
    $expectedPublicId = strtoupper((string) $action['existingTechnicalDocumentPublicId']);
    $canonicalLegacyId = (int) $action['canonicalLegacyId'];
    $stylePublicId = strtoupper((string) $action['stylePublicIds'][0]);
    $evidence = $action['evidence'] ?? [];

    $byChecksum = $library->findActiveByChecksum($expectedSha);
    if ($byChecksum === null) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'findActiveByChecksum returned null'];
    }
    if (strtoupper($byChecksum->publicId) !== $expectedPublicId) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'checksum PublicId mismatch'];
    }
    if (strtolower((string) ($byChecksum->checksumSha256 ?? '')) !== $expectedSha) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'SHA mismatch'];
    }

    $docRows = $db->fetchAll(
        'SELECT [DocumentId], [SourceLegacyId], [DeletedAtUtc], [IsActive] FROM [technical].[Documents] WHERE [PublicId]=:p',
        [':p' => $byChecksum->publicId],
    );
    if ($docRows === [] || $docRows[0]['DeletedAtUtc'] !== null || (int) $docRows[0]['IsActive'] !== 1) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'canonical document missing/inactive'];
    }
    if ((string) $docRows[0]['SourceLegacyId'] !== (string) $canonicalLegacyId) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'canonical legacy id mismatch'];
    }
    $documentId = (int) $docRows[0]['DocumentId'];

    $styleRows = $db->fetchAll(
        <<<'SQL'
SELECT [ProductStyleId], [StyleCode], [PublicId], [IsActive], [DeletedAtUtc]
FROM [product].[ProductStyles]
WHERE [PublicId] = :p
SQL,
        [':p' => $stylePublicId],
    );
    if (count($styleRows) !== 1) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'style PublicId did not resolve to exactly one row'];
    }
    if ($styleRows[0]['DeletedAtUtc'] !== null || (int) $styleRows[0]['IsActive'] !== 1) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => 'style inactive/deleted'];
    }
    $styleCode = (string) $styleRows[0]['StyleCode'];
    $byCode = $db->fetchAll(
        'SELECT [PublicId] FROM [product].[ProductStyles] WHERE [StyleCode]=:c AND [DeletedAtUtc] IS NULL AND [IsActive]=1',
        [':c' => $styleCode],
    );
    if (count($byCode) !== 1 || strtoupper((string) $byCode[0]['PublicId']) !== $stylePublicId) {
        return ['legacyId' => $legacyId, 'status' => 'FAIL', 'reason' => "StyleCode {$styleCode} ambiguous or mismatched"];
    }

    $productStyleId = (int) $styleRows[0]['ProductStyleId'];
    $link = $db->fetchAll(
        'SELECT [DocumentStyleLinkId] AS [Id] FROM [technical].[DocumentStyleLinks] WHERE [DocumentId]=:d AND [ProductStyleId]=:s AND [DeletedAtUtc] IS NULL',
        [':d' => $documentId, ':s' => $productStyleId],
    );

    return [
        'legacyId' => $legacyId,
        'status' => 'OK',
        'canonicalLegacyId' => $canonicalLegacyId,
        'documentPublicId' => $byChecksum->publicId,
        'documentId' => $documentId,
        'stylePublicId' => (string) $styleRows[0]['PublicId'],
        'styleCode' => $styleCode,
        'productStyleId' => $productStyleId,
        'expectedSha256' => $expectedSha,
        'linkState' => $link === [] ? 'MISSING' : 'PRESENT',
        'existingLinkId' => $link === [] ? null : (int) $link[0]['Id'],
        'evidence' => $evidence,
    ];
}
