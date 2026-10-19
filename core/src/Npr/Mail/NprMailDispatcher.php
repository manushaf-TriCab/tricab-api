<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Mail;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Shared\Time\Clock;

/**
 * Delivery of outbox rows (plan N2.4). Runs only after the business change has
 * committed, so a failure here never undoes it:
 *
 *  - {@see dispatchForRequest} is the in-request attempt after a transition;
 *    it swallows every failure (the row stays pending or failed and the wake
 *    file is signalled, so the sweep retries);
 *  - {@see sweep} is the retry pass: pending or failed rows with attempts left,
 *    spaced by a backoff of 1, 5, 15 minutes, 1 and 4 hours;
 *  - {@see retry} is the administrator's manual retry.
 *
 * Mode `off` sends nothing. `test` sends each message to the one test address
 * (never the real recipients, no Cc) with the intended recipients named in the
 * subject and body. A retry re-sends the stored snapshot; it never repeats a
 * workflow transition. Ambiguous provider outcomes are reported, not hidden:
 * a failed row may in rare cases already have been delivered.
 */
final class NprMailDispatcher
{
    /** Seconds to wait after attempt N (index 0: a fresh row, so an in-flight request is not raced). */
    private const BACKOFF = [120, 60, 300, 900, 3600, 14400];

    public function __construct(
        private readonly NprMailOutbox $outbox,
        private readonly NprMailTransport $transport,
        private readonly NprEmailTemplates $templates,
        private readonly NprMailMode $mode,
        private readonly string $testRecipient,
        private readonly Clock $clock,
        private readonly ?NprMailWake $wake = null,
    ) {
    }

    public function mode(): NprMailMode
    {
        return $this->mode;
    }

    /** After the commit of a transition: try this request's pending rows now. Never throws. */
    public function dispatchForRequest(string $requestPublicId): void
    {
        if ($this->mode === NprMailMode::Off) {
            return;
        }
        try {
            foreach ($this->outbox->pendingForRequest($requestPublicId) as $entry) {
                $this->deliver($entry);
            }
        } catch (Throwable $failure) {
            error_log('NPR mail: in-request delivery failed: ' . get_class($failure));
            $this->signal();
        }
    }

    /**
     * One retry pass.
     *
     * @return array{sent: int, failed: int, outstanding: int}
     */
    public function sweep(int $limit = 50): array
    {
        $sent = 0;
        $failed = 0;
        $now = $this->clock->now();
        foreach ($this->outbox->retryable($limit) as $entry) {
            if (!$this->isDue($entry, $now)) {
                continue;
            }
            $this->deliver($entry) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed, 'outstanding' => $this->outbox->outstanding()];
    }

    /**
     * Administrator retry: reopen the row and send it now.
     *
     * @return NprOutboxEntry|null null when there is no such row
     */
    public function retry(int $outboxId): ?NprOutboxEntry
    {
        if (!$this->outbox->reopen($outboxId)) {
            return null;
        }
        $entry = $this->outbox->find($outboxId);
        if ($entry !== null && $this->mode !== NprMailMode::Off) {
            $this->deliver($entry);
        }

        return $this->outbox->find($outboxId);
    }

    private function isDue(NprOutboxEntry $entry, DateTimeImmutable $now): bool
    {
        $reference = $entry->lastAttemptAt ?? $entry->createdAt;
        $delay = self::BACKOFF[min($entry->attempts, count(self::BACKOFF) - 1)];

        return $now->getTimestamp() >= $reference->getTimestamp() + $delay;
    }

    /** True when the message went out. */
    private function deliver(NprOutboxEntry $entry): bool
    {
        $now = $this->clock->now();
        try {
            $message = $this->message($entry);
            $this->transport->send($message);
        } catch (Throwable $failure) {
            $this->outbox->recordAttempt($entry->id, false, self::reason($failure), $now);
            $this->signal();

            return false;
        }
        $this->outbox->recordAttempt($entry->id, true, null, $now);

        return true;
    }

    /** @throws NprMailFailed */
    private function message(NprOutboxEntry $entry): NprMailMessage
    {
        if ($entry->to === [] && $entry->cc === []) {
            throw new NprMailFailed('No deliverable recipient: every required address was missing.');
        }
        $rendered = $this->templates->render($entry->event, $entry->payload);

        if ($this->mode === NprMailMode::Test) {
            if ($this->testRecipient === '') {
                throw new NprMailFailed('NPR_MAIL_MODE=test needs NPR_MAIL_TEST_RECIPIENT.');
            }
            $intended = implode(', ', [...$entry->to, ...array_map(static fn (string $a): string => 'cc ' . $a, $entry->cc)]);

            return new NprMailMessage(
                '[TEST] ' . $rendered['subject'],
                '<p style="font-size:12px;color:#b45309">Test mode. Intended for: ' . htmlspecialchars($intended, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' . $rendered['html'],
                'Test mode. Intended for: ' . $intended . "\n\n" . $rendered['text'],
                [$this->testRecipient],
                [],
            );
        }

        return new NprMailMessage($rendered['subject'], $rendered['html'], $rendered['text'], $entry->to, $entry->cc);
    }

    private function signal(): void
    {
        try {
            $this->wake?->signal();
        } catch (Throwable) {
            // Best effort: the idle check finds a lost signal.
        }
    }

    private static function reason(Throwable $failure): string
    {
        $text = $failure instanceof NprMailFailed ? $failure->getMessage() : 'Unexpected ' . get_class($failure);

        return mb_substr($text, 0, 500);
    }
}
