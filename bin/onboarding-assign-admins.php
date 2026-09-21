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
 *   php bin/onboarding-assign-admins.php --apply --actor=you@tricab.com
 *   php bin/onboarding-assign-admins.php --apply --only=one@tricab.com
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
 *
 * The platform owner is on the list as well. He already reaches the console
 * through `ADMINISTRATOR`, which currently maps all eight ONBOARDING
 * permissions — but that mapping is meant to be narrowed to `ONBOARDING.ADMIN`
 * alone, so that a platform administrator can grant access to the module
 * without reading inductions. Holding the role directly is what survives that
 * change.
 */
const ONBOARDING_ADMINS = [
    'eloised@tricab.com' => 'Eloise Dunford',
    'diemn@tricab.com' => 'Diem Nguyen',
    'ricm@tricab.com' => 'Ric Moretti',
    'manushaf@tricab.com' => 'Manusha Fernando',
];

$apply = in_array('--apply', $argv, true);
$json = in_array('--json', $argv, true);

/**
 * `--only=a@tricab.com,b@tricab.com` narrows the run to some of the people
 * above. It exists because the all-or-nothing guard below is the right default
 * and the wrong behaviour when one person is ready and another is not — as
 * happens while accounts are still `pending`.
 *
 * An address not on the list is refused rather than assigned. This script is a
 * record of a decision about who runs onboarding, not a general-purpose way to
 * hand out the role; that is Admin → User Management, where the grant is
 * attributed to whoever makes it.
 */
$only = [];
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--only=')) {
        foreach (explode(',', substr($argument, 7)) as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate === '') {
                continue;
            }
            if (!array_key_exists($candidate, ONBOARDING_ADMINS)) {
                fwrite(STDERR, sprintf(
                    "NOT_ON_THE_LIST: %s is not one of the named Onboarding admins. Use Admin → User Management to grant the role to anybody else.\n",
                    $candidate,
                ));
                exit(2);
            }
            $only[] = $candidate;
        }
    }
}

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

/* What the role must hold is ADMIN_ROLE_PERMISSIONS — the five administrative
   ones — and not all eight. Since 21 Sep 2026 ONBOARDING_ADMIN carries no
   self-service permission, because TriCab's admins have no induction of their
   own; an admin who somehow needs one gets it from `employee` like everybody
   else. Counting ONBOARDING.% here instead would fail this script the moment
   the narrowing seed is re-run.

   The list is built from the constant rather than written out, so the script and
   the module cannot disagree about what the role means. Each value gets its own
   parameter name: PDO_SQLSRV rewrites named parameters to positional markers, so
   a name used twice leaves a marker unbound. */
$requiredPermissions = Module::ADMIN_ROLE_PERMISSIONS;
$permissionParams = [];
foreach ($requiredPermissions as $index => $code) {
    $permissionParams[':perm' . $index] = strtoupper($code);
}
$permissionPlaceholders = implode(', ', array_keys($permissionParams));

$roleRow = $db->fetchAll(
    sprintf(
        'SELECT r.RoleId, r.IsActive,
                (SELECT COUNT(*) FROM [identity].[RolePermissions] rp
                   JOIN [identity].[Permissions] p ON p.PermissionId = rp.PermissionId
                  WHERE rp.RoleId = r.RoleId AND rp.IsActive = 1
                    AND UPPER(p.PermissionCode) IN (%s)) AS GrantedPermissions
         FROM [identity].[Roles] r
         WHERE UPPER(r.RoleCode) = :roleCode',
        $permissionPlaceholders,
    ),
    $permissionParams + [':roleCode' => strtoupper($role)],
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
$required = count($requiredPermissions);
$out(sprintf('Role holds %d of %d administrative ONBOARDING permissions.', $granted, $required));

if ($granted !== $required) {
    fwrite(STDERR, sprintf(
        "ROLE_INCOMPLETE: %s holds %d of %d administrative ONBOARDING permissions. Re-run seed-onboarding-roles.sql.\n",
        $role,
        $granted,
        $required,
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
                  AND LOWER(uea.NormalizedEmailAddress) = :emailVerified
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

$selected = $only === []
    ? ONBOARDING_ADMINS
    : array_intersect_key(ONBOARDING_ADMINS, array_flip($only));

if ($only !== []) {
    $out(sprintf('Restricted to: %s', implode(', ', array_keys($selected))));
}

$plans = [];
foreach ($selected as $email => $name) {
    $normalized = strtolower(trim($email));

    // :email and :emailVerified carry the same value, and they are two
    // parameters rather than one because PDO_SQLSRV rewrites named parameters
    // to positional markers and binds each occurrence separately. A name used
    // twice leaves the second marker unbound and the driver answers
    // "COUNT field incorrect or syntax error", which names neither the
    // parameter nor the query. See the trap list in the implementation plan.
    $params = [':email' => $normalized];
    if ($hasEmailAddressTable) {
        $params[':emailVerified'] = $normalized;
    }

    $rows = $db->fetchAll($sql, $params);

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
 * Refuse a partial apply. Assigning three of four named admins and reporting
 * success would leave somebody without the access they were told they have,
 * and the person running this would have no reason to look again. Narrowing the
 * run is possible, but only by saying so explicitly with --only.
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
 *
 * `--actor=you@tricab.com` is how you appear in the audit trail instead of
 * whichever administrator happens to sort first. Worth passing: the row is the
 * only lasting record of who decided this, and "the first ADMINISTRATOR by id"
 * is not a decision anybody made.
 */
$actorEmail = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--actor=')) {
        $actorEmail = strtolower(trim(substr($argument, 8)));
    }
}

if ($actorEmail !== null) {
    $actorRow = $db->fetchAll(
        "SELECT TOP 1 u.UserId, LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS PublicId, u.EmailAddress
         FROM [identity].[Users] u
         INNER JOIN [identity].[UserRoles] ur ON ur.UserId = u.UserId AND ur.IsActive = 1
         INNER JOIN [identity].[Roles] r ON r.RoleId = ur.RoleId AND UPPER(r.RoleCode) = N'ADMINISTRATOR' AND r.IsActive = 1
         WHERE LOWER(u.NormalizedEmailAddress) = :email
         ORDER BY u.UserId",
        [':email' => $actorEmail],
    );

    if ($actorRow === []) {
        fwrite(STDERR, sprintf(
            "ACTOR_NOT_AN_ADMINISTRATOR: %s is not an active ADMINISTRATOR, so it cannot be recorded as granting a role.\n",
            $actorEmail,
        ));
        exit(7);
    }
} else {
    $actorRow = $db->fetchAll(
        "SELECT TOP 1 u.UserId, LOWER(CONVERT(NVARCHAR(36), u.PublicId)) AS PublicId, u.EmailAddress
         FROM [identity].[Users] u
         INNER JOIN [identity].[UserRoles] ur ON ur.UserId = u.UserId AND ur.IsActive = 1
         INNER JOIN [identity].[Roles] r ON r.RoleId = ur.RoleId AND UPPER(r.RoleCode) = N'ADMINISTRATOR' AND r.IsActive = 1
         ORDER BY u.UserId",
    );
}

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
$out(sprintf(
    'Acting as %s (userId=%d).%s',
    $actor->emailAddress,
    $actor->userId,
    $actorEmail === null ? ' Pass --actor=you@tricab.com to be recorded yourself.' : '',
));

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
