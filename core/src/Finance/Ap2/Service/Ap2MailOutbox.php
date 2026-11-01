<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use Throwable;
use TriCab\Core\Finance\Ap2\Contract\Ap2DeferredOutbox;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2OutboxMessage;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailRules;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSender;

/**
 * The real outbox: approval requests, decision notices and shared histories go out from the AP mailbox through the
 * shared {@see FinanceMailSender} (so the grant, the ledger `finance.mail_deliveries` and the test-recipient guard all
 * apply). Bound only when FINANCE_AP2_SENDING_ENABLED is true; otherwise the history-only outbox is.
 *
 * Messages queued inside a transaction wait for it ({@see Ap2OutboxFlushingTransactions}): sent after the commit,
 * dropped on rollback. A message queued outside one is sent at once. A send that fails never undoes the work that
 * asked for it: the failure is written to the supplier's history ("Email not sent"), and the in-app notice stays.
 * The idempotency key is made from the message's kind and what it is about (the approval id), so queueing the same
 * request twice sends once.
 */
final class Ap2MailOutbox implements Ap2DeferredOutbox
{
    private int $depth = 0;

    /** @var list<Ap2OutboxMessage> */
    private array $held = [];

    public function __construct(
        private readonly FinanceMailSender $sender,
        private readonly Ap2HistoryStore $history,
        private readonly Ap2Time $time,
        private readonly string $mailbox,
    ) {
    }

    public function queue(Ap2OutboxMessage $message): void
    {
        if ($this->depth > 0) {
            $this->held[] = $message;

            return;
        }
        $this->deliver($message);
    }

    public function enter(): void
    {
        ++$this->depth;
    }

    public function leave(bool $committed): void
    {
        $this->depth = max(0, $this->depth - 1);
        if ($this->depth > 0) {
            return;
        }
        $held = $this->held;
        $this->held = [];
        if (!$committed) {
            return;
        }
        foreach ($held as $message) {
            $this->deliver($message);
        }
    }

    private function deliver(Ap2OutboxMessage $m): void
    {
        $to = Ap2SupplierMatcher::addresses($m->to);
        $cc = Ap2SupplierMatcher::addresses($m->cc);
        $key = 'ap2-' . strtolower(str_replace(' ', '-', $m->kind)) . ':' . ($m->reference ?? bin2hex(random_bytes(12)));
        $type = 'Email sent';
        $detail = $m->kind . ' to ' . $m->to . ' · ' . $m->subject;
        $extra = ['kind' => $m->kind, 'to' => $m->to, 'cc' => $m->cc, 'subject' => $m->subject, 'body' => $m->body, 'sent' => true];
        try {
            $result = $this->sender->send(new FinanceMailMessage(
                Module::APP_AP2,
                $this->mailbox,
                $to,
                $m->subject,
                '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px">' . nl2br(htmlspecialchars($m->body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</div>',
                $cc,
                [],
                FinanceMailRules::CONTENT_HTML,
                [],
                $key,
                $m->from->userId > 0 ? $m->from->userId : null,
            ));
            $extra['deliveryId'] = $result->delivery->id;
            if ($result->delivery->isSuppressed()) {
                $type = 'Email recorded (not sent)';
                $extra['sent'] = false;
            }
        } catch (Throwable $failure) {
            $type = 'Email not sent';
            $detail .= ' · ' . mb_substr($failure->getMessage(), 0, 300);
            $extra['sent'] = false;
            $extra['error'] = mb_substr($failure->getMessage(), 0, 300);
        }
        if ($m->supplierId === '') {
            return;
        }
        try {
            $this->history->append(new Ap2HistoryEntry(bin2hex(random_bytes(16)), $m->supplierId, $type, $detail, $m->from, $this->time->now(), $m->invoiceId, $m->mailId, $extra));
        } catch (Throwable) {
            // The ledger row in finance.mail_deliveries is the record; a history line must not break the caller after the commit.
        }
    }
}
