<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use Throwable;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMimeBuilder;
use TriCab\Core\Finance\Shared\Mail\FinanceMimeMailTransport;

/**
 * A transport that sends nothing and remembers what it was given. It also records
 * the ledger's statuses at the moment of delivery, which is how a test proves the
 * pending row is written before anything is sent.
 */
final class RecordingFinanceMailTransport implements FinanceMimeMailTransport
{
    /** @var list<FinanceMailMessage> */
    public array $sent = [];

    /** @var list<string> */
    public array $ledgerStatusesAtDelivery = [];

    public ?Throwable $failWith = null;

    public function __construct(private readonly ?InMemoryFinanceMailLedger $ledger = null)
    {
    }

    /** @var list<string> the MIME text of each message sent as MIME (also in `sent`) */
    public array $mime = [];

    /** A message with a calendar part, as the Graph transport would send it: recorded, and its MIME kept for assertions. */
    public function deliverMime(FinanceMailMessage $message): void
    {
        $this->deliver($message);
        $this->mime[] = FinanceMimeBuilder::build($message, $message->to, $message->cc, new \DateTimeImmutable('2026-10-25 01:00:00 UTC'), 'test-message-id', 'testboundary');
    }

    public function deliver(FinanceMailMessage $message): void
    {
        if ($this->ledger !== null) {
            $this->ledgerStatusesAtDelivery = array_map(static fn ($row): string => $row->status, $this->ledger->all());
        }
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->sent[] = $message;
    }
}
