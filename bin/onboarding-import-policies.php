<?php

declare(strict_types=1);

/**
 * Import the converted Employee Handbook policies (handbook policy import,
 * Step 3 — see platform/ONBOARDING-POLICY-IMPORT-PLAN.md).
 *
 * Dry-run is the DEFAULT. Nothing is written without --apply.
 *
 * Reads what bin/onboarding-convert-handbook.php wrote (<dir>/index.json and
 * <dir>/policies/<slug>.json) and puts each policy through
 * HandbookPolicyImporter, which calls AuthorOnboardingContentService — create,
 * save, publish — so every policy has its `content.*` audit rows, attributed to
 * the person named by --actor. A seed script is not an audit trail.
 *
 * Idempotent by slug: a policy whose live version already says the same thing
 * is left alone; one that differs gets a new draft, published only with
 * --publish-changes; one with an open draft is not touched. Nothing ever
 * supersedes work in progress.
 *
 * Prerequisites, checked before anything is read:
 *   1. alter-onboarding-policy-metadata.sql has been applied (the D3 columns);
 *   2. the conversion is complete — index.json names no failures;
 *   3. with --apply, the actor is an active user holding ONBOARDING.CONTENT.MANAGE.
 *
 * Usage:
 *   php bin/onboarding-import-policies.php --from=../onboarding-policy-import
 *   php bin/onboarding-import-policies.php --from=../onboarding-policy-import --apply --actor=you@tricab.com
 *   php bin/onboarding-import-policies.php --from=... --apply --actor=... --publish-changes
 *   php bin/onboarding-import-policies.php --from=... --only=leave-policy,dress-code-policy
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Onboarding\HandbookImport\HandbookPolicyImporter;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$options = getopt('', ['from:', 'actor:', 'only:', 'apply', 'publish-changes']);
$from = is_string($options['from'] ?? null) ? rtrim($options['from'], '/') : null;
$apply = array_key_exists('apply', $options);
$publishChanges = array_key_exists('publish-changes', $options);
$actorEmail = is_string($options['actor'] ?? null) ? strtolower(trim($options['actor'])) : null;
$only = is_string($options['only'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $options['only'])))) : [];

if ($from === null) {
    fwrite(STDERR, "Usage: php bin/onboarding-import-policies.php --from=<converter output dir> [--apply --actor=you@tricab.com] [--publish-changes] [--only=slug,slug]\n");
    exit(2);
}
if ($apply && $actorEmail === null) {
    fwrite(STDERR, "ACTOR_REQUIRED: --apply needs --actor=you@tricab.com. Every policy is published in somebody's name.\n");
    exit(2);
}

/* ------------------------------------------------------------ the conversion */

$index = json_decode((string) @file_get_contents($from . '/index.json'), true);
if (!is_array($index) || ($index['schema'] ?? null) !== 'tricab.onboarding.handbook-import-index/1') {
    fwrite(STDERR, "NO_CONVERSION: {$from}/index.json is missing or is not the converter's. Run bin/onboarding-convert-handbook.php first.\n");
    exit(3);
}
if (($index['failed'] ?? []) !== []) {
    fwrite(STDERR, 'CONVERSION_INCOMPLETE: the converter failed on ' . implode(', ', (array) $index['failed']) . ". Fix those first; a partial handbook is not imported.\n");
    exit(3);
}
$sourceCommit = is_array($index['source'] ?? null) ? (string) ($index['source']['commit'] ?? '') : '';
if (str_ends_with($sourceCommit, '-dirty')) {
    fwrite(STDERR, "SOURCE_DIRTY: the handbook source had uncommitted changes when it was converted ({$sourceCommit}). Convert from a clean commit.\n");
    exit(3);
}

$records = [];
foreach ((array) ($index['policies'] ?? []) as $entry) {
    $slug = is_array($entry) ? (string) ($entry['slug'] ?? '') : '';
    if ($only !== [] && !in_array($slug, $only, true)) {
        continue;
    }
    $record = json_decode((string) @file_get_contents($from . '/' . (string) $entry['file']), true);
    if (!is_array($record)) {
        fwrite(STDERR, "UNREADABLE: {$from}/{$entry['file']}\n");
        exit(3);
    }
    $records[] = $record;
}
$unknown = array_diff($only, array_column($records, 'slug'));
if ($unknown !== []) {
    fwrite(STDERR, 'NOT_IN_THE_CONVERSION: ' . implode(', ', $unknown) . "\n");
    exit(3);
}

/* -------------------------------------------------------------- the database */

$settings = Environment::settings(dirname(__DIR__));
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql; there is no shared database to import into.\n");
    exit(2);
}
$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$columns = (int) ($db->fetchAll(
    "SELECT COUNT(*) AS n FROM sys.columns
     WHERE (object_id = OBJECT_ID(N'[onboarding].[PolicyVersions]') AND name IN (N'DocumentReference', N'IssuedLabel', N'ReviewDueLabel', N'RequiresAcknowledgement'))
        OR (object_id = OBJECT_ID(N'[onboarding].[Policies]') AND name IN (N'Summary', N'ChapterTitle', N'SortOrder'))",
)[0]['n'] ?? 0);
if ($columns !== 7) {
    fwrite(STDERR, sprintf("SCHEMA_MISSING: found %d of the 7 policy metadata columns. Apply alter-onboarding-policy-metadata.sql first.\n", $columns));
    exit(4);
}

$actor = null;
if ($actorEmail !== null) {
    $rows = $db->fetchAll(
        "SELECT TOP 1 u.UserId, LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS PublicId, u.EmailAddress
         FROM [identity].[Users] u
         INNER JOIN [identity].[UserStatuses] s ON s.UserStatusId = u.UserStatusId AND s.StatusCode = N'active'
         INNER JOIN [identity].[UserRoles] ur ON ur.UserId = u.UserId AND ur.IsActive = 1
         INNER JOIN [identity].[Roles] r ON r.RoleId = ur.RoleId AND r.IsActive = 1
         INNER JOIN [identity].[RolePermissions] rp ON rp.RoleId = r.RoleId AND rp.IsActive = 1
         INNER JOIN [identity].[Permissions] p ON p.PermissionId = rp.PermissionId AND UPPER(p.PermissionCode) = :permission
         WHERE LOWER(u.NormalizedEmailAddress) = :email
         ORDER BY u.UserId",
        [':email' => $actorEmail, ':permission' => AuthorOnboardingContentService::PERMISSION],
    );
    if ($rows === []) {
        fwrite(STDERR, sprintf("ACTOR_NOT_AN_AUTHOR: %s is not an active user holding %s.\n", $actorEmail, AuthorOnboardingContentService::PERMISSION));
        exit(5);
    }
    $actor = new ActorContext(
        userId: (int) $rows[0]['UserId'],
        publicId: (string) $rows[0]['PublicId'],
        emailAddress: (string) $rows[0]['EmailAddress'],
    );
}

/* -------------------------------------------------------------------- plan */

/** @var HandbookPolicyImporter $importer */
$importer = $container->get(HandbookPolicyImporter::class);
$plan = $importer->plan($records);

echo sprintf("Mode: %s%s\n", $apply ? 'APPLY' : 'DRY RUN (nothing will be written)', $apply && $publishChanges ? ', publishing changed wording' : '');
echo sprintf("Source: tricab-training-vercel @ %s, %d policies%s\n", $sourceCommit, count($records), $only === [] ? '' : ' (--only)');
echo $actor === null ? "Actor: none given (needed for --apply)\n" : sprintf("Actor: %s (userId=%d)\n", $actor->emailAddress, $actor->userId);
echo "\n";
$counts = [];
foreach ($plan as $step) {
    $counts[$step['action']] = ($counts[$step['action']] ?? 0) + 1;
    echo sprintf(
        "  %-9s %-52s %s%s\n",
        strtoupper($step['action']),
        $step['slug'],
        $step['reason'],
        $step['itemChanges'] === [] ? '' : ' Details to update: ' . implode(', ', $step['itemChanges']) . '.',
    );
}
echo "\n";
foreach ($counts as $action => $count) {
    echo sprintf("  %d %s\n", $count, $action);
}

if (!$apply || $actor === null) {
    echo "\nDry run complete. Re-run with --apply --actor=you@tricab.com to write.\n";
    exit(0);
}

/* ------------------------------------------------------------------- apply */

$slugs = array_column($records, 'slug');
$before = auditCount($db, $slugs);
$results = $importer->apply($plan, $actor, $publishChanges);

echo "\nResults:\n";
foreach ($results as $result) {
    echo sprintf("  %-52s %s%s\n", $result['slug'], $result['result'], $result['versionNumber'] === null ? '' : ' (version ' . $result['versionNumber'] . ')');
}

/* ------------------------------------------------------------------ verify */

$params = [];
foreach ($slugs as $i => $slug) {
    $params[':s' . $i] = $slug;
}
$in = implode(', ', array_keys($params));
$verified = $db->fetchAll(
    "SELECT COUNT(DISTINCT p.[PolicyId]) AS Policies,
            SUM(CASE WHEN v.[IsPublished] = 1 AND v.[SupersededAtUtc] IS NULL THEN 1 ELSE 0 END) AS Live
     FROM [onboarding].[Policies] p
     LEFT JOIN [onboarding].[PolicyVersions] v ON v.[PolicyId] = p.[PolicyId]
     WHERE p.[Slug] IN ({$in})",
    $params,
);
echo sprintf(
    "\nVerified: %d of %d policies present, %d live versions, %d new audit rows.\n",
    (int) ($verified[0]['Policies'] ?? 0),
    count($slugs),
    (int) ($verified[0]['Live'] ?? 0),
    auditCount($db, $slugs) - $before,
);
exit(0);

/**
 * `content.*` audit rows about these policies or their versions.
 *
 * The slugs are bound twice, under two sets of names: PDO_SQLSRV binds each
 * occurrence of a named parameter separately, so one name used twice leaves a
 * marker unbound.
 *
 * @param list<string> $slugs
 */
function auditCount(SqlConnection $db, array $slugs): int
{
    $params = [];
    $items = [];
    $versions = [];
    foreach ($slugs as $i => $slug) {
        $params[':i' . $i] = $slug;
        $params[':v' . $i] = $slug;
        $items[] = ':i' . $i;
        $versions[] = ':v' . $i;
    }

    return (int) ($db->fetchAll(
        sprintf(
            "SELECT COUNT(*) AS n FROM [audit].[OnboardingAdminEvents] e
             WHERE e.[EventType] LIKE N'content.%%'
               AND (e.[TargetPublicId] IN (SELECT [PublicId] FROM [onboarding].[Policies] WHERE [Slug] IN (%s))
                 OR e.[TargetPublicId] IN (SELECT v.[PublicId] FROM [onboarding].[PolicyVersions] v
                                           JOIN [onboarding].[Policies] p ON p.[PolicyId] = v.[PolicyId]
                                           WHERE p.[Slug] IN (%s)))",
            implode(', ', $items),
            implode(', ', $versions),
        ),
        $params,
    )[0]['n'] ?? 0);
}
