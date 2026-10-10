<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Finance\Ap\InMemoryApEmailStore;
use TriCab\Api\Finance\Ap\InMemoryApOutboundStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Service\ApDraftMailService;
use TriCab\Core\Finance\Ap\Service\ApEmailService;
use TriCab\Core\Finance\Ap\Service\ApMailService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;

/**
 * The composer's services over the in-memory stores and a transport that sends nothing, with a fixed clock (2026-10-09 01:02:03 UTC).
 * `ap@example.test` (the configured mailbox, given as `AP@Example.test`) and `melissa@example.test` may be sent from; a read-only
 * grant, an inactive mailbox and another app's grant may not. No mail leaves the process.
 */
final class ApMailWorld
{
    public const FROM = 'ap@example.test';

    public ApInvoiceWorld $register;

    public InMemoryApEmailStore $emailStore;

    public ApEmailService $emails;

    public InMemoryApOutboundStore $outbound;

    public InMemoryFinanceMailLedger $ledger;

    public InMemoryFinanceMailboxRegistry $registry;

    public RecordingReplyMailTransport $transport;

    public ApMailService $service;

    public int $ids = 0;

    public function __construct(bool $sending = true, ?FinanceMailTransport $transport = null)
    {
        $this->register = new ApInvoiceWorld();
        $this->emailStore = new InMemoryApEmailStore($this->register->invoices);
        $time = new ApTime($this->register->clock, 'Australia/Melbourne');
        $this->emails = new ApEmailService($this->emailStore, $this->register->invoices, $this->register->audit, $time);
        $this->outbound = new InMemoryApOutboundStore();
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingReplyMailTransport();
        $this->registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', self::FROM, 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m2', 'melissa@example.test', '', 'Personal', true, ['AP' => ['read' => false, 'send' => true]]),
            new FinanceMailbox('m3', 'readonly@example.test', 'Read only', 'AP', true, ['AP' => ['read' => true, 'send' => false]]),
            new FinanceMailbox('m4', 'old@example.test', 'Retired', '', false, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m5', 'task@example.test', 'Tasks', '', true, ['TASK' => ['read' => false, 'send' => true]]),
        ]);
        $this->service = $this->serviceFor($sending, $transport);
    }

    public function serviceFor(bool $sending, ?FinanceMailTransport $transport = null): ApMailService
    {
        $n = 0;
        $mail = new FinanceMailService(
            $this->registry,
            $this->ledger,
            $transport ?? $this->transport,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-09 02:03:04', new DateTimeZone('UTC')),
            static function () use (&$n): string {
                return 'd' . str_pad((string) ++$n, 31, '0', STR_PAD_LEFT);
            },
        );

        return new ApMailService(
            $this->register->invoices,
            $this->emails,
            $this->register->attachments,
            $this->outbound,
            $this->register->audit,
            $mail,
            $this->ledger,
            $this->registry,
            new ApTime($this->register->clock, 'Australia/Melbourne'),
            'AP@Example.test',
            $sending,
            fn (): string => 'l' . str_pad((string) ++$this->ids, 31, '0', STR_PAD_LEFT),
        );
    }

    public function drafts(\TriCab\Core\Finance\Ap\ApAiService $ai): ApDraftMailService
    {
        return new ApDraftMailService($this->register->invoices, $this->emails, $ai);
    }

    /**
     * @param list<string>|null $mailboxes null for everyone
     */
    public function actor(?array $mailboxes = null): ApActor
    {
        return ApInvoiceFixtures::actor($mailboxes);
    }

    /** Stores an invoice and, with bytes, its document. */
    public function invoice(string $id, ?string $document = null, mixed ...$over): ApInvoice
    {
        return $this->register->stored(ApInvoiceFixtures::invoice($id, ...$over), $document);
    }

    /** Stores a scanned email in `ap@example.test` from `Accounts@Acme.example.test`. */
    public function email(string $id, mixed ...$over): ApEmail
    {
        $email = new ApEmail(
            $id,
            'graph-' . $id,
            self::FROM,
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            receivedAt: ApEmailWorld::utc('2026-10-05 01:00:00'),
            senderName: 'Vera Vendor',
            senderEmail: 'Accounts@Acme.example.test',
            subject: 'Where is our payment?',
            bodyPreview: 'We have not been paid.',
        );
        $email = $over === [] ? $email : $email->with(...$over);
        $this->emailStore->insert($email);

        return $email;
    }
}
