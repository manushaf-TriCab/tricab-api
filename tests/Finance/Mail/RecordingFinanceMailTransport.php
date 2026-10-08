<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use Throwable;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;

/**
 * A transport that sends nothing and remembers what it was given. It also records
 * the ledger's statuses at the moment of delivery, which is how a test proves the
 * pending row is written before anything is sent.
 */
final class RecordingFinanceMailTransport implements FinanceMailTransport
{
    /** @var list<FinanceMailMessage> */
    public array $sent = [];

    /** @var list<string> */
    public array $ledgerStatusesAtDelivery = [];

    public ?Throwable $failWith = null;

    public function __construct(private readonly ?InMemoryFinanceMailLedger $ledger = null)
    {
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
