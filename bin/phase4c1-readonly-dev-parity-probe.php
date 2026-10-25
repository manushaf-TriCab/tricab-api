<?php

declare(strict_types=1);

/**
 * READ-ONLY Phase 4C1 probe: _dev schema parity + validity sample.
 * SELECT / SHOW only. No INSERT/UPDATE/DELETE.
 */

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = $_ENV + $_SERVER;

$host = trim((string) ($env['LEGACY_TC_HOST'] ?? ''));
$db = trim((string) ($env['LEGACY_TC_DATABASE'] ?? ''));
$user = trim((string) ($env['LEGACY_TC_USER'] ?? ''));
$pass = (string) ($env['LEGACY_TC_PASSWORD'] ?? '');
$port = trim((string) ($env['LEGACY_TC_PORT'] ?? '3306'));

if ($host === '' || $db === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "MISSING_RO_CONFIG\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (strtolower((string) ($env['LEGACY_TC_SSL_MODE'] ?? '')) === 'required') {
    $opts[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $opts);
} catch (Throwable $e) {
    fwrite(STDERR, 'CONNECT_FAIL: ' . $e->getMessage() . "\n");
    exit(2);
}

function cols(PDO $pdo, string $table): array
{
    $st = $pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`');
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['Field']] = $r;
    }

    return $out;
}

function meta(PDO $pdo, string $table): array
{
    $st = $pdo->query('SHOW TABLE STATUS LIKE ' . $pdo->quote($table));

    return $st->fetch() ?: [];
}

function indexes(PDO $pdo, string $table): array
{
    $st = $pdo->query('SHOW INDEX FROM `' . $table . '`');
    $keys = [];
    foreach ($st->fetchAll() as $r) {
        $keys[$r['Key_name']][] = $r['Column_name'];
    }

    return $keys;
}

$pairs = [['tqoth', 'tqoth_dev'], ['tqoti', 'tqoti_dev'], ['tqote', 'tqote_dev']];
foreach ($pairs as [$a, $b]) {
    $ca = cols($pdo, $a);
    $cb = cols($pdo, $b);
    $onlyA = array_diff_key($ca, $cb);
    $onlyB = array_diff_key($cb, $ca);
    $diff = [];
    foreach ($ca as $f => $ra) {
        if (!isset($cb[$f])) {
            continue;
        }
        $rb = $cb[$f];
        foreach (['Type', 'Null', 'Key', 'Default', 'Extra', 'Collation'] as $k) {
            $va = (string) ($ra[$k] ?? '');
            $vb = (string) ($rb[$k] ?? '');
            if ($va !== $vb) {
                $diff[$f][$k] = [$va, $vb];
            }
        }
    }
    $ma = meta($pdo, $a);
    $mb = meta($pdo, $b);
    $ia = indexes($pdo, $a);
    $ib = indexes($pdo, $b);
    echo "TABLE_PAIR {$a} vs {$b}\n";
    echo '  cols_prod=' . count($ca) . ' cols_dev=' . count($cb)
        . ' only_prod=' . count($onlyA) . ' only_dev=' . count($onlyB)
        . ' typediff=' . count($diff) . "\n";
    echo '  engine_prod=' . ($ma['Engine'] ?? '?') . ' engine_dev=' . ($mb['Engine'] ?? '?') . "\n";
    echo '  ai_prod=' . ($ma['Auto_increment'] ?? 'null') . ' ai_dev=' . ($mb['Auto_increment'] ?? 'null') . "\n";
    echo '  index_prod=' . count($ia) . ' index_dev=' . count($ib) . "\n";
    if ($onlyA !== []) {
        echo '  ONLY_PROD ' . implode(',', array_keys($onlyA)) . "\n";
    }
    if ($onlyB !== []) {
        echo '  ONLY_DEV ' . implode(',', array_keys($onlyB)) . "\n";
    }
    foreach ($diff as $f => $d) {
        echo '  DIFF ' . $f . ' ' . json_encode($d) . "\n";
    }
}

$rows = $pdo->query(
    'SELECT id, de, dvalid, DATEDIFF(dvalid, de) AS days, st FROM tqoth'
    . ' WHERE dvalid IS NOT NULL AND de IS NOT NULL ORDER BY id DESC LIMIT 40',
)->fetchAll();
$hist = [];
foreach ($rows as $r) {
    $d = (string) $r['days'];
    $hist[$d] = ($hist[$d] ?? 0) + 1;
}
echo 'VALIDITY_SAMPLE_N=' . count($rows) . "\n";
ksort($hist, SORT_NUMERIC);
foreach ($hist as $d => $c) {
    echo "  days_{$d}={$c}\n";
}
foreach (array_slice($rows, 0, 8) as $r) {
    echo '  quote ' . $r['id'] . ' de=' . $r['de'] . ' dvalid=' . $r['dvalid']
        . ' days=' . $r['days'] . ' st=' . $r['st'] . "\n";
}

foreach ($pdo->query(
    'SELECT i.id,i.qot,i.cata,i.catdt,i.col,i.qty,i.pm,i.nprc,i.net,i.cprc,i.cval,i.qotqty,i.ref'
    . ' FROM tqoti i WHERE i.catdt=1615 ORDER BY i.id DESC LIMIT 3',
)->fetchAll() as $r) {
    echo 'CABLE_LINE ' . json_encode($r) . "\n";
}

$devCount = (int) $pdo->query('SELECT COUNT(*) AS c FROM tqoth_dev')->fetch()['c'];
echo "TQOTH_DEV_ROW_COUNT={$devCount}\n";

echo "=== OPERATOR SAMPLES ===\n";
foreach ($pdo->query(
    'SELECT id,sa,sa_op,cus,cus_op,cus_gen,st FROM tqoth'
    . ' WHERE cus_gen IS NULL OR cus_gen=0 ORDER BY id DESC LIMIT 3',
)->fetchAll() as $r) {
    echo 'STAFF_HDR ' . json_encode($r) . "\n";
}
foreach ($pdo->query(
    'SELECT id,sa,sa_op,cus,cus_op,cus_gen,st FROM tqoth WHERE cus_gen=1 ORDER BY id DESC LIMIT 3',
)->fetchAll() as $r) {
    echo 'PORTAL_HDR ' . json_encode($r) . "\n";
}
foreach ($pdo->query(
    'SELECT DISTINCT h.sa_op, d.desc_code, d.desc_name FROM tqoth h'
    . ' LEFT JOIN descript d ON d.desc_id=h.sa_op ORDER BY h.id DESC LIMIT 5',
)->fetchAll() as $r) {
    echo 'SA_OP_DESC ' . json_encode($r) . "\n";
}

echo "=== LINE TYPE SAMPLES ===\n";
foreach ($pdo->query(
    'SELECT id,qot,cata,catdt,col,qty,pm,nprc,net,cprc,cval,qotqty,ref FROM tqoti'
    . ' WHERE catdt=1660 ORDER BY id DESC LIMIT 2',
)->fetchAll() as $r) {
    echo 'ACCESSORY ' . json_encode($r) . "\n";
}
foreach ($pdo->query(
    'SELECT qot, COUNT(*) AS c FROM tqoti WHERE catdt=1615 GROUP BY qot HAVING COUNT(*)>=3'
    . ' ORDER BY qot DESC LIMIT 1',
)->fetchAll() as $r) {
    echo 'MULTI_CABLE_QOT ' . json_encode($r) . "\n";
}
foreach ($pdo->query(
    'SELECT e.id,e.qoti,e.pk,e.qty,LEFT(e.comments,40) AS comments,i.cata,i.catdt'
    . ' FROM tqote e JOIN tqoti i ON i.id=e.qoti ORDER BY e.id DESC LIMIT 3',
)->fetchAll() as $r) {
    echo 'EXT ' . json_encode($r) . "\n";
}
foreach ($pdo->query(
    'SELECT id,qot,cata,catdt,nprc,net FROM tqoti WHERE net=0 OR nprc=0 ORDER BY id DESC LIMIT 3',
)->fetchAll() as $r) {
    echo 'TBC_CAND ' . json_encode($r) . "\n";
}

echo "DONE\n";
