<?php

declare(strict_types=1);

/**
 * Fills `finance.portal_user_map` from an export of the finance portal's `platform_users`.
 *
 *   php bin/map-finance-portal-users.php --export=platform_users.json            # a plan; writes nothing
 *   php bin/map-finance-portal-users.php --export=platform_users.csv --report=unmatched.csv
 *   php bin/map-finance-portal-users.php --export=platform_users.json --apply    # writes the matches
 *
 * The export is JSON (an array of objects, or {"users": [...]}) or CSV with a header row;
 * columns `id`, `email`, `full_name` (or `name`) and optionally `is_active`. Each portal
 * user is matched, by email, to the platform user that holds that address
 * (`identity.Users` or `identity.UserEmailAddresses`). Nobody is created and nothing is
 * guessed from a name. Every portal user with no platform user is listed, as are the
 * conflicts (an email two platform users hold, a portal user already mapped elsewhere, a
 * platform user two portal users would claim), and left unmapped for a person to resolve.
 * `--report=FILE` also writes the unmatched and the conflicts to a CSV.
 *
 * It only plans unless `--apply` is given, and it is safe to run again: what is mapped is
 * left as it is. Writing to any database that is not on this machine also needs
 * `--approved-by=NAME` (plan 9: nothing is applied to a shared database without the
 * owner's approval); the name is only printed. **Written for F1.10 and not run against a
 * shared database.** Prints one JSON line of counts first, then the lists.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserExport;
use TriCab\Core\Finance\Shared\Access\FinancePortalUserMatcher;

$options = getopt('', ['export:', 'report::', 'apply', 'approved-by::']);
$file = is_string($options['export'] ?? null) ? $options['export'] : '';
if ($file === '' || !is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Usage: php bin/map-finance-portal-users.php --export=FILE.json|FILE.csv [--report=FILE.csv] [--apply [--approved-by=NAME]]\n");
    exit(2);
}
$apply = array_key_exists('apply', $options);
$approvedBy = is_string($options['approved-by'] ?? null) ? trim($options['approved-by']) : '';

$contents = file_get_contents($file);
if ($contents === false) {
    fwrite(STDERR, "Cannot read {$file}.\n");
    exit(2);
}
$format = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'csv' ? 'csv' : 'json';
$export = FinancePortalUserExport::parse($contents, $format);
if ($export['users'] === [] && $export['rejected'] === []) {
    fwrite(STDERR, "The export has no users.\n");
    exit(2);
}

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "This reads identity's addresses, so it needs the SQL user repository (USER_REPOSITORY=azure-sql) and a database.\n");
    exit(1);
}
$local = in_array(strtolower(explode(',', $settings->dbHost)[0]), ['localhost', '127.0.0.1', '::1', '(local)'], true);
if ($apply && !$local && $approvedBy === '') {
    fwrite(STDERR, "DB_HOST is not this machine. Writing there needs the owner's approval: add --approved-by=NAME.\n");
    exit(1);
}

$matcher = ContainerFactory::create($settings)->get(FinancePortalUserMatcher::class);
if (!$matcher instanceof FinancePortalUserMatcher) {
    fwrite(STDERR, "The container has no matcher.\n");
    exit(1);
}
$plan = $matcher->plan($export['users'], $export['rejected']);
$added = $apply ? $matcher->apply($plan, new DateTimeImmutable('now', new DateTimeZone('UTC'))) : 0;

fwrite(STDOUT, json_encode([
    'at' => gmdate('c'),
    'mode' => $apply ? 'apply' : 'plan',
    'database' => $local ? 'local' : 'not local' . ($approvedBy !== '' ? ', approved by ' . $approvedBy : ''),
] + $plan->counts() + ['added' => $added], JSON_THROW_ON_ERROR) . "\n");

foreach ($plan->unmatched as $row) {
    fwrite(STDOUT, sprintf("UNMATCHED  %s  %s  %s%s\n", $row['portalUserId'], $row['email'], $row['name'], $row['active'] ? '' : '  (inactive in the portal)'));
}
foreach ($plan->conflicts as $row) {
    fwrite(STDOUT, sprintf("CONFLICT   %s  %s  %s\n", $row['portalUserId'], $row['email'], $row['reason']));
}
foreach ($plan->rejected as $row) {
    fwrite(STDOUT, sprintf("REJECTED   row %d  %s\n", $row['row'], $row['reason']));
}

$report = is_string($options['report'] ?? null) ? $options['report'] : '';
if ($report !== '') {
    $handle = fopen($report, 'wb');
    if ($handle === false) {
        fwrite(STDERR, "Cannot write {$report}.\n");
        exit(1);
    }
    fputcsv($handle, ['kind', 'portal_user_id', 'email', 'name', 'detail'], ',', '"', '');
    foreach ($plan->unmatched as $row) {
        fputcsv($handle, ['unmatched', $row['portalUserId'], $row['email'], $row['name'], $row['active'] ? 'active' : 'inactive in the portal'], ',', '"', '');
    }
    foreach ($plan->conflicts as $row) {
        fputcsv($handle, ['conflict', $row['portalUserId'], $row['email'], '', $row['reason']], ',', '"', '');
    }
    fclose($handle);
}
exit(0);
