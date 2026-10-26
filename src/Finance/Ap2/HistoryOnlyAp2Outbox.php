<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2Outbox;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2OutboxMessage;

/**
 * For now the outbox sends nothing: it writes the message into the supplier's history (type `Email recorded (not
 * sent)`), and the use cases tell the person in the bell themselves. The in-app notification is not part of the
 * email, so it stays when the real sender arrives.
 *
 * TODO (sending round): replace this with the Microsoft Graph sender: send after the transaction commits, honour
 * FINANCE_AP2_SENDING_ENABLED and the test-recipient guard, and record the delivery in `finance.mail_deliveries`.
 */
final class HistoryOnlyAp2Outbox implements Ap2Outbox
{
    public function __construct(private readonly Ap2HistoryStore $history, private readonly ApTime $time)
    {
    }

    public function queue(Ap2OutboxMessage $message): void
    {
        $this->history->append(new Ap2HistoryEntry(
            bin2hex(random_bytes(16)),
            $message->supplierId,
            'Email recorded (not sent)',
            $message->kind . ' to ' . $message->to . ' · ' . $message->subject,
            $message->from,
            $this->time->now(),
            $message->invoiceId,
            $message->mailId,
            ['kind' => $message->kind, 'to' => $message->to, 'cc' => $message->cc, 'subject' => $message->subject, 'body' => $message->body, 'sent' => false],
        ));
    }
}
