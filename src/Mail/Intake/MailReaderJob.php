<?php

declare(strict_types=1);

namespace TriCab\Api\Mail\Intake;

use Closure;
use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Database\DatabaseTemporarilyUnavailable;
use TriCab\Core\Mail\Intake\MailReaderPass;
use TriCab\Core\Mail\Intake\PassResult;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/**
 * Runs one {@see MailReaderPass} the way the WebJob needs it: under the pass lock, and answering with an
 * exit code the runbook (docs/runbooks/rfq-mail-reader.md) explains. Graph and database failures leave the
 * delta cursor where the last finished page put it.
 */
final class MailReaderJob
{
    /** A pass ran (or had nothing to do). */
    public const EXIT_OK = 0;

    /** An unexpected failure. The cursor was not moved past the failing page. */
    public const EXIT_FAILED = 1;

    /** Switched on but not configured: Graph settings, Foundry, or a mail store that is not the database. */
    public const EXIT_NOT_CONFIGURED = 2;

    /** Not running by design: RFQ_MAIL_MODE=off or RFQ_MAIL_READ_ENABLED=false. Nothing was read. */
    public const EXIT_PAUSED = 3;

    /** Microsoft Graph refused or could not be reached. The cursor was not moved. */
    public const EXIT_GRAPH = 4;

    /** Another pass holds the lock. Nothing was done. */
    public const EXIT_LOCKED = 5;

    /** The database is resuming (serverless tc). The cursor was not moved: the next pass retries. */
    public const EXIT_DATABASE_RESUMING = 75;

    /** @var Closure(string): void */
    private readonly Closure $out;

    /** @var Closure(string): void */
    private readonly Closure $err;

    /**
     * @param (callable(string): void)|null $out
     * @param (callable(string): void)|null $err
     */
    public function __construct(
        private readonly MailReaderPass $pass,
        private readonly string $stateDirectory,
        ?callable $out = null,
        ?callable $err = null,
    ) {
        $this->out = $out === null ? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        } : Closure::fromCallable($out);
        $this->err = $err === null ? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        } : Closure::fromCallable($err);
    }

    public function run(): int
    {
        $lock = FileMailReaderLock::acquire($this->stateDirectory);
        if ($lock === null) {
            ($this->err)('RFQ mail reader: another pass is running; this one does nothing.');

            return self::EXIT_LOCKED;
        }
        try {
            $result = $this->pass->run();
        } catch (DatabaseTemporarilyUnavailable) {
            ($this->err)('RFQ mail reader: the database is not available yet (usually the serverless database resuming). The delta cursor is unchanged; the next pass retries.');

            return self::EXIT_DATABASE_RESUMING;
        } catch (MailboxReadFailed $failure) {
            ($this->err)('RFQ mail reader: Microsoft Graph: ' . $failure->getMessage() . ' The delta cursor is unchanged.');

            return self::EXIT_GRAPH;
        } catch (Throwable $failure) {
            ($this->err)('RFQ mail reader: failed with ' . $failure::class . ': ' . mb_substr($failure->getMessage(), 0, 300) . ' The delta cursor is unchanged past the last finished page.');

            return self::EXIT_FAILED;
        } finally {
            $lock->release();
        }

        if ($result->status !== PassResult::DONE) {
            return self::EXIT_PAUSED;
        }
        // One line when something happened; an idle pass prints nothing, so the WebJob log stays readable.
        if ($result->received > 0 || $result->removed > 0 || $result->resynced) {
            try {
                ($this->out)(json_encode(['at' => gmdate('c')] + $result->toArray(), JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                // Counts only; cannot fail in practice.
            }
        }

        return self::EXIT_OK;
    }
}
