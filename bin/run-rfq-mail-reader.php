<?php

declare(strict_types=1);

/**
 * RFQ mail reader (RFQ plan M3, M4): one pass over melbourne@'s Inbox, shadow mode.
 *
 *   php bin/run-rfq-mail-reader.php                 # one pass, then exit
 *   php bin/run-rfq-mail-reader.php --status        # what the reader remembers (no Graph, no database)
 *   php bin/run-rfq-mail-reader.php --reset-cursor  # forget the delta token; the next pass starts over
 *
 * The `rfq-mail` WebJob runs it every minute. A pass asks Graph for what changed in the Inbox and touches
 * the database only when Graph returns messages (and for an occasional heartbeat), so the serverless `tc`
 * can pause between mail. New messages are recorded in `[mail]` through MailIntakeRecorder, pre-filtered
 * without a model, classified by Claude on Foundry, and the result recorded. Shadow only: no drafts, no
 * notifications; with RFQ_MAIL_MODE=live and RFQ_MAIL_DRAFTS_ENABLED=true (RFQ M6, off by default) rfq mail also becomes a
 * Quotegen draft. Nothing runs unless RFQ_MAIL_READ_ENABLED=true and RFQ_MAIL_MODE is shadow or live.
 *
 * Exit codes (docs/runbooks/rfq-mail-reader.md):
 *    0  a pass ran, or had nothing to do
 *    1  unexpected failure (the delta cursor stays at the last finished page)
 *    2  switched on but not configured (RFQ_MAIL_GRAPH_*, Foundry, or the mail store is not the database)
 *    3  paused by design: RFQ_MAIL_MODE=off or RFQ_MAIL_READ_ENABLED=false
 *    4  Microsoft Graph refused or could not be reached (cursor unchanged)
 *    5  another pass holds the lock
 *   75  the database is resuming (cursor unchanged; the next pass retries)
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Assistant\Infrastructure\Foundry\ManagedIdentityFoundryAccessTokenProvider;
use TriCab\Api\Assistant\Infrastructure\Prompt\MarkdownPromptRepository;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Api\Infrastructure\Http\StreamJsonHttpClient;
use TriCab\Api\Mail\Intake\FileMailReaderLock;
use TriCab\Api\Mail\Intake\FileMailReaderState;
use TriCab\Api\Mail\Intake\FoundryMailClassifier;
use TriCab\Api\Mail\Intake\GraphMailDeltaSource;
use TriCab\Api\Mail\Intake\MailReaderJob;
use TriCab\Api\Mail\Notify\RfqMailNotificationStore;
use TriCab\Api\Mail\Notify\RfqMailNotifier;
use TriCab\Api\Mail\Notify\RfqMailRecipients;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Api\Shared\Mailbox\GraphMailboxReader;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Mail\Application\Service\MailDraftAutomation;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailStore;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Intake\MailReaderOptions;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Shared\Time\Clock;

$options = getopt('', ['status', 'reset-cursor', 'allow-memory']);

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
$state = FileMailReaderState::fromSettings($settings);

if (isset($options['status'])) {
    $connection = $state->connection();
    fwrite(STDOUT, json_encode([
        'stateDirectory' => $state->directory(),
        'hasCursor' => $state->cursor() !== null,
        'connection' => $connection['connection'] ?? null,
        'connectionAt' => isset($connection['at']) ? $connection['at']->format('c') : null,
        'readEnabled' => $settings->rfqMailReadEnabled,
        'mode' => $settings->rfqMailMode,
        'mailbox' => $settings->rfqMailMailbox,
        'graphConfigured' => $settings->isMailBodyReadingConfigured(),
        'classifierConfigured' => $settings->isRfqMailClassifierConfigured(),
        'draftsEnabled' => $settings->rfqMailDraftsEnabled,
        'draftsOn' => $settings->isRfqMailDraftsOn(),
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    exit(MailReaderJob::EXIT_OK);
}

if (isset($options['reset-cursor'])) {
    $lock = FileMailReaderLock::acquire($state->directory());
    if ($lock === null) {
        fwrite(STDERR, "RFQ mail reader: a pass is running; try again in a minute.\n");
        exit(MailReaderJob::EXIT_LOCKED);
    }
    $state->clearCursor();
    $lock->release();
    fwrite(STDOUT, "RFQ mail reader: delta cursor cleared. The next pass reads the last {$settings->rfqMailFirstRunDays} day(s); messages already recorded are not recorded twice.\n");
    exit(MailReaderJob::EXIT_OK);
}

if ($settings->rfqMailMode === Settings::RFQ_MAIL_MODE_OFF) {
    exit(MailReaderJob::EXIT_PAUSED);
}
if (!$settings->rfqMailReadEnabled) {
    // Paused. Only a reader that was once connected writes "paused" to the database (once); one that
    // was never switched on touches nothing.
    $connection = $state->connection();
    if ($connection === null || $connection['connection'] === 'paused') {
        exit(MailReaderJob::EXIT_PAUSED);
    }
}

// Platform settings (mailbox, classifier model) come from the cached copy, so a pass still leaves `tc`
// asleep. The database is read only when no copy exists yet.
try {
    $container = ContainerFactory::create($settings, hydratePlatformSettings: true, refreshPlatformSettings: false);
} catch (DatabaseTemporarilyUnavailable) {
    fwrite(STDERR, "RFQ mail reader: the database is not available yet (usually the serverless database resuming), so platform settings could not be read. The next pass retries.\n");
    exit(MailReaderJob::EXIT_DATABASE_RESUMING);
}
$settings = $container->get(Settings::class);

if ($settings->rfqMailReadEnabled) {
    $missing = [];
    if (!$settings->isMailBodyReadingConfigured()) {
        $missing[] = 'RFQ_MAIL_GRAPH_TENANT_ID / RFQ_MAIL_GRAPH_CLIENT_ID / RFQ_MAIL_GRAPH_CLIENT_SECRET';
    }
    if (!$settings->isRfqMailClassifierConfigured()) {
        $missing[] = 'FOUNDRY_ENABLED / FOUNDRY_ENDPOINT / RFQ_MAIL_CLASSIFIER_MODEL';
    }
    if ($missing !== []) {
        fwrite(STDERR, 'RFQ mail reader: switched on but not configured: ' . implode('; ', $missing) . ".\n");
        exit(MailReaderJob::EXIT_NOT_CONFIGURED);
    }
}

$store = $container->get(MailStore::class);
if (!$store instanceof MailStore) {
    fwrite(STDERR, "RFQ mail reader: the container has no mail store.\n");
    exit(MailReaderJob::EXIT_FAILED);
}
if ($store instanceof InMemoryMailStore && !isset($options['allow-memory'])) {
    // An in-memory store forgets everything at exit while the cursor moves on: mail would be lost.
    fwrite(STDERR, "RFQ mail reader: the mail store is in memory (USER_REPOSITORY is not azure-sql), so recording would be lost. Refusing.\n");
    exit(MailReaderJob::EXIT_NOT_CONFIGURED);
}

$http = new StreamJsonHttpClient(20.0, 5.0);
$graph = new GraphMailboxReader($http, $settings->rfqMailGraphTenantId, $settings->rfqMailGraphClientId, $settings->rfqMailGraphClientSecret);
$classifierHttp = new StreamJsonHttpClient((float) $settings->rfqMailClassifierTimeoutSeconds, 5.0);
$classifier = new FoundryMailClassifier(
    $classifierHttp,
    new ManagedIdentityFoundryAccessTokenProvider($classifierHttp),
    $settings->foundryEndpoint,
    $settings->rfqMailClassifierModel,
    $settings->foundryAuthMode,
    $settings->foundryApiKey,
    (new MarkdownPromptRepository(dirname(__DIR__) . '/PROMPTS'))->get('rfq-mail-classify', 'v1'),
);

// Drafts (RFQ M6) exist only with RFQ_MAIL_MODE=live AND RFQ_MAIL_DRAFTS_ENABLED=true. Otherwise no automation
// is built and the pass is the shadow pass, whatever else is set.
// New-email-RFQ notices (RFQ_MAIL_NOTIFY_ENABLED) ride on the same automation, so they exist only when drafts do.
$notifier = null;
if ($settings->isRfqMailDraftsOn() && $settings->rfqMailNotifyEnabled) {
    $mailboxUser = $container->get(UserRepository::class)->findByEmail(EmailAddress::fromString($settings->rfqMailMailbox));
    $notifier = new RfqMailNotifier(
        $container->get(RfqMailRecipients::class),
        $container->get(RfqMailNotificationStore::class),
        $container->get(Clock::class),
        $mailboxUser === null ? 0 : $mailboxUser->id()->toInt(),
    );
}
$drafts = $settings->isRfqMailDraftsOn()
    ? new MailDraftAutomation($store, $container->get(RfqDraftCreator::class), $settings->rfqMailMailbox, $container->get(Clock::class), $notifier)
    : null;

$pass = new MailReaderPass(
    new MailReaderOptions(
        $settings->rfqMailMailbox,
        $settings->rfqMailReadEnabled,
        $settings->rfqMailMode,
        $settings->rfqMailFirstRunDays,
        $settings->rfqMailPassSeconds,
        $settings->rfqMailHeartbeatMinutes,
    ),
    new GraphMailDeltaSource($graph),
    $graph,
    new MailIntakeRecorder($store),
    $store,
    $classifier,
    $state,
    $settings->rfqMailOwnAddresses,
    static function (string $line): void {
        fwrite(STDERR, '[' . gmdate('c') . '] ' . $line . "\n");
    },
    null,
    $drafts,
);

exit((new MailReaderJob($pass, $state->directory()))->run());
