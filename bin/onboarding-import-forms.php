<?php

declare(strict_types=1);

/**
 * Import TriCab's own onboarding forms (Phase 5.4) — today the Employee Details
 * Form, HR-FOR-004-V007 — as DRAFTS, through the authoring service.
 *
 * Dry-run is the DEFAULT. Nothing is written without --apply, and --apply needs
 * --actor: every form is created in somebody's name. A form that already exists
 * is never touched. HR publishes each one from the editor after reading it.
 *
 * Usage:
 *   php bin/onboarding-import-forms.php
 *   php bin/onboarding-import-forms.php --apply --actor=you@tricab.com
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Onboarding\FormImport\TriCabFormImporter;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$options = getopt('', ['actor:', 'apply']);
$apply = array_key_exists('apply', $options);
$actorEmail = is_string($options['actor'] ?? null) ? strtolower(trim($options['actor'])) : null;
if ($apply && $actorEmail === null) {
    fwrite(STDERR, "ACTOR_REQUIRED: --apply needs --actor=you@tricab.com. Every form is created in somebody's name.\n");
    exit(2);
}

$settings = Environment::settings(dirname(__DIR__));
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql; there is no shared database to import into.\n");
    exit(2);
}
$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

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

/** @var TriCabFormImporter $importer */
$importer = $container->get(TriCabFormImporter::class);
$plan = $importer->plan();

echo sprintf("Mode: %s\n", $apply ? 'APPLY' : 'DRY RUN (nothing will be written)');
echo $actor === null ? "Actor: none given (needed for --apply)\n" : sprintf("Actor: %s (userId=%d)\n", $actor->emailAddress, $actor->userId);
echo "\n";
foreach ($plan as $step) {
    echo sprintf("  %-7s %-32s %s\n", strtoupper($step['action']), $step['slug'], $step['reason']);
}

if (!$apply || $actor === null) {
    echo "\nDry run complete. Re-run with --apply --actor=you@tricab.com to write.\n";
    exit(0);
}

echo "\nResults:\n";
foreach ($importer->apply($plan, $actor) as $result) {
    echo sprintf("  %-32s %s\n", $result['slug'], $result['result']);
}
exit(0);
