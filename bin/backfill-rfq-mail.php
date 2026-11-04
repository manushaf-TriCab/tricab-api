<?php

declare(strict_types=1);

/**
 * One-off backfill of RFQ mail the live reader never saw: the Inbox's child folders (staff file mail there by hand)
 * and, with a flag, Deleted Items. Run it by SSH on the App Service, from /home/site/wwwroot/app:
 *
 *   php bin/backfill-rfq-mail.php --days=14 --include-deleted            # DRY RUN (the default): counts only
 *   php bin/backfill-rfq-mail.php --days=14 --include-deleted --apply    # record + classify what is new
 *
 *   --days=N           look back N days (1 to 30; default 14)
 *   --include-deleted  also read Deleted Items
 *   --dry-run          the default: per folder, how many messages and how many are already recorded vs new.
 *                      Nothing is recorded or classified (no database writes, no model calls).
 *   --apply            record and classify the new ones, in SHADOW mode: it never makes drafts, links or
 *                      notifications, whatever RFQ_MAIL_MODE is
 *   --limit=N          with --apply, stop after N new messages (try 10 first); a later run carries on
 *   --pause=SECONDS    pause between model calls (default 1); a 429 backs off 5, 10, 20, 40, 80 s and retries;
 *                      a message still throttled after that is DEFERRED (not recorded) and picked up by a re-run
 *
 * Graph is read with GET only (a filtered list, not delta): nothing in the mailbox is marked read, moved, flagged or
 * changed, and the live reader's cursors and state files are never touched. Messages already recorded (matched on
 * internetMessageId, in whatever folder they sit) are skipped, so it is safe to run again. The mailbox's own mail is
 * recorded as skipped by the pre-filter, exactly as the reader does.
 *
 * Exit codes: 0 done, 1 failed, 2 not configured (RFQ_MAIL_GRAPH_*, Foundry for --apply, or the mail store is not the
 * database), 4 Graph refused or could not be reached, 75 the database is resuming (run it again), 64 bad option.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Assistant\Infrastructure\Foundry\ManagedIdentityFoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Prompt\MarkdownPromptRepository;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Api\Mail\Intake\FoundryMailClassifier;
use TriCab\Api\Mail\Intake\GraphMailDeltaSource;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Shared\Mailbox\GraphMailboxReader;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Intake\ClassifierInput;
use TriCab\Core\Mail\Intake\ClassifierVerdict;
use TriCab\Core\Mail\Intake\MailBackfill;
use TriCab\Core\Mail\Intake\MailClassifier;
use TriCab\Core\Mail\Intake\MailReaderOptions;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Mail\Intake\PacedMailClassifier;
use TriCab\Core\Mail\Intake\TransientMailReaderState;
use TriCab\Core\Shared\Mailbox\MailboxAccessDenied;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

$options = getopt('', ['days::', 'include-deleted', 'dry-run', 'apply', 'limit::', 'pause::', 'allow-memory']);
$say = static function (string $line): void {
    fwrite(STDOUT, $line . "\n");
};

$days = isset($options['days']) ? (string) $options['days'] : '14';
if (!ctype_digit($days) || (int) $days < 1 || (int) $days > MailBackfill::MAX_DAYS) {
    fwrite(STDERR, 'backfill-rfq-mail: --days must be a whole number from 1 to ' . MailBackfill::MAX_DAYS . ".\n");
    exit(64);
}
$limit = isset($options['limit']) ? (string) $options['limit'] : '0';
$pause = isset($options['pause']) ? (string) $options['pause'] : '1';
if (!ctype_digit($limit) || !is_numeric($pause) || (float) $pause < 0) {
    fwrite(STDERR, "backfill-rfq-mail: --limit must be a whole number and --pause a number of seconds.\n");
    exit(64);
}
if (isset($options['apply']) && isset($options['dry-run'])) {
    fwrite(STDERR, "backfill-rfq-mail: choose --apply or --dry-run, not both.\n");
    exit(64);
}
$apply = isset($options['apply']);

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));

try {
    $container = ContainerFactory::create($settings, hydratePlatformSettings: true, refreshPlatformSettings: false);
} catch (DatabaseTemporarilyUnavailable) {
    fwrite(STDERR, "backfill-rfq-mail: the database is not available yet (usually the serverless database resuming). Run it again in a minute.\n");
    exit(75);
}
$settings = $container->get(Settings::class);

$missing = [];
if (!$settings->isMailBodyReadingConfigured()) {
    $missing[] = 'RFQ_MAIL_GRAPH_TENANT_ID / RFQ_MAIL_GRAPH_CLIENT_ID / RFQ_MAIL_GRAPH_CLIENT_SECRET (or the platform GRAPH_*)';
}
if ($apply && !$settings->isRfqMailClassifierConfigured()) {
    $missing[] = 'FOUNDRY_ENABLED / FOUNDRY_ENDPOINT / RFQ_MAIL_CLASSIFIER_MODEL';
}
if ($missing !== []) {
    fwrite(STDERR, 'backfill-rfq-mail: not configured: ' . implode('; ', $missing) . ".\n");
    exit(2);
}

$store = $container->get(MailStore::class);
if (!$store instanceof MailStore || ($store instanceof InMemoryMailStore && !isset($options['allow-memory']))) {
    fwrite(STDERR, "backfill-rfq-mail: the mail store is not the database (USER_REPOSITORY is not azure-sql), so there is nothing to compare or record against. Refusing.\n");
    exit(2);
}

$http = new StreamJsonHttpClient(20.0, 5.0);
$graph = new GraphMailboxReader($http, $settings->rfqMailGraphTenantId, $settings->rfqMailGraphClientId, $settings->rfqMailGraphClientSecret);
$source = new GraphMailDeltaSource($graph);

$paced = null;
if ($apply) {
    $classifierHttp = new StreamJsonHttpClient((float) $settings->rfqMailClassifierTimeoutSeconds, 5.0);
    $paced = new PacedMailClassifier(
        new FoundryMailClassifier(
            $classifierHttp,
            new ManagedIdentityFoundryAccessTokenProvider($classifierHttp),
            $settings->foundryEndpoint,
            $settings->rfqMailClassifierModel,
            $settings->foundryAuthMode,
            $settings->foundryApiKey,
            (new MarkdownPromptRepository(dirname(__DIR__) . '/PROMPTS'))->get('rfq-mail-classify', 'v1'),
        ),
        (float) $pause,
    );
}

// A dry run never ingests, so its classifier is one that refuses to be called.
$classifier = $paced ?? new class implements MailClassifier {
    public function classify(ClassifierInput $input): ClassifierVerdict
    {
        throw new LogicException('A dry run never calls the model.');
    }

    public function modelName(): string
    {
        return '';
    }

    public function promptVersion(): string
    {
        return '';
    }
};

// The live reader's own intake path, with no draft automation (so never a draft, link or notification) and with
// state that lives only in this process (so never the reader's cursors, folder list or retry list).
$pass = new MailReaderPass(
    new MailReaderOptions($settings->rfqMailMailbox, true, MailReaderOptions::MODE_SHADOW),
    $source,
    $graph,
    new MailIntakeRecorder($store),
    $store,
    $classifier,
    new TransientMailReaderState(),
    $settings->rfqMailOwnAddresses,
    static function (string $line): void {
        fwrite(STDERR, '[' . gmdate('c') . '] ' . $line . "\n");
    },
);

$say(sprintf(
    '%s: mailbox %s, last %d day(s)%s%s.',
    $apply ? 'APPLY (shadow: record + classify, no drafts)' : 'DRY RUN (nothing is recorded or classified)',
    $settings->rfqMailMailbox,
    (int) $days,
    isset($options['include-deleted']) ? ', including Deleted Items' : '',
    $apply && (int) $limit > 0 ? ', at most ' . (int) $limit . ' new message(s)' : '',
));

try {
    $summary = (new MailBackfill($settings->rfqMailMailbox, $source, $pass, $paced, $say))->run(
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
        (int) $days,
        isset($options['include-deleted']),
        $apply,
        (int) $limit,
    );
} catch (DatabaseTemporarilyUnavailable) {
    fwrite(STDERR, "backfill-rfq-mail: the database is not available yet (usually the serverless database resuming). Run it again in a minute; recorded messages are skipped.\n");
    exit(75);
} catch (MailboxReadFailed | MailboxReadUnavailable | MailboxAccessDenied $failure) {
    fwrite(STDERR, 'backfill-rfq-mail: Microsoft Graph: ' . $failure->getMessage() . " Run it again; recorded messages are skipped.\n");
    exit(4);
} catch (Throwable $failure) {
    fwrite(STDERR, 'backfill-rfq-mail: failed with ' . $failure::class . ': ' . mb_substr($failure->getMessage(), 0, 300) . "\n");
    exit(1);
}

$totals = ['seen' => 0, 'alreadyRecorded' => 0, 'new' => 0, 'recorded' => 0, 'classified' => 0, 'skipped' => 0, 'deferred' => 0, 'errors' => 0];
foreach ($summary as $row) {
    foreach ($totals as $key => $value) {
        $totals[$key] = $value + $row[$key];
    }
}
$say(str_repeat('-', 60));
$say(sprintf('Total: seen %d, already recorded %d, new %d.', $totals['seen'], $totals['alreadyRecorded'], $totals['new']));
if ($apply) {
    $say(sprintf('Recorded now %d, classified %d, pre-filter skipped %d, deferred %d, errors %d.', $totals['recorded'], $totals['classified'], $totals['skipped'], $totals['deferred'], $totals['errors']));
    if ($totals['deferred'] > 0) {
        $say('Deferred messages (model still throttled after retries) were NOT recorded: run the same command again later and they are picked up as new.');
    }
    if ($paced !== null && $paced->retries > 0) {
        $say('The model throttled (429) ' . $paced->retries . ' time(s); each was backed off and retried.');
    }
} else {
    $say('Dry run only. To record and classify the new ones, run the same command with --apply (try --limit=10 first).');
}
exit(0);
