<?php
declare(strict_types=1);
/**
 * Stage 3 Tri Expense DRY-RUN ONLY.
 * - Profile mapping classification
 * - Receipt migration classification
 * ZERO legacy writes. ZERO receipt inserts. ZERO V2 user activation.
 * Does NOT apply ExpenseProfiles (use a separate operator-approved command later).
 */
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Application\Service\ExpenseMigrationDryRunClassifier;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Application\Exception\LegacyDatabaseWriteAttemptException;
require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();
$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') { fwrite(STDERR, "PRODUCTION_BLOCKED\n"); exit(4); }
function env_file(string $path): array {
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, " \t\"'");
    }
    return $out;
}
$env = env_file($basePath . '/.env');
$container = ContainerFactory::create($settings);
/** @var SqlConnection $azure */
$azure = $container->get(SqlConnection::class);
$safety = $azure->fetchAll('SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName, SUSER_SNAME() AS userName');
echo "APP_ENV={$settings->appEnv}\nAZURE_SERVER={$safety[0]['serverName']}\nAZURE_DB={$safety[0]['dbName']}\nAZURE_USER={$safety[0]['userName']}\n";
echo "LEGACY_HOST={$env['LEGACY_TC_HOST']}\nLEGACY_DB={$env['LEGACY_TC_DATABASE']}\nLEGACY_USER={$env['LEGACY_TC_USER']}\nMODE=DRY_RUN\n";
if (!str_contains(strtolower((string)$safety[0]['serverName']), 'tricab-mel') || strtolower((string)$safety[0]['dbName']) !== 'tc') { fwrite(STDERR, "STOP\n"); exit(4); }
$guard = new LegacyReadOnlySqlGuard();
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['LEGACY_TC_HOST'], $env['LEGACY_TC_PORT'] ?? '3306', $env['LEGACY_TC_DATABASE']);
$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => true];
if (defined('Pdo\\Mysql::ATTR_SSL_CA')) { $opt[\Pdo\Mysql::ATTR_SSL_CA] = ''; if (defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) $opt[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false; }
$my = new PDO($dsn, $env['LEGACY_TC_USER'], $env['LEGACY_TC_PASSWORD'], $opt);
$q = function (string $sql) use ($my, $guard): array { $guard->assertReadOnly($sql); return $my->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
try { $guard->assertReadOnly('UPDATE tc.triexpense_users SET is_active=1'); fwrite(STDERR, "GUARD_FAILED\n"); exit(5); }
catch (LegacyDatabaseWriteAttemptException) { echo "LEGACY_WRITE_GUARD=ok\n"; }
$teUsers = $q('SELECT id, LOWER(TRIM(user_login)) login_norm, LOWER(TRIM(user_email)) email_norm, user_login, user_email, is_active, is_admin, is_finance, LOWER(TRIM(COALESCE(manager_login,\'\'))) manager_login FROM tc.triexpense_users ORDER BY id');
$v2 = $azure->fetchAll("SELECT u.UserId, LOWER(LTRIM(RTRIM(u.NormalizedEmailAddress))) AS email_norm, s.StatusCode FROM [identity].[Users] u JOIN [identity].[UserStatuses] s ON s.UserStatusId=u.UserStatusId");
$byEmail = [];
foreach ($v2 as $row) { $e=(string)$row['email_norm']; if ($e!=='') $byEmail[$e][]=$row; }
$loginToEmail = [];
foreach ($teUsers as $u) { $loginToEmail[$u['login_norm']] = $u['email_norm']; }
$profileCounts = ['READY_ACTIVE'=>0,'V2_PENDING'=>0,'V2_UNMATCHED'=>0,'NON_TRICAB_EMAIL_REVIEW'=>0,'MANAGER_REVIEW'=>0,'OTHER_REVIEW'=>0,'INACTIVE_LEGACY'=>0];
$mappings=[]; $managerReview=0;
foreach ($teUsers as $u) {
    $email=$u['email_norm']; $domain=str_contains($email,'@')?explode('@',$email,2)[1]:'';
    $candidates=$byEmail[$email]??[]; $class='V2_UNMATCHED'; $v2UserId=null; $v2Status=null;
    if (count($candidates)===1) {
        $v2UserId=(int)$candidates[0]['UserId']; $v2Status=(string)$candidates[0]['StatusCode'];
        if ((int)$u['is_active']!==1) $class='INACTIVE_LEGACY';
        elseif ($domain!=='' && $domain!=='tricab.com') $class='NON_TRICAB_EMAIL_REVIEW';
        elseif ($v2Status==='active') $class='READY_ACTIVE';
        elseif ($v2Status==='pending') $class='V2_PENDING';
        else $class='OTHER_REVIEW';
    } elseif (count($candidates)>1) $class='OTHER_REVIEW';
    $mgrLogin=$u['manager_login']; $mgrUserId=null; $mgrOk=true;
    if ($mgrLogin!=='') {
        $mgrEmail=$loginToEmail[$mgrLogin]??(str_contains($mgrLogin,'@')?$mgrLogin:$mgrLogin.'@tricab.com');
        $mgrCandidates=$byEmail[strtolower($mgrEmail)]??[];
        if (count($mgrCandidates)===1) $mgrUserId=(int)$mgrCandidates[0]['UserId'];
        else { $mgrOk=false; $managerReview++; }
    }
    $profileCounts[$class]++;
    $mappings[]=['legacy_id'=>(int)$u['id'],'login_masked'=>substr($u['login_norm'],0,2).'***','email_domain'=>$domain,'is_active'=>(int)$u['is_active'],'class'=>$class,'v2_user_id'=>$v2UserId,'v2_status'=>$v2Status,'manager_ok'=>$mgrOk];
}
$profileCounts['MANAGER_REVIEW']=$managerReview;
$activeLoginMgr=[];
foreach ($teUsers as $u) { if ((int)$u['is_active']===1 && $u['manager_login']!=='') $activeLoginMgr[$u['login_norm']]=$u['manager_login']; }
$circular=[];
foreach ($activeLoginMgr as $a=>$b) { if ($a<$b && isset($activeLoginMgr[$b]) && $activeLoginMgr[$b]===$a) $circular[]=['a'=>substr($a,0,2).'***','b'=>substr($b,0,2).'***']; }
echo 'PROFILE_COUNTS='.json_encode($profileCounts)."\n";
echo 'PROFILE_TOTAL_LEGACY='.count($teUsers)."\n";
echo 'ACTIVE_LEGACY='.count(array_filter($teUsers,fn($u)=>(int)$u['is_active']===1))."\n";
echo 'CIRCULAR_MANAGER_PAIRS='.json_encode($circular)."\n";
$unmatched=array_values(array_map(fn($m)=>['legacy_id'=>$m['legacy_id'],'login_masked'=>$m['login_masked'],'email_domain'=>$m['email_domain']], array_filter($mappings,fn($m)=>$m['class']==='V2_UNMATCHED'&&$m['is_active']===1)));
echo 'UNMATCHED_ACTIVE='.json_encode($unmatched)."\n";
$exact=count(array_filter($mappings,fn($m)=>in_array($m['class'],['READY_ACTIVE','V2_PENDING','NON_TRICAB_EMAIL_REVIEW'],true)));
echo "EXACT_V2_MATCH_ACTIVE_OR_REVIEW={$exact}\n";

$receipts=$q('SELECT id, created_by, submitted_by, manager_login, status, category, file_url, deleted_at IS NOT NULL AS soft_deleted FROM tc.triexpense_receipts');
$classifier=new ExpenseMigrationDryRunClassifier();
$attach=['AZURE_BLOB_READY'=>0,'WORDPRESS_DUAL_READ_REQUIRED'=>0,'MISSING'=>0,'UNKNOWN'=>0];
$classes=['READY'=>0,'OWNER_UNMATCHED'=>0,'MANAGER_UNMATCHED'=>0,'CATEGORY_REVIEW'=>0,'ATTACHMENT_REVIEW'=>0,'OTHER_REVIEW'=>0];
$soft=0;$live=0; $emailToUserId=[];
foreach ($byEmail as $e=>$rows) { if (count($rows)===1) $emailToUserId[$e]=(int)$rows[0]['UserId']; }
$loginToUserId=[];
foreach ($mappings as $m) { if ($m['v2_user_id']) { /* need login */ } }
foreach ($teUsers as $u) {
    $c=$byEmail[$u['email_norm']]??[];
    if (count($c)===1) $loginToUserId[$u['login_norm']]=(int)$c[0]['UserId'];
}
$multi=[]; $freeText=0;
foreach ($receipts as $r) {
    if ((int)$r['soft_deleted']===1) $soft++; else $live++;
    $ownerEmail=strtolower(trim((string)$r['created_by']));
    $ownerMatched=isset($emailToUserId[$ownerEmail]);
    $mgr=strtolower(trim((string)$r['manager_login']));
    $managerMatched=$mgr===''?true:isset($loginToUserId[$mgr]);
    $file=(string)$r['file_url'];
    if ($file==='') $attach['MISSING']++;
    elseif (str_contains($file,'blob.core.windows.net')) $attach['AZURE_BLOB_READY']++;
    elseif (str_contains($file,'/wp-content/')||str_contains($file,'/expense/')) $attach['WORDPRESS_DUAL_READ_REQUIRED']++;
    else $attach['UNKNOWN']++;
    $cat=(string)$r['category'];
    if ($cat!=='' && !preg_match('/^\d{5}$/',$cat)) $freeText++;
    $class=$classifier->classify(['owner_matched'=>$ownerMatched,'manager_matched'=>$managerMatched,'category'=>$cat,'file_url'=>$file])->toString();
    $classes[$class]++;
    $flags=[];
    if(!$ownerMatched)$flags[]='OWNER'; if(!$managerMatched)$flags[]='MANAGER';
    if($cat!==''&&!preg_match('/^\d{5}$/',$cat))$flags[]='CATEGORY';
    if($file===''||str_contains($file,'/wp-content/'))$flags[]='ATTACHMENT';
    if(count($flags)>1)$multi[implode('+', $flags)] = ($multi[implode('+',$flags)]??0)+1;
}
echo 'RECEIPT_TOTAL='.count($receipts)."\nRECEIPT_LIVE={$live}\nRECEIPT_SOFT_DELETED={$soft}\n";
echo 'RECEIPT_CLASSES='.json_encode($classes)."\n";
echo 'ATTACHMENT_CLASSES='.json_encode($attach)."\n";
echo "FREE_TEXT_CATEGORY_COUNT={$freeText}\n";
echo 'MULTI_ISSUE_COUNTS='.json_encode($multi)."\n";
echo "NOTE=RECEIPT_CLASSES are primary classifier buckets (first-match order: owner, manager, category, attachment, ready). MULTI_ISSUE_COUNTS show overlapping dimensions.\n";
echo "LEGACY_WRITES=ZERO\nRECEIPT_INSERTS=ZERO\nV2_USER_ACTIVATIONS=ZERO\nDONE\n";
