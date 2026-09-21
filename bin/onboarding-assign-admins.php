<?php

declare(strict_types=1);

/**
 * Assign the Onboarding admin role to the people who run onboarding.
 *
 * Dry-run is the DEFAULT. Nothing is written without --apply.
 *
 * This is a bootstrap, not the ongoing way to grant access. Once these three
 * hold the role, a fourth admin is added in Admin → User Management by someone
 * holding USER.ROLE.ASSIGN, which records who granted it. Editing this list
 * later is the wrong move: this file is a record of an initial decision, not a
 * source of truth about who works in HR.
 *
 * Why a command and not SQL: `seed-onboarding-roles.sql` deliberately assigns
 * the role to nobody, the same way the TriExpense seed does. Role assignment
 * goes through AssignRoleToUserService so it is validated, idempotent, and
 * recorded in audit.UserManagementEvents with an actor attached. A raw INSERT
 * would leave no trace of who did it.
 *
 * Prerequisites, both checked before anything is written:
 *   1. seed-onboarding-permissions.sql has been applied (8 ONBOARDING.* rows)
 *   2. seed-onboarding-roles.sql has been applied (the ONBOARDING_ADMIN role)
 *
 * A person with no `identity.Users` row cannot be assigned anything. That is
 * reported, not worked around: the row appears when they first sign in with
 * Microsoft, or when an administrator creates them in User Management. Creating
 * accounts for people is not this script's business.
 *
 * All three hold @tricab.com addresses and sign in with Entra, which is HIGH
 * assurance — comfortably above the SUBSTANTIAL floor every administrative
 * ONBOARDING permission carries. An admin who could only use an emailed code
 * would hold the role and still be refused.
 *
 * Usage:
 *   php bin/onboarding-assign-admins.php                 # dry run, writes nothing
 *   php bin/onboarding-assign-admins.php --apply
 *   php bin/onboarding-assign-admins.php --apply --json
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignRoleToUserService;
use TriCab\Core\Onboarding\Module;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

/**
 * The people who run onboarding, decided by the platform owner on
 * 21 September 2026.
 */
const ONBOARDING_ADMINS = [
    'eloised@tricab.com' => 'Eloise Dunford',
    'diemn@tricab.com' => 'Diem Nguyen',
    'ricm@tricab.com' => 'Ric Moretti',
];

$apply = in_array('--apply', $argv, true);
$json = in_array('--json', $argv, true);

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql; there is no shared identity store to assign roles in.\n");
    exit(2);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
/** @var AssignRoleToUserService $assignRole */
$assignRole = $container->get(AssignRoleToUserService::class);

$role = Module::ROLE_ADMIN;
$lines = [];
$out = static function (string $line) use (&$lines, $json): void {
    $lines[] = $line;
    if (!$json) {
        echo $line, "\n";
    }
};

$out(sprintf('Mode: %s', $apply ? 'APPLY' : 'DRY RUN (nothing will be written)'));
$out(sprintf('Role: %s', $role));

/* ---------------------------------------------------------------- prerequisites */

$permissionCount = (int) ($db->fetchAll(
    "SELECT COUNT(*) AS n FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) LIKE N'ONBOARDING.%'",
)[0]['n'] ?? 0);

if ($permissionCount < 8) {
    fwrite(STDERR, sprintf(
        "PERMISSIONS_MISSING: found %d of 8 ONBOARDING permissions. Apply seed-onboarding-permissions.sql first.\n",
        $permissionCount,
    ));
    exit(3);
}

$roleRow = $db->fetchAll(
    'SELECT r.RoleId, r.IsActive,
            (SELECT COUNT(*) FROM [identity].[RolePermissions] rp
               JOIN [identity].[Permissions] p ON p.PermissionId = rp.PermissionId
              WHERE rp.RoleId = r.RoleId AND rp.IsActive = 1
                AND UPPER(p.PermissionCode) LIKE N\'ONBOARDING.%\') AS GrantedPermissions
     FROM [identity].[Roles] r
     WHERE UPPER(r.RoleCode) = :roleCode',
    [':roleCode' => strtoupper($role)],
);

if ($roleRow === []) {
    fwrite(STDERR, "ROLE_MISSING: {$role} does not exist. Apply seed-onboarding-roles.sql first.\n");
    exit(4);
}
if ((int) $roleRow[0]['IsActive'] !== 1) {
    fwrite(STDERR, "ROLE_INACTIVE: {$role} exists but is not active. Reactivate it before assigning it.\n");
    exit(4);
}

$granted = (int) $roleRow[0]['GrantedPermissions'];
$out(sprintf('Role holds %d of 8 ONBOARDING permissions.', $granted));

if ($granted !== 8) {
    fwrite(STDERR, sprintf(
        "ROLE_INCOMPLETE: %s holds %d of 8 ONBOARDING permissions. Re-run seed-onboarding-roles.sql.\n",
        $role,
        $granted,
    ));
    exit(4);
}

/* ------------------------------------------------------------------ resolve people */

/**
 * `identity.UserEmailAddresses` is Phase P and has not been applied to any
 * shared database yet, so it is probed rather than assumed. Where it exists,
 * a person is matched on any active verified address of theirs, because Phase P
 * made several addresses per person equally valid and an admin may have been
 * added under a different one. Where it does not, the primary column is all
 * there is — which is exactly the pre-Phase-P behaviour, not a degraded one.
 */
$hasEmailAddressTable = (int) ($db->fetchAll(
    "SELECT CASE WHEN OBJECT_ID(N'[identity].[UserEmailAddresses]', N'U') IS NULL THEN 0 ELSE 1 END AS present",
)[0]['present'] ?? 0) === 1;

$out(sprintf(
    'Address lookup: %s',
    $hasEmailAddressTable
        ? 'primary column and verified additional addresses'
        : 'primary column only (identity.UserEmailAddresses not applied here)',
));

$sql = $hasEmailAddressTable
    ? 'SELECT TOP 2
            u.UserId,
            LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS PublicId,
            u.EmailAddress,
            u.DisplayName,
            s.StatusCode
        FROM [identity].[Users] u
        LEFT JOIN [identity].[UserStatuses] s ON s.UserStatusId = u.UserStatusId
        WHERE LOWER(u.NormalizedEmailAddress) = :email
           OR EXISTS (
                SELECT 1 FROM [identity].[UserEmailAddresses] uea
                WHERE uea.UserId = u.UserId
                  AND uea.IsActive = 1
                  AND uea.VerifiedAtUtc IS NOT NULL
                  AND LOWER(uea.NormalizedEmailAddress) = :email
           )
        ORDER BY u.UserId'
    : 'SELECT TOP 2
            u.UserId,
            LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS PublicId,
            u.EmailAddress,
            u.DisplayName,
            s.StatusCode
        FROM [identity].[Users] u
        LEFT JOIN [identity].[UserStatuses] s ON s.UserStatusId = u.UserStatusId
        WHERE LOWER(u.NormalizedEmailAddress) = :email
        ORDER BY u.UserId';

$plans = [];
foreach (ONBOARDING_ADMINS as $email => $name) {
    $normalized = strtolower(trim($email));
    $rows = $db->fetchAll($sql, [':email' => $normalized]);

    if ($rows === []) {
        $plans[] = ['email' => $email, 'name' => $name, 'state' => 'NO_ACCOUNT'];
        continue;
    }

    // Two matches means two people share an address, which is an identity
    // defect. Guessing which one gets HR access is not this script's call.
    if (count($rows) > 1) {
        $plans[] = ['email' => $email, 'name' => $name, 'state' => 'AMBIGUOUS'];
        continue;
    }

    $row = $rows[0];
    $status = strtolower((string) ($row['StatusCode'] ?? ''));
    $held = $db->fetchAll(
        'SELECT COUNT(*) AS n
         FROM [identity].[UserRoles] ur
         JOIN [identity].[Roles] r ON r.RoleId = ur.RoleId
         WHERE ur.UserId = :userId AND ur.IsActive = 1 AND UPPER(r.RoleCode) = :roleCode',
        [':userId' => (int) $row['UserId'], ':roleCode' => strtoupper($role)],
    );

    $plans[] = [
        'email' => $email,
        'name' => $name,
        'state' => ((int) ($held[0]['n'] ?? 0)) > 0 ? 'ALREADY_HELD' : 'TO_ASSIGN',
        'userId' => (int) $row['UserId'],
        'publicId' => (string) $row['PublicId'],
        'displayName' => (string) ($row['DisplayName'] ?? ''),
        'status' => $status,
    ];
}

$out('');
foreach ($plans as $plan) {
    $out(sprintf(
        '%-22s %-16s %s%s',
        $plan['email'],
        $plan['state'],
        $plan['name'],
        isset($plan['status']) && $plan['status'] !== 'active'
            ? sprintf(' — account status: %s', $plan['status'])
            : '',
    ));
}

$unresolved = array_values(array_filter(
    $plans,
    static fn (array $plan): bool => in_array($plan['state'], ['NO_ACCOUNT', 'AMBIGUOUS'], true),
));

$inactive = array_values(array_filter(
    $plans,
    static fn (array $plan): bool => isset($plan['status']) && $plan['status'] !== 'active',
));

/**
 * Refuse a partial apply. Assigning two of three named admins and reporting
 * success would leave somebody without the access they were told they have,
 * and the person running this would have no reason to look again.
 */
if ($unresolved !== []) {
    $out('');
    $out('No accounts found for: ' . implode(', ', array_column($unresolved, 'email')));
    $out('They need a platform account first — one Microsoft sign-in creates it, or an');
    $out('administrator can create them in Admin → User Management. Re-run afterwards.');
    fwrite(STDERR, "UNRESOLVED_ADMINS\n");
    if ($json) {
        echo json_encode(['mode' => $apply ? 'apply' : 'dry-run', 'role' => $role, 'plans' => $plans], JSON_PRETTY_PRINT), "\n";
    }
    exit(5);
}

if ($inactive !== []) {
    $out('');
    $out('Not active: ' . implode(', ', array_column($inactive, 'email')));
    $out('Only the `active` status has AllowsLogin = 1, so `pending` and `disabled`');
    $out('accounts are refused at sign-in before any permission is consulted — the role');
    $out('would sit on an account that cannot reach it. Activate them in');
    $out('Admin → User Management, which records who did it and when, rather than by');
    $out('updating the status column directly. Then re-run this.');
    fwrite(STDERR, "INACTIVE_ADMINS\n");
    exit(6);
}

/* ------------------------------------------------------------------------ apply */

if (!$apply) {
    $toAssign = count(array_filter($plans, static fn (array $p): bool => $p['state'] === 'TO_ASSIGN'));
    $out('');
    $out(sprintf('Dry run complete. %d would be assigned, %d already hold the role.', $toAssign, count($plans) - $toAssign));
    $out('Re-run with --apply to write.');
    if ($json) {
        echo json_encode(['mode' => 'dry-run', 'role' => $role, 'plans' => $plans], JSON_PRETTY_PRINT), "\n";
    }
    exit(0);
}

/**
 * The actor recorded against every assignment. An audit row saying "somebody
 * granted HR access" is not worth writing, so this fails rather than assigning
 * anonymously.
 */
$actorRow = $db->fetchAll(
    "SELECT TOP 1 u.UserId, LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS PublicId, u.EmailAddress
     FROM [identity].[Users] u
     INNER JOIN [identity].[UserRoles] ur ON ur.UserId = u.UserId AND ur.IsActive = 1
     INNER JOIN [identity].[Roles] r ON r.RoleId = ur.RoleId AND UPPER(r.RoleCode) = N'ADMINISTRATOR' AND r.IsActive = 1
     ORDER BY u.UserId",
);

if ($actorRow === []) {
    fwrite(STDERR, "NO_ADMIN_ACTOR: no active ADMINISTRATOR to attribute the assignment to.\n");
    exit(7);
}

$actor = new ActorContext(
    userId: (int) $actorRow[0]['UserId'],
    publicId: (string) $actorRow[0]['PublicId'],
    emailAddress: (string) $actorRow[0]['EmailAddress'],
);

$out('');
$out(sprintf('Acting as %s (userId=%d).', $actor->emailAddress, $actor->userId));

$assigned = 0;
$errors = 0;

foreach ($plans as $plan) {
    if ($plan['state'] === 'ALREADY_HELD') {
        $out(sprintf('SKIP     %s already holds %s', $plan['email'], $role));
        continue;
    }

    try {
        // Idempotent inside the service: it checks for an active assignment
        // before writing, so a re-run adds nothing and records nothing.
        $assignRole->execute($actor, $plan['publicId'], $role);
        $assigned++;
        $out(sprintf('ASSIGNED %s → %s', $plan['email'], $role));
    } catch (Throwable $exception) {
        $errors++;
        $out(sprintf('ERROR    %s: %s', $plan['email'], $exception->getMessage()));
    }
}

$out('');
$out(sprintf('Assigned %d, errors %d.', $assigned, $errors));

if ($json) {
    echo json_encode([
        'mode' => 'apply',
        'role' => $role,
        'actor' => $actor->emailAddress,
        'assigned' => $assigned,
        'errors' => $errors,
        'plans' => $plans,
    ], JSON_PRETTY_PRINT), "\n";
}

exit($errors === 0 ? 0 : 1);
