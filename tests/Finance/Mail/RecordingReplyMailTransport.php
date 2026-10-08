<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use Throwable;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceReplyMailTransport;

/**
 * A reply-capable transport that sends nothing: it remembers what it was given, standalone or as a reply, and can
 * be told to fall back (return false) or to fail. Used by the Tri-AR mail tests; nothing here reaches Graph.
 */
final class RecordingReplyMailTransport implements FinanceReplyMailTransport
{
    /** @var list<FinanceMailMessage> */
    public array $standalone = [];

    /** @var list<array{message: FinanceMailMessage, replyTo: string}> */
    public array $replies = [];

    public ?Throwable $failWith = null;

    /** What a reply reports: true for threaded, false for "went as a new message". */
    public bool $threads = true;

    public function deliver(FinanceMailMessage $message): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->standalone[] = $message;
    }

    public function deliverReply(FinanceMailMessage $message, string $replyToMessageId): bool
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->replies[] = ['message' => $message, 'replyTo' => $replyToMessageId];

        return $this->threads;
    }
}
