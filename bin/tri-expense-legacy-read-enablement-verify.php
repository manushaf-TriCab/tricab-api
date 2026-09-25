<?php

declare(strict_types=1);

/**
 * Post-enablement verification for TriExpense legacy read (READ-ONLY).
 * Never prints passwords, tokens, emails, logins, or receipt payloads.
 *
 * Usage (App Service, with app settings in process env):
 *   php bin/tri-expense-legacy-read-enablement-verify.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\DTO\ExpenseFeatureFlags;
use TriCab\Core\Expense\Application\DTO\ExpenseReceiptDetails;
use TriCab\Core\Expense\Application\Service\LegacyExpenseIdentityResolver;
use TriCab\Core\Expense\Application\Service\ListMyExpenseReceiptsService;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Contract\LegacyExpenseReadRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Api\Expense\Legacy\MysqlLegacyExpenseReadRepository;
use TriCab\Api\Expense\Legacy\NullLegacyExpenseReadRepository;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

/**
 * @param array<string, mixed> $report
 */
function te_out(array $report, int $code): never
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$container = ContainerFactory::create($settings);

/** @var ExpenseFeatureFlags $flags */
$flags = $container->get(ExpenseFeatureFlags::class);
/** @var LegacyExpenseReadRepository $legacyRepo */
$legacyRepo = $container->get(LegacyExpenseReadRepository::class);
/** @var ListMyExpenseReceiptsService $list */
$list = $container->get(ListMyExpenseReceiptsService::class);
/** @var ExpenseProfileRepository $profiles */
$profiles = $container->get(ExpenseProfileRepository::class);
/** @var LegacyExpenseIdentityResolver $identity */
$identity = $container->get(LegacyExpenseIdentityResolver::class);
/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);

$report = [
    'check' => 'tri-expense-legacy-read-enablement-verify',
    'readOnly' => true,
    'featureFlagSetting' => $settings->triexpenseLegacyReadEnabled ? 'true' : 'false',
    'featuresLegacyRead' => $flags->legacyRead,
    'legacyRepoClass' => $legacyRepo::class,
    'legacyRepoIsMysql' => $legacyRepo instanceof MysqlLegacyExpenseReadRepository,
    'legacyRepoIsNull' => $legacyRepo instanceof NullLegacyExpenseReadRepository,
    'users' => [],
    'unmatchedNegative' => null,
    'isolation' => null,
    'overallPass' => false,
    'secretsPrinted' => 0,
];

if (!$settings->triexpenseLegacyReadEnabled || !$flags->legacyRead || !($legacyRepo instanceof MysqlLegacyExpenseReadRepository)) {
    $report['error'] = 'Legacy read not active in runtime DI (flag/repo).';
    te_out($report, 2);
}

$rows = $sql->fetchAll(
    'SELECT TOP 50 p.[UserId], p.[LegacyLogin], p.[LegacyEmail], p.[IsActive]
     FROM [expense].[ExpenseProfiles] p
     WHERE p.[IsActive] = 1
     ORDER BY p.[UserId]',
);

$matched = [];
$unmatched = [];
foreach ($rows as $row) {
    $userId = UserId::fromInt((int) $row['UserId']);
    $profile = $profiles->findByUserId($userId);
    if ($profile === null) {
        continue;
    }
    $keys = $identity->ownerKeys($profile);
    if ($keys === []) {
        $unmatched[] = $profile;
        continue;
    }
    $legacyRows = $legacyRepo->listForOwnerKeys($keys, 5);
    if ($legacyRows === []) {
        $unmatched[] = $profile;
    } else {
        $matched[] = $profile;
    }
}

/**
 * @return array<string, mixed>
 */
function te_user_summary(ExpenseProfile $profile, ListMyExpenseReceiptsService $list): array
{
    $actor = new ExpenseActor(
        userId: $profile->userId(),
        publicId: 'verify-' . $profile->userId()->toInt(),
        emailAddress: 'redacted@example.com',
        permissions: [Module::PERMISSION_ACCESS],
        roles: [],
    );
    $items = $list->execute($actor);
    $legacy = array_values(array_filter($items, static fn (ExpenseReceiptDetails $i) => $i->source === ExpenseReceiptDetails::SOURCE_LEGACY));
    $azure = array_values(array_filter($items, static fn (ExpenseReceiptDetails $i) => $i->source !== ExpenseReceiptDetails::SOURCE_LEGACY));
    $ids = array_map(static fn (ExpenseReceiptDetails $i) => $i->publicId, $items);
    $dup = count($ids) - count(array_unique($ids));
    $statuses = [];
    foreach ($legacy as $item) {
        $statuses[$item->status] = ($statuses[$item->status] ?? 0) + 1;
    }
    $legacyIds = [];
    foreach ($legacy as $item) {
        $legacyIds[] = $item->publicId;
    }

    return [
        'userId' => $profile->userId()->toInt(),
        'total' => count($items),
        'legacyCount' => count($legacy),
        'azureCount' => count($azure),
        'allLegacySource' => count($legacy) === 0 || count(array_filter($legacy, static fn ($i) => $i->source === 'legacy')) === count($legacy),
        'duplicatePublicIds' => $dup,
        'statusHistogram' => $statuses,
        'legacyPublicIdsSample' => array_slice($legacyIds, 0, 3),
    ];
}

if ($matched === []) {
    $report['error'] = 'No ExpenseProfile matched legacy receipts.';
    te_out($report, 3);
}

$userA = te_user_summary($matched[0], $list);
$report['users']['A'] = $userA;

$userB = null;
if (isset($matched[1])) {
    $userB = te_user_summary($matched[1], $list);
    $report['users']['B'] = $userB;
}

// Negative: unmatched profile must not get unscoped dump
if ($unmatched !== []) {
    $neg = te_user_summary($unmatched[0], $list);
    $report['unmatchedNegative'] = [
        'userId' => $neg['userId'],
        'legacyCount' => $neg['legacyCount'],
        'total' => $neg['total'],
        'notFullDataset' => $neg['legacyCount'] < 100,
        'pass' => $neg['legacyCount'] === 0,
    ];
} else {
    // Force a nonsense profile path via empty owner keys — already covered by repo,
    // but report absence of unmatched profiles.
    $report['unmatchedNegative'] = [
        'note' => 'no_unmatched_active_profiles_in_sample',
        'pass' => null,
    ];
}

$leak = 0;
if ($userB !== null) {
    $idsA = $userA['legacyPublicIdsSample'];
    // Re-fetch full id sets for isolation
    $actorA = new ExpenseActor(
        userId: UserId::fromInt((int) $userA['userId']),
        publicId: 'verify-a',
        emailAddress: 'a@example.com',
        permissions: [Module::PERMISSION_ACCESS],
        roles: [],
    );
    $actorB = new ExpenseActor(
        userId: UserId::fromInt((int) $userB['userId']),
        publicId: 'verify-b',
        emailAddress: 'b@example.com',
        permissions: [Module::PERMISSION_ACCESS],
        roles: [],
    );
    $setA = [];
    foreach ($list->execute($actorA) as $item) {
        if ($item->source === 'legacy') {
            $setA[$item->publicId] = true;
        }
    }
    $setB = [];
    foreach ($list->execute($actorB) as $item) {
        if ($item->source === 'legacy') {
            $setB[$item->publicId] = true;
        }
    }
    foreach ($setA as $id => $_) {
        if (isset($setB[$id])) {
            $leak++;
        }
    }
    $report['isolation'] = [
        'userALegacyCount' => count($setA),
        'userBLegacyCount' => count($setB),
        'crossOwnerLeakCount' => $leak,
        'pass' => $leak === 0 && count($setA) > 0 && count($setB) > 0,
    ];
} else {
    $report['isolation'] = [
        'note' => 'only_one_matched_profile',
        'crossOwnerLeakCount' => null,
        'pass' => null,
    ];
}

$report['overallPass'] = $report['featuresLegacyRead'] === true
    && $report['legacyRepoIsMysql'] === true
    && ($userA['legacyCount'] ?? 0) > 0
    && ($userA['duplicatePublicIds'] ?? 1) === 0
    && ($report['unmatchedNegative']['pass'] ?? false) === true
    && (($report['isolation']['pass'] ?? false) === true || ($report['isolation']['pass'] ?? null) === null);

te_out($report, $report['overallPass'] ? 0 : 10);
