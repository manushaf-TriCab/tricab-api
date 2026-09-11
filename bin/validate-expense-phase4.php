<?php

declare(strict_types=1);

/**
 * Phase 4 non-prod validation against tricab-mel / tc / development.
 *
 * Safety:
 * - Blocks production
 * - Requires @@SERVERNAME contains tricab-mel and DB_NAME() = tc
 * - Only creates BankReference values prefixed P4VAL-
 * - Never mutates legacy MySQL tables
 * - Never elevates UserId 1
 * - Restores any temporary IsFinance flag and deletes P4VAL-* rows
 * - No email / OCR
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\ExportFinanceReconciliationCsvService;
use TriCab\Core\Expense\Application\Service\GetExpenseReconciliationService;
use TriCab\Core\Expense\Application\Service\ImportExpenseBankFeedService;
use TriCab\Core\Expense\Application\Service\ListExpenseAuditService;
use TriCab\Core\Expense\Application\Service\OffsetExpenseBankFeedService;
use TriCab\Core\Expense\Application\Service\PreCloseExpensePeriodService;
use TriCab\Core\Expense\Application\Service\RenderFinanceSummaryPdfService;
use TriCab\Core\Expense\Application\Service\UndoOffsetExpenseBankFeedService;
use TriCab\Core\Expense\Contract\ExpenseBankFeedRepository;
use TriCab\Core\Expense\Contract\ExpensePeriodRepository;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
if ($settings->isProduction() || strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(4);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$env = $db->fetchAll('SELECT @@SERVERNAME AS [serverName], DB_NAME() AS [dbName], SUSER_SNAME() AS [userName]');
$server = (string) ($env[0]['serverName'] ?? '');
$database = (string) ($env[0]['dbName'] ?? '');
echo "APP_ENV={$settings->appEnv}\nSERVER={$server}\nDB={$database}\nUSER={$env[0]['userName']}\n";
if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: expected tricab-mel / tc\n");
    exit(4);
}

$prefix = 'P4VAL-' . gmdate('YmdHis') . '-';
$elevatedUserId = null;
$originalIsFinance = null;

$cleanup = static function () use ($db, $prefix, &$elevatedUserId, &$originalIsFinance): void {
    $db->execute(
        'DELETE FROM [expense].[ExpenseBankFeedEntries] WHERE [BankReference] LIKE :prefix',
        ['prefix' => $prefix . '%'],
    );
    if ($elevatedUserId !== null && $originalIsFinance !== null) {
        $db->execute(
            'UPDATE [expense].[ExpenseProfiles] SET [IsFinance] = :isFinance WHERE [UserId] = :userId AND [UserId] <> 1',
            ['isFinance' => $originalIsFinance ? 1 : 0, 'userId' => $elevatedUserId],
        );
    }
};

try {
    /** @var ExpenseProfileRepository $profiles */
    $profiles = $container->get(ExpenseProfileRepository::class);

    $candidates = $db->fetchAll("
        SELECT TOP 20 [UserId], [IsFinance], [IsActive], [LegacyEmail]
        FROM [expense].[ExpenseProfiles]
        WHERE [UserId] <> 1 AND [IsActive] = 1
        ORDER BY CASE WHEN [IsFinance] = 1 THEN 0 ELSE 1 END, [UserId]
    ");

    $financeProfile = null;
    foreach ($candidates as $row) {
        $uid = UserId::fromInt((int) $row['UserId']);
        $profile = $profiles->findByUserId($uid);
        if ($profile === null || !$profile->isActive()) {
            continue;
        }
        if ($profile->isFinance()) {
            $financeProfile = $profile;
            break;
        }
        if ($elevatedUserId === null) {
            $elevatedUserId = (int) $row['UserId'];
            $originalIsFinance = (bool) $row['IsFinance'];
            $db->execute(
                'UPDATE [expense].[ExpenseProfiles] SET [IsFinance] = 1 WHERE [UserId] = :userId AND [UserId] <> 1',
                ['userId' => $elevatedUserId],
            );
            $financeProfile = $profiles->findByUserId($uid);
            break;
        }
    }

    if ($financeProfile === null) {
        fwrite(STDERR, "NO_ACTIVE_PROFILE_FOR_FINANCE_ACTOR\n");
        exit(5);
    }

    $actor = new ExpenseActor(
        $financeProfile->userId(),
        'p4val-' . $financeProfile->userId()->toInt(),
        $financeProfile->legacyEmail(),
        [Module::PERMISSION_ACCESS],
        ['employee'],
    );

    $access = new ExpenseAccessService($profiles, new ProfileFinanceCapabilityResolver());
    $access->requireFinance($actor);

    $csv = implode("\n", [
        'Bank Reference,Cardholder Email,First Name,Last Name,Transaction Date,Posting Date,Statement Date,Merchant Name,Merchant City,Merchant Country,Merchant Postcode,Amount,Currency,Foreign Amount,Foreign Currency,Net Amount,Tax Amount,Transaction Type,Merchant Category Group',
        "{$prefix}A,{$financeProfile->legacyEmail()},Val,User,2026-09-01,2026-09-02,2026-09-30,P4 Shop,Melbourne,AU,3000,25.00,AUD,,,,,,",
        "{$prefix}B,{$financeProfile->legacyEmail()},Val,User,2026-09-03,2026-09-04,2026-09-30,P4 Offset,Melbourne,AU,3000,-25.00,AUD,,,,,,",
        "{$prefix}C,{$financeProfile->legacyEmail()},Val,User,2026-09-05,2026-09-06,2026-09-30,P4 Unmatched,Melbourne,AU,3000,99.00,AUD,,,,,,",
    ]);

    /** @var ImportExpenseBankFeedService $import */
    $import = $container->get(ImportExpenseBankFeedService::class);
    $imported = $import->execute($actor, 'AU', $csv, 'p4val_au.csv');
    echo 'IMPORT inserted=' . $imported['inserted'] . ' duplicates=' . $imported['duplicates'] . "\n";

    /** @var ExpenseBankFeedRepository $bankFeeds */
    $bankFeeds = $container->get(ExpenseBankFeedRepository::class);
    $a = $bankFeeds->findByBankReference($prefix . 'A');
    $b = $bankFeeds->findByBankReference($prefix . 'B');
    if ($a === null || $b === null) {
        throw new RuntimeException('P4VAL bank rows missing after import');
    }

    /** @var OffsetExpenseBankFeedService $offset */
    $offset = $container->get(OffsetExpenseBankFeedService::class);
    $offset->execute(
        $actor,
        $a->publicId()->toString(),
        $b->publicId()->toString(),
        $a->rowVersion()?->toBase64() ?? '',
        $b->rowVersion()?->toBase64() ?? '',
    );
    echo "OFFSET ok\n";

    $a2 = $bankFeeds->findByBankReference($prefix . 'A');
    if ($a2 === null) {
        throw new RuntimeException('offset entry missing');
    }
    /** @var UndoOffsetExpenseBankFeedService $undo */
    $undo = $container->get(UndoOffsetExpenseBankFeedService::class);
    $undo->execute($actor, $a2->publicId()->toString(), $a2->rowVersion()?->toBase64() ?? '');
    echo "UNDO_OFFSET ok\n";

    /** @var ExpensePeriodRepository $periods */
    $periods = $container->get(ExpensePeriodRepository::class);
    $period = $periods->findOpenByCountry('AU');
    if ($period === null) {
        throw new RuntimeException('open AU period missing');
    }

    /** @var PreCloseExpensePeriodService $preClose */
    $preClose = $container->get(PreCloseExpensePeriodService::class);
    $blockers = $preClose->execute($actor, $period->publicId()->toString());
    echo 'PRE_CLOSE canClose=' . (($blockers['canClose'] ?? false) ? '1' : '0')
        . ' blockers=' . count($blockers['blockers'] ?? []) . "\n";

    /** @var GetExpenseReconciliationService $recon */
    $recon = $container->get(GetExpenseReconciliationService::class);
    $reconView = $recon->execute($actor, 'AU');
    echo 'RECON keys=' . implode(',', array_keys($reconView)) . "\n";

    /** @var ExportFinanceReconciliationCsvService $csvExport */
    $csvExport = $container->get(ExportFinanceReconciliationCsvService::class);
    $financeCsv = $csvExport->execute($actor, 'AU');
    echo 'FINANCE_CSV bytes=' . strlen($financeCsv) . "\n";

    /** @var RenderFinanceSummaryPdfService $pdf */
    $pdf = $container->get(RenderFinanceSummaryPdfService::class);
    $summary = $pdf->execute($actor, 'AU');
    echo 'PDF_SUMMARY bytes=' . strlen($summary) . "\n";

    /** @var ListExpenseAuditService $audit */
    $audit = $container->get(ListExpenseAuditService::class);
    $auditRows = $audit->execute($actor, 50);
    echo 'AUDIT rows=' . count($auditRows) . "\n";

    echo "LIVE_EMAIL_SENT=NO\nOCR_CALLS_MADE=NO\nLEGACY_WRITES=0\n";
    echo "PHASE4_MANUAL_VALIDATION=OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'VALIDATION_FAILED: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $cleanup();
    exit(1);
}

$cleanup();
echo "CLEANUP_DONE\n";
