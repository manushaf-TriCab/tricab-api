<?php

declare(strict_types=1);

/**
 * Keep Entra-linked platform groups in step with Entra (Tri-Task company plan, Phase 1).
 * See {@see \TriCab\Api\Identity\Groups\GroupSync} for the rule: only groups an Administrator
 * linked are read; only `entra` member rows are added or removed; members with no platform
 * account are created as `pending` users (never activated); a group gone from Entra is
 * unlinked, not deleted. Platform groups are never touched.
 *
 *   php bin/sync-platform-groups.php                 # one pass over every linked group
 *   php bin/sync-platform-groups.php --if-enabled    # only when IDENTITY_GROUP_SYNC_ENABLED=true (the WebJob)
 *   php bin/sync-platform-groups.php --env=FILE      # read settings from that dotenv file instead of .env
 *
 * Needs the Graph application permission `GroupMember.Read.All` (tenant admin consent) on the
 * API's app registration. Until it is granted a pass reads nothing, changes nothing, notes the
 * reason on the linked groups, prints a typed outcome and exits 6: a clear "needs a person", not a crash.
 *
 * Prints one JSON line of counts (never a person's details). Exit codes: 0 done (or nothing to do),
 * 1 failure (one or more groups failed; the rest synced), 3 switched off, 6 permission not granted or
 * Graph not configured (needs a person), 75 the database is resuming.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Groups\GraphNotConfigured;
use TriCab\Api\Identity\Groups\GraphPermissionNotGranted;
use TriCab\Api\Identity\Groups\GroupSync;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;

CoreAutoload::register();

$options = getopt('', ['env:', 'if-enabled']);

$say = static function (array $line): void {
    fwrite(STDOUT, json_encode(['at' => gmdate('c')] + $line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
};

if (isset($options['env'])) {
    $file = (string) $options['env'];
    if (!is_readable($file)) {
        fwrite(STDERR, "Cannot read --env file {$file}.\n");
        exit(1);
    }
    $env = array_merge($_SERVER, $_ENV, Dotenv::parse((string) file_get_contents($file)));
} else {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $env = array_merge($_ENV, $_SERVER);
}

// The switch. Off unless the app setting is exactly true; checked before anything touches the database.
if (isset($options['if-enabled']) && strtolower(trim((string) ($env['IDENTITY_GROUP_SYNC_ENABLED'] ?? ''))) !== 'true') {
    exit(3);
}

try {
    $sync = ContainerFactory::create(Settings::fromEnvironment($env))->get(GroupSync::class);
    if (!$sync instanceof GroupSync) {
        throw new RuntimeException('The container has no group sync.');
    }

    $result = $sync->syncAll();
    $say($result);

    exit(match ($result['outcome']) {
        GraphPermissionNotGranted::OUTCOME, GraphNotConfigured::OUTCOME => 6,
        GroupSync::OUTCOME_FAILED => 1,
        default => 0,
    });
} catch (DatabaseTemporarilyUnavailable) {
    $say(['resuming' => 'The database is resuming; the next pass retries.']);
    exit(75);
} catch (Throwable $e) {
    // Never a DSN (it can carry a token), never a person's details.
    $say(['failed' => get_class($e) . ': ' . $e->getMessage()]);
    exit(1);
}
