<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Ap2\HistoryOnlyAp2Outbox;
use TriCab\Api\Finance\Ap2\InMemoryAp2HistoryStore;
use TriCab\Api\Finance\Ap2\Ai\FakeAp2InvoiceExtractor;
use TriCab\Api\Finance\Ap2\InMemoryAp2InvoiceStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2MailStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PersonDirectory;
use TriCab\Api\Finance\Ap2\InMemoryAp2StatementStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2SupplierStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\GraphFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Finance\Mail\RecordingMethodHttpClient;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotProposal;
use TriCab\Core\Finance\Ap2\Ai\Ap2CopilotReply;
use TriCab\Core\Finance\Ap2\Ai\Ap2AiUnavailable;
use TriCab\Core\Finance\Ap2\Ai\Ap2ExtractedInvoice;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailClassification;
use TriCab\Core\Finance\Ap2\Ai\Ap2MailContext;
use TriCab\Core\Finance\Ap2\Ai\Ap2SupplierRef;
use TriCab\Core\Finance\Ap2\Contract\Ap2Copilot;
use TriCab\Core\Finance\Ap2\Contract\Ap2DraftWriter;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceExtractor;
use TriCab\Core\Finance\Ap2\Contract\Ap2MailClassifier;
use TriCab\Core\Finance\Ap2\Contract\Ap2Outbox;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Mail;
use TriCab\Core\Finance\Ap2\Domain\Ap2MailExtraction;
use TriCab\Core\Finance\Ap2\Domain\Ap2OutboxMessage;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;
use TriCab\Core\Finance\Ap2\Service\Ap2ApprovalService;
use TriCab\Core\Finance\Ap2\Service\Ap2MailOutbox;
use TriCab\Core\Finance\Ap2\Service\Ap2MailService;
use TriCab\Core\Finance\Ap2\Service\Ap2MailSyncJob;
use TriCab\Core\Finance\Ap2\Service\Ap2MailSyncService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * Tri-AP2 Phases 3 and 4: the mail sync, the inbox routes, drafts, sending, create-invoice and the AI interfaces, over the
 * in-memory stores with a fake reader and fake transports. Nothing here reaches Graph, a database or a real mailbox.
 */
final class Ap2MailTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap2';

    private const MAILBOX = 'ap@example.test';

    /** @var App<ContainerInterface> */
    private App $app;

    private Container $c;

    private InMemoryMailboxReader $reader;

    private RecordingReplyMailTransport $transport;

    protected function setUp(): void
    {
        $this->boot();
    }

    /**
     * @param array<string, string> $env
     */
    private function boot(array $env = [], bool $grantRead = true): void
    {
        $this->app = TestAppFactory::create(array_merge(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true', 'FINANCE_AP_MAILBOX' => self::MAILBOX, 'FINANCE_AP2_SENDING_ENABLED' => 'true'], $env));
        $c = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $c);
        $this->c = $c;
        $this->reader = new InMemoryMailboxReader();
        $this->transport = new RecordingReplyMailTransport();
        $this->c->set(MailboxReader::class, $this->reader);
        $this->c->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', self::MAILBOX, 'Accounts Payable', 'AP', true, ['AP2' => ['read' => $grantRead, 'send' => true], 'AP' => ['read' => true, 'send' => true]]),
        ]));
        $this->c->set(FinanceMailTransport::class, $this->transport);
        $users = $this->c->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_AP2_ACCESS, ...Module::AP2_PERMISSIONS], ['EMPLOYEE']);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function get(string $class): object
    {
        $service = $this->c->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @template T of object
     * @param class-string $contract
     * @param class-string<T> $class
     * @return T
     */
    private function store(string $contract, string $class): object
    {
        $store = $this->c->get($contract);
        self::assertInstanceOf($class, $store);

        return $store;
    }

    private function mails(): InMemoryAp2MailStore
    {
        return $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2MailStore::class, InMemoryAp2MailStore::class);
    }

    private function supplier(string $id = 'S001', string $name = 'Acme Cables', string $email = 'billing@acme.test'): Ap2Supplier
    {
        $s = Ap2Fixtures::supplier($id, $name)->with(email: $email);
        $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore::class, InMemoryAp2SupplierStore::class)->insert($s);

        return $s;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $files name, type, bytes
     * @param 'html'|'text' $type
     */
    private function inbox(string $id, string $from, string $subject, string $body = 'Hello', array $files = [], string $type = MailboxMessage::BODY_TEXT, string $cc = '', string $when = '-2 hours'): void
    {
        $summary = new MailboxMessageSummary($id, $subject, $from, 'Sender', new DateTimeImmutable($when, new \DateTimeZone('UTC')), $files !== [], mb_substr($body, 0, 50));
        $attachments = [];
        foreach ($files as $i => [$name, $contentType, $bytes]) {
            $attachments[] = new MailboxAttachment(new MailboxAttachmentSummary($id . '-a' . $i, $name, $contentType, strlen($bytes)), $bytes);
        }
        $this->reader->add(self::MAILBOX, new MailboxMessage($summary, $type, $body, [new MailboxRecipient(self::MAILBOX)], $cc === '' ? [] : [new MailboxRecipient($cc), new MailboxRecipient(self::MAILBOX)]), $attachments);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{fetched: int, created: int, skipped: int, truncated: bool}
     */
    private function sync(array $body = []): array
    {
        return $this->get(Ap2MailSyncService::class)->sync(Ap2Fixtures::actor(), $body);
    }

    /** @return list<Ap2Mail> */
    private function stored(): array
    {
        return $this->mails()->all();
    }

    private function refused(callable $call, int $status): Ap2Failure
    {
        try {
            $call();
        } catch (Ap2Failure $failure) {
            self::assertSame($status, $failure->httpStatus(), $failure->getMessage() . ' ' . json_encode($failure->extra));

            return $failure;
        }
        self::fail('Expected a refusal with ' . $status);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    // ---------------------------------------------------------------- sync

    public function test_sync_stores_new_mail_and_dedupes_on_the_second_run(): void
    {
        $this->supplier();
        $this->inbox('g1', 'Billing@Acme.test', 'Invoice 100', '<p>Please <b>pay</b></p><script>x()</script><br>Thanks &amp; regards', [['inv.pdf', 'application/pdf', '%PDF-1.4 one']], MailboxMessage::BODY_HTML, 'accounts@acme.test');
        $this->inbox('g2', 'someone@else.test', 'Hello');

        self::assertSame(['fetched' => 2, 'created' => 2, 'skipped' => 0, 'truncated' => false], $this->sync());
        self::assertSame(['fetched' => 2, 'created' => 0, 'skipped' => 2, 'truncated' => false], $this->sync(), 'the same messages are not stored twice');
        self::assertCount(2, $this->stored());

        $mail = $this->mails()->findMail($this->idOf('Invoice 100'));
        self::assertNotNull($mail);
        self::assertSame("Please pay\n\nThanks & regards", $mail->body);
        self::assertStringNotContainsString('x()', $mail->body);
        self::assertSame('billing@acme.test', $mail->from);
        self::assertSame('billing@acme.test', $mail->replyTo);
        self::assertSame('accounts@acme.test', $mail->cc, 'the original CC, without our own mailbox');
        self::assertSame(self::MAILBOX, $mail->mailbox);
        self::assertSame('g1', $mail->graphMessageId);
        self::assertSame('Supplier invoice', $mail->category, 'no AI: the rule-based guess (PDF + "invoice")');
        self::assertSame(0.3, $mail->aiConfidence);
        self::assertStringStartsWith('Rule-based guess', (string) $mail->aiSummary);
        $other = $this->mails()->findMail($this->idOf('Hello'));
        self::assertNotNull($other);
        self::assertSame('Other', $other->category);
        self::assertFalse($mail->read);
        $files = $this->mails()->attachmentsOf($mail->id);
        self::assertCount(1, $files);
        self::assertSame('inv.pdf', $files[0]->name);
        self::assertNotNull($files[0]->fileId);
    }

    private function idOf(string $subject): string
    {
        foreach ($this->stored() as $m) {
            if ($m->subject === $subject) {
                return $m->id;
            }
        }
        self::fail('No mail with subject ' . $subject);
    }

    public function test_the_supplier_is_matched_by_address_then_by_domain_and_a_tie_is_no_match(): void
    {
        $acme = $this->supplier('S001', 'Acme Cables', 'billing@acme.test');
        $this->supplier('S002', 'Beta Wires', 'ar@beta.test');
        $this->supplier('S003', 'Beta Twin', 'sales@beta.test');
        $this->inbox('g1', 'billing@acme.test', 'exact');
        $this->inbox('g2', 'someone.new@acme.test', 'domain');
        $this->inbox('g3', 'x@mail.acme.test', 'subdomain');
        $this->inbox('g4', 'who@beta.test', 'tie');
        $this->inbox('g5', 'person@gmail.com', 'free mail');
        $this->sync();

        self::assertSame($acme->id, $this->mails()->findMail($this->idOf('exact'))->supplierId ?? null);
        self::assertSame($acme->id, $this->mails()->findMail($this->idOf('domain'))->supplierId ?? null);
        self::assertSame($acme->id, $this->mails()->findMail($this->idOf('subdomain'))->supplierId ?? null);
        self::assertNull($this->mails()->findMail($this->idOf('tie'))?->supplierId, 'two suppliers share the domain: a person decides');
        self::assertNull($this->mails()->findMail($this->idOf('free mail'))?->supplierId);
        $history = $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->forSupplier($acme->id);
        self::assertCount(3, $history);
        self::assertSame('Email received', $history[0]->type);
    }

    public function test_the_classifier_sets_the_category_and_only_fills_a_supplier_the_address_did_not_find(): void
    {
        $acme = $this->supplier('S001', 'Acme Cables', 'billing@acme.test');
        $beta = $this->supplier('S002', 'Beta Wires', 'ar@beta.test');
        $this->c->set(Ap2MailClassifier::class, new class ($beta->id) implements Ap2MailClassifier {
            public function __construct(private readonly string $supplierId)
            {
            }

            public function classify(string $subject, string $from, string $bodyText, array $attachmentNames, array $suppliers): Ap2MailClassification
            {
                return new Ap2MailClassification($subject === 'weird' ? 'Not a category' : 'Credit note', $this->supplierId, 0.9, 'A credit note for Beta');
            }
        });
        $this->inbox('g1', 'billing@acme.test', 'matched by address');
        $this->inbox('g2', 'unknown@nowhere.test', 'from an unknown sender');
        $this->inbox('g3', 'unknown@nowhere.test', 'weird');
        $this->sync();

        $one = $this->mails()->findMail($this->idOf('matched by address'));
        self::assertSame($acme->id, $one->supplierId ?? null, 'the address wins over the AI');
        self::assertSame('Credit note', $one->category ?? '');
        self::assertSame(0.9, $one->aiConfidence ?? 0.0);
        self::assertSame('A credit note for Beta', $one->aiSummary ?? '');
        self::assertSame($beta->id, $this->mails()->findMail($this->idOf('from an unknown sender'))->supplierId ?? null);
        self::assertSame('Other', $this->mails()->findMail($this->idOf('weird'))->category ?? '', 'an invented category is ignored');
    }

    public function test_the_sync_reads_through_the_shared_reader_with_the_ap2_key_and_audits_each_read(): void
    {
        $audit = new InMemoryFinanceAuditLog();
        $this->c->set(\TriCab\Core\Finance\Shared\Audit\FinanceAuditLog::class, $audit);
        $this->inbox('g1', 'a@b.test', 'One', 'x', [['f.pdf', 'application/pdf', 'bytes']]);
        $this->sync();

        $actions = array_map(static fn ($e): string => $e->action . '/' . $e->application, $audit->events);
        self::assertContains('finance.mail.messages_listed/AP2', $actions);
        self::assertContains('finance.mail.message_read/AP2', $actions);
        self::assertContains('finance.mail.attachment_read/AP2', $actions);
        self::assertSame([], array_filter($actions, static fn (string $a): bool => !str_ends_with($a, '/AP2')), 'every read is the AP2 application');
    }

    public function test_a_sync_without_a_read_grant_is_refused_and_recorded_as_failed(): void
    {
        $this->boot([], false);
        $this->inbox('g1', 'a@b.test', 'One');
        $this->refused(fn () => $this->sync(), 409);

        $runs = array_values($this->mails()->runs);
        self::assertCount(1, $runs);
        self::assertSame('Failed', $runs[0]->status);
        self::assertSame([], $this->stored());
    }

    public function test_a_successful_run_is_recorded_and_the_window_is_checked(): void
    {
        $this->inbox('g1', 'a@b.test', 'One');
        $this->sync();
        $runs = array_values($this->mails()->runs);
        self::assertSame('Succeeded', $runs[0]->status);
        self::assertSame([1, 1, 0], [$runs[0]->fetched, $runs[0]->created, $runs[0]->skipped]);
        self::assertSame(1, $runs[0]->startedByUserId === null ? 0 : 1);

        $this->refused(fn () => $this->sync(['from' => '2026-01-01', 'to' => '2026-06-01']), 422);
        $this->refused(fn () => $this->sync(['from' => '2026-10-02', 'to' => '2026-10-01']), 422);
        $this->refused(fn () => $this->sync(['from' => 'yesterday']), 422);
    }

    public function test_sync_never_touches_the_mailbox_beyond_reading(): void
    {
        $this->inbox('g1', 'a@b.test', 'One');
        $this->sync();
        self::assertSame([], array_values(array_filter(get_class_methods($this->reader), static fn (string $m): bool => preg_match('/move|flag|markRead|delete|patch/i', $m) === 1)));
    }

    public function test_the_scheduled_job_is_registered_only_when_its_switch_is_on(): void
    {
        $names = static function (ContainerInterface $c): array {
            $registry = $c->get(FinanceJobRegistry::class);
            self::assertInstanceOf(FinanceJobRegistry::class, $registry);

            return array_map(static fn ($j): string => $j->name(), $registry->all());
        };
        self::assertNotContains(Ap2MailSyncJob::NAME, $names($this->c));

        $this->boot(['FINANCE_AP2_MAIL_SYNC_ENABLED' => 'true']);
        self::assertNotContains(Ap2MailSyncJob::NAME, $names($this->c), 'the switch alone is not enough: mailbox reading must be on too');

        $this->boot(['FINANCE_AP2_MAIL_SYNC_ENABLED' => 'true', 'FINANCE_MAILBOX_READ_ENABLED' => 'true', 'FINANCE_GRAPH_TENANT_ID' => 't', 'FINANCE_GRAPH_CLIENT_ID' => 'c', 'FINANCE_GRAPH_CLIENT_SECRET' => 's']);
        self::assertContains(Ap2MailSyncJob::NAME, $names($this->c));
    }

    // ---------------------------------------------------------------- routes and edits

    private function mailWith(string $subject = 'Where is my money', string $from = 'billing@acme.test', bool $supplier = true): Ap2Mail
    {
        if ($supplier) {
            $this->supplier();
        }
        $this->inbox('g-' . md5($subject), $from, $subject, 'Body text', [['one.pdf', 'application/pdf', '%PDF one'], ['two.png', 'image/png', 'PNGBYTES']]);
        $this->sync();
        $mail = $this->mails()->findMail($this->idOf($subject));
        self::assertNotNull($mail);

        return $mail;
    }

    /**
     * @param array{0: int, 1: array<string, mixed>} $result
     * @return array<array-key, mixed>
     */
    private function ok(array $result, int $status = 200): array
    {
        self::assertSame($status, $result[0], json_encode($result[1], JSON_THROW_ON_ERROR));
        $data = $result[1]['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    public function test_the_routes_list_by_tab_and_date_and_open_marks_read(): void
    {
        $a = $this->mailWith('First');
        $b = $this->mailWith('Second');
        $this->ok($this->call('PATCH', '/mail/' . $b->id, ['status' => 'Done']));

        self::assertCount(2, $this->ok($this->call('GET', '/mail')));
        self::assertCount(1, $this->ok($this->call('GET', '/mail?tab=progress')));
        self::assertCount(1, $this->ok($this->call('GET', '/mail?tab=done')));
        self::assertCount(0, $this->ok($this->call('GET', '/mail?tab=hold')));
        $today = (new DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->format('Y-m-d');
        self::assertCount(2, $this->ok($this->call('GET', '/mail?from=' . $today . '&to=' . $today)), 'received two hours ago');
        self::assertCount(0, $this->ok($this->call('GET', '/mail?from=2020-01-01&to=2020-01-02')));
        self::assertSame(422, $this->call('GET', '/mail?tab=nope')[0]);

        $list = $this->ok($this->call('GET', '/mail?tab=progress'));
        $row = $list[0];
        self::assertIsArray($row);
        self::assertArrayNotHasKey('body', $row);
        self::assertSame($a->id, $row['id']);
        self::assertFalse($row['read']);
        $detail = $this->ok($this->call('GET', '/mail/' . $a->id));
        self::assertSame('Body text', $detail['body']);
        self::assertTrue($detail['read']);
        self::assertCount(2, Ap2Json::arr($detail, 'attachments'));
        self::assertSame(['id', 'name', 'size', 'contentType'], array_keys(Ap2Json::arr($detail, 'attachments.0')));
        self::assertSame(404, $this->call('GET', '/mail/' . md5('missing'))[0]);
    }

    public function test_the_attachment_file_is_served_and_unknown_ones_are_404(): void
    {
        $mail = $this->mailWith();
        $aid = $this->mails()->attachmentsOf($mail->id)[0]->id;
        $response = $this->app->handle(TestAppFactory::request('GET', self::BASE . '/mail/' . $mail->id . '/attachments/' . $aid . '/file'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('%PDF one', (string) $response->getBody());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('one.pdf', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        self::assertSame(404, $this->call('GET', '/mail/' . $mail->id . '/attachments/' . md5('nope') . '/file')[0]);
        self::assertSame(404, $this->call('GET', '/mail/' . md5('nope') . '/attachments/' . $aid . '/file')[0]);
    }

    public function test_draft_reviewed_is_cleared_when_the_draft_to_cc_supplier_or_attachments_change(): void
    {
        $mail = $this->mailWith();
        $other = $this->supplier('S002', 'Beta Wires', 'ar@beta.test');
        $a1 = $this->mails()->attachmentsOf($mail->id)[0]->id;
        $a2 = $this->mails()->attachmentsOf($mail->id)[1]->id;
        $path = '/mail/' . $mail->id;

        $d = $this->ok($this->call('PATCH', $path, ['draft' => 'Hi', 'draftReviewed' => true]));
        self::assertFalse($d['draftReviewed'], 'a change and a review in the same save is not a review');
        $d = $this->ok($this->call('PATCH', $path, ['draftReviewed' => true]));
        self::assertTrue($d['draftReviewed']);
        $d = $this->ok($this->call('PATCH', $path, ['draft' => 'Hi', 'draftReviewed' => true]));
        self::assertTrue($d['draftReviewed'], 'the same draft is no change');

        foreach ([['draft' => 'Hi again'], ['replyTo' => 'new@acme.test'], ['cc' => 'boss@acme.test'], ['draftAttachmentIds' => [$a1]]] as $change) {
            $this->ok($this->call('PATCH', $path, ['draftReviewed' => true]));
            $d = $this->ok($this->call('PATCH', $path, $change));
            self::assertFalse($d['draftReviewed'], json_encode($change) . ' clears the review');
        }
        self::assertSame([$a1], $d['draftAttachmentIds']);

        $d = $this->ok($this->call('PATCH', $path, ['draftAttachmentIds' => [$a1, $a2], 'draftReviewed' => false]));
        $this->ok($this->call('PATCH', $path, ['draftReviewed' => true]));
        $d = $this->ok($this->call('PATCH', $path, ['draftAttachmentIds' => [$a2]]));
        self::assertFalse($d['draftReviewed'], 'removing a chip clears the review');
        $log = array_map(static fn ($e): string => $e->type, $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->forSupplier($mail->supplierId ?? ''));
        self::assertContains('Draft attachment removed', $log);

        $this->mails()->linkApproval($mail->id, md5('approval'));
        $this->ok($this->call('PATCH', $path, ['draftReviewed' => true]));
        $d = $this->ok($this->call('PATCH', $path, ['supplierId' => $other->id]));
        self::assertFalse($d['draftReviewed']);
        self::assertSame([], $d['draftAttachmentIds'], 'a new supplier clears the attachments');
        self::assertNull($d['approvalId'], 'and the approval');
        self::assertSame($other->id, $d['supplierId']);

        $d = $this->ok($this->call('PATCH', $path, ['supplierId' => null]));
        self::assertNull($d['supplierId']);
    }

    public function test_patch_validates_and_sets_owner_category_and_status(): void
    {
        $mail = $this->mailWith();
        $this->get(InMemoryAp2PersonDirectory::class);
        $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory::class, InMemoryAp2PersonDirectory::class)->add(new Ap2Person(9, 'Priya Shah', 'priya@tricab.com'), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_INVOICES_WRITE]);
        $path = '/mail/' . $mail->id;

        $d = $this->ok($this->call('PATCH', $path, ['category' => 'Supplier statement', 'status' => 'On hold', 'ownerUserId' => 9]));
        self::assertSame(['Supplier statement', 'On hold', 9], [$d['category'], $d['status'], Ap2Json::get($d, 'owner.userId')]);
        $d = $this->ok($this->call('PATCH', $path, ['ownerUserId' => null]));
        self::assertNull($d['owner']);

        self::assertSame(422, $this->call('PATCH', $path, ['category' => 'Nope'])[0]);
        self::assertSame(422, $this->call('PATCH', $path, ['status' => 'Nope'])[0]);
        self::assertSame(422, $this->call('PATCH', $path, ['ownerUserId' => 4242])[0]);
        self::assertSame(422, $this->call('PATCH', $path, ['supplierId' => md5('nobody')])[0]);
        self::assertSame(422, $this->call('PATCH', $path, ['cc' => 'not an address'])[0]);
        self::assertSame(422, $this->call('PATCH', $path, ['draftAttachmentIds' => [md5('not mine')]])[0]);
        self::assertSame(422, $this->call('PATCH', $path, ['draftReviewed' => true])[0], 'there is nothing to review');
        self::assertSame(404, $this->call('PATCH', '/mail/' . md5('x'), ['status' => 'Done'])[0]);
    }

    // ---------------------------------------------------------------- AI interfaces

    public function test_draft_and_copilot_answer_503_while_no_ai_is_connected(): void
    {
        $mail = $this->mailWith();
        [$status, $json] = $this->call('POST', '/mail/' . $mail->id . '/draft', ['instruction' => 'be polite']);
        self::assertSame(503, $status);
        self::assertSame('FINANCE_AP2_AI_UNAVAILABLE', Ap2Json::str($json, 'error.code'));
        [$status, $json] = $this->call('POST', '/copilot', ['message' => 'hello', 'mailId' => $mail->id]);
        self::assertSame(503, $status);
        self::assertSame('FINANCE_AP2_AI_UNAVAILABLE', Ap2Json::str($json, 'error.code'));

        self::assertSame(422, $this->call('POST', '/copilot', [])[0], 'a question is needed before the AI is even asked');
        self::assertSame(422, $this->call('POST', '/copilot', ['message' => 'x', 'mailId' => md5('nope')])[0]);
        self::assertSame(404, $this->call('POST', '/mail/' . md5('nope') . '/draft', [])[0]);
        self::assertFalse(Ap2Json::get($this->ok($this->call('GET', '/me')), 'flags.ai'));
    }

    public function test_a_connected_ai_is_used_and_switches_the_me_flag_on(): void
    {
        $mail = $this->mailWith();
        $this->c->set(Ap2DraftWriter::class, new class implements Ap2DraftWriter {
            public function draft(Ap2MailContext $mail, ?Ap2SupplierRef $supplier, ?array $invoice, array $validationIssues, ?string $instruction): string
            {
                return 'Dear ' . ($supplier->name ?? 'supplier') . ', re ' . $mail->subject . ' (' . ($instruction ?? 'no steer') . ') ' . count($mail->attachmentNames) . ' files';
            }
        });
        $this->c->set(Ap2Copilot::class, new class implements Ap2Copilot {
            public function respond(array $context, string $message, array $history): Ap2CopilotReply
            {
                return new Ap2CopilotReply('You said: ' . $message . ' (' . count($history) . ' earlier)', [
                    new Ap2CopilotProposal('apply_draft', 'Use this draft', ['mailId' => $context['mailId'] ?? '', 'draft' => 'Hi']),
                    new Ap2CopilotProposal('delete_everything', 'Nope', []),
                ]);
            }
        });

        $draft = $this->ok($this->call('POST', '/mail/' . $mail->id . '/draft', ['instruction' => 'ask for the PO']));
        self::assertSame('Dear Acme Cables, re Where is my money (ask for the PO) 2 files', $draft['draft']);
        self::assertSame('', $this->mails()->findMail($mail->id)->draft ?? 'x', 'a draft is returned, never saved');

        $answer = $this->ok($this->call('POST', '/copilot', ['message' => 'hi', 'mailId' => $mail->id, 'history' => [['role' => 'user', 'content' => 'a'], ['role' => 'assistant', 'content' => 'b']]]));
        self::assertSame('You said: hi (2 earlier)', $answer['reply']);
        self::assertCount(1, Ap2Json::arr($answer, 'proposals'), 'an unknown proposal type is dropped');
        self::assertSame('apply_draft', Ap2Json::get($answer, 'proposals.0.type'));
        self::assertSame(422, $this->call('POST', '/copilot', ['message' => 'hi', 'history' => [['role' => 'robot', 'content' => 'a']]])[0]);
    }

    public function test_the_ai_flag_follows_what_is_bound(): void
    {
        $this->c->set(Ap2InvoiceExtractor::class, new class implements Ap2InvoiceExtractor {
            public function extract(string $bytes, string $contentType, string $fileName, ?string $supplierHint): Ap2ExtractedInvoice
            {
                return Ap2ExtractedInvoice::none();
            }
        });
        self::assertTrue(Ap2Json::get($this->ok($this->call('GET', '/me')), 'flags.ai'));
    }

    // ---------------------------------------------------------------- create-invoice and files

    public function test_create_invoice_links_both_ways_and_serves_the_document(): void
    {
        $mail = $this->mailWith();
        $invoice = $this->ok($this->call('POST', '/mail/' . $mail->id . '/create-invoice'), 201);

        self::assertSame($mail->id, $invoice['mailId']);
        self::assertSame('Needs review', $invoice['queue'], 'the Null extractor read nothing');
        self::assertSame('one.pdf', $invoice['sourceDoc']);
        self::assertSame(hash('sha256', '%PDF one'), $invoice['sourceFingerprint']);
        self::assertNotNull($invoice['sourceFileId']);
        self::assertSame($mail->supplierId, $invoice['supplierId']);
        self::assertSame($invoice['id'], Ap2Json::get($this->ok($this->call('GET', '/mail/' . $mail->id)), 'invoiceId'));

        $response = $this->app->handle(TestAppFactory::request('GET', self::BASE . '/invoices/' . Ap2Json::str($invoice, 'id') . '/file'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('%PDF one', (string) $response->getBody());

        $again = $this->call('POST', '/mail/' . $mail->id . '/create-invoice');
        self::assertSame(409, $again[0], 'one invoice per email');
    }

    public function test_create_invoice_uses_what_the_extractor_found(): void
    {
        $this->c->set(Ap2InvoiceExtractor::class, new class implements Ap2InvoiceExtractor {
            public function extract(string $bytes, string $contentType, string $fileName, ?string $supplierHint): Ap2ExtractedInvoice
            {
                return new Ap2ExtractedInvoice(number: 'INV-77', issued: '2026-10-01', due: '2026-10-31', currency: 'NZD', net: 100.0, tax: 10.0, total: 110.0, po: 'PO-1', quantity: 5.0, poUnitPrice: 20.0, overall: 0.8);
            }
        });
        $mail = $this->mailWith();
        $invoice = $this->ok($this->call('POST', '/mail/' . $mail->id . '/create-invoice'), 201);

        self::assertSame(['INV-77', 'NZD', 110, 'Goods PO', 'PO-1', 0.8], [$invoice['number'], $invoice['currency'], $invoice['total'], $invoice['kind'], $invoice['po'], $invoice['confidence']]);
        self::assertFalse($invoice['verified'], 'a person still verifies');
    }

    public function test_create_invoice_needs_a_supplier_and_a_document(): void
    {
        $noSupplier = $this->mailWith('No supplier', 'x@nowhere.test', false);
        $this->refused(fn () => $this->get(Ap2MailService::class)->createInvoice(Ap2Fixtures::actor(), $noSupplier->id), 422);

        $this->supplier();
        $this->inbox('g-plain', 'billing@acme.test', 'No file', 'text', [['notes.txt', 'text/plain', 'hello']]);
        $this->sync();
        $this->refused(fn () => $this->get(Ap2MailService::class)->createInvoice(Ap2Fixtures::actor(), $this->idOf('No file')), 409);
        $this->refused(fn () => $this->get(Ap2MailService::class)->createInvoice(Ap2Fixtures::actor(), md5('missing')), 404);
    }

    private function fakeExtractor(): FakeAp2InvoiceExtractor
    {
        $fake = new FakeAp2InvoiceExtractor();
        $fake->next = new Ap2ExtractedInvoice(number: 'INV-9', issued: '2026-10-01', currency: 'AUD', net: 100.0, tax: 10.0, total: 110.0, confidence: ['number' => 0.9, 'total' => 0.8], overall: 0.85);
        $this->c->set(Ap2InvoiceExtractor::class, $fake);

        return $fake;
    }

    public function test_the_sync_stores_the_extraction_of_the_first_pdf_of_a_supplier_invoice_and_creates_no_invoice(): void
    {
        $fake = $this->fakeExtractor();
        $supplier = $this->supplier();
        $this->inbox('g1', 'billing@acme.test', 'Invoice 100', 'Please pay', [['pic.png', 'image/png', 'PNG'], ['a.pdf', 'application/pdf', '%PDF a'], ['b.pdf', 'application/pdf', '%PDF b']]);
        $this->sync();

        $mail = $this->mails()->findMail($this->idOf('Invoice 100'));
        self::assertNotNull($mail);
        self::assertSame('Supplier invoice', $mail->category);
        self::assertCount(1, $fake->calls);
        self::assertSame(['application/pdf', 'a.pdf', 'Acme Cables'], [$fake->calls[0]['contentType'], $fake->calls[0]['fileName'], $fake->calls[0]['supplierHint']]);
        $extraction = $mail->extraction;
        self::assertNotNull($extraction);
        $files = $this->mails()->attachmentsOf($mail->id);
        self::assertSame($files[1]->id, $extraction->attachmentId, 'the first PDF');
        self::assertSame(['a.pdf', $supplier->id, 'Acme Cables'], [$extraction->fileName, $extraction->supplierId, $extraction->supplierName]);
        self::assertSame('INV-9', $extraction->read->number);
        self::assertNull($mail->invoiceId);
        self::assertSame([], $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->all(), 'a suggestion only');
    }

    public function test_the_sync_does_not_extract_for_other_categories_no_pdf_or_no_supplier(): void
    {
        $fake = $this->fakeExtractor();
        $this->supplier();
        $this->inbox('g1', 'billing@acme.test', 'Hello there', 'Hi', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->inbox('g2', 'billing@acme.test', 'Invoice 2', 'Please pay', [['a.png', 'image/png', 'PNG']]);
        $this->inbox('g3', 'stranger@nowhere.test', 'Invoice 3', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->sync();

        self::assertSame([], $fake->calls);
        foreach ($this->stored() as $m) {
            self::assertNull($m->extraction);
        }
    }

    public function test_an_extractor_failure_or_an_empty_reading_never_fails_the_sync(): void
    {
        $fake = $this->fakeExtractor();
        $this->supplier();
        $this->inbox('g1', 'billing@acme.test', 'Invoice 100', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->inbox('g2', 'billing@acme.test', 'Invoice 200', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $fake->failWith = new Ap2AiUnavailable('down');
        self::assertSame(['fetched' => 2, 'created' => 2, 'skipped' => 0, 'truncated' => false], $this->sync());
        self::assertCount(2, $fake->calls);
        self::assertNull($this->mails()->findMail($this->idOf('Invoice 100'))?->extraction);

        $fake->failWith = new \RuntimeException('boom');
        $this->inbox('g3', 'billing@acme.test', 'Invoice 300', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        self::assertSame(1, $this->sync()['created']);

        $fake->failWith = null;
        $fake->next = null;
        $this->inbox('g4', 'billing@acme.test', 'Invoice 400', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->sync();
        self::assertNull($this->mails()->findMail($this->idOf('Invoice 400'))?->extraction, 'nothing read, nothing stored');
    }

    public function test_create_invoice_reuses_the_stored_extraction_without_calling_the_extractor(): void
    {
        $fake = $this->fakeExtractor();
        $this->supplier();
        $this->inbox('g1', 'billing@acme.test', 'Invoice 100', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->sync();
        self::assertCount(1, $fake->calls);

        $mail = $this->mails()->findMail($this->idOf('Invoice 100'));
        self::assertNotNull($mail);
        $invoice = $this->ok($this->call('POST', '/mail/' . $mail->id . '/create-invoice'), 201);
        self::assertCount(1, $fake->calls, 'no second call');
        self::assertSame(['INV-9', 0.85], [$invoice['number'], $invoice['confidence']]);
    }

    public function test_create_invoice_calls_the_extractor_when_nothing_is_stored_or_the_supplier_changed(): void
    {
        $fake = $this->fakeExtractor();
        $this->supplier();
        $beta = $this->supplier('S002', 'Beta Wires', 'ar@beta.test');
        $this->inbox('g1', 'billing@acme.test', 'Invoice 100', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->sync();
        $mail = $this->mails()->findMail($this->idOf('Invoice 100'));
        self::assertNotNull($mail);
        self::assertNotNull($mail->extraction);

        // The supplier is changed through PATCH: the suggestion is cleared and the extractor is asked again, for Beta.
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['supplierId' => $beta->id]));
        self::assertNull($this->mails()->findMail($mail->id)?->extraction);
        $this->ok($this->call('POST', '/mail/' . $mail->id . '/create-invoice'), 201);
        self::assertCount(2, $fake->calls);
        self::assertSame('Beta Wires', $fake->calls[1]['supplierHint']);

        // A stale suggestion for another supplier is not used either.
        $this->inbox('g2', 'billing@acme.test', 'Invoice 200', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->sync();
        $second = $this->mails()->findMail($this->idOf('Invoice 200'));
        self::assertNotNull($second);
        $this->mails()->updateMail($second->with(supplierId: $beta->id));
        $calls = count($fake->calls);
        $this->ok($this->call('POST', '/mail/' . $second->id . '/create-invoice'), 201);
        self::assertCount($calls + 1, $fake->calls);

        // Nothing stored at all: the extractor is called as before.
        $this->inbox('g3', 'billing@acme.test', 'Invoice 300', 'Please pay', [['a.pdf', 'application/pdf', '%PDF a']]);
        $this->sync();
        $third = $this->mails()->findMail($this->idOf('Invoice 300'));
        self::assertNotNull($third);
        $this->mails()->updateMail($third->with(extraction: null));
        $calls = count($fake->calls);
        $this->ok($this->call('POST', '/mail/' . $third->id . '/create-invoice'), 201);
        self::assertCount($calls + 1, $fake->calls);
    }

    public function test_the_extraction_survives_a_json_round_trip(): void
    {
        $read = new Ap2ExtractedInvoice('INV-1', '2026-10-01', '2026-10-31', 'NZD', 100.0, 10.0, 110.0, 'PO-1', 5.0, 20.0, 3.0, 'CN-1', 2.0, 1.0, 'TriCab Pty', ['number' => 0.9, 'total' => 1.0], 0.75);
        self::assertEquals($read, Ap2ExtractedInvoice::fromArray($read->toArray()));
        $decoded = json_decode(json_encode($read->toArray(), JSON_THROW_ON_ERROR), true);
        self::assertIsArray($decoded);
        self::assertEquals($read, Ap2ExtractedInvoice::fromArray($decoded));
        self::assertTrue(Ap2ExtractedInvoice::none()->isEmpty());
        self::assertFalse($read->isEmpty());

        $extraction = new Ap2MailExtraction('att1', 'a.pdf', 'S001', 'Acme Cables', $read);
        self::assertEquals($extraction, Ap2MailExtraction::fromJson($extraction->toJson()));
        self::assertNull(Ap2MailExtraction::fromJson('{"nope":1}'));
        self::assertNull(Ap2MailExtraction::fromJson(null));
    }

    public function test_an_invoice_without_a_document_answers_404_for_its_file(): void
    {
        $s = $this->supplier();
        $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->insert(Ap2Fixtures::invoice($s));

        self::assertSame(404, $this->call('GET', '/invoices/' . md5('B010') . '/file')[0]);
        self::assertSame(404, $this->call('GET', '/invoices/' . md5('missing') . '/file')[0]);
    }

    public function test_a_statement_linked_to_a_mail_shows_on_the_mail(): void
    {
        $mail = $this->mailWith();
        self::assertNull(Ap2Json::get($this->ok($this->call('GET', '/mail/' . $mail->id)), 'statementId'));
        $statement = $this->ok($this->call('POST', '/statements', ['supplierId' => $mail->supplierId, 'date' => '2026-10-20', 'amount' => 500, 'mailId' => $mail->id]), 201);

        self::assertSame($statement['id'], Ap2Json::get($this->ok($this->call('GET', '/mail/' . $mail->id)), 'statementId'));
        $row = Ap2Json::get($this->ok($this->call('GET', '/mail')), '0.statementId');
        self::assertSame($statement['id'], $row);
    }

    // ---------------------------------------------------------------- sending

    private function ready(string $draft = 'Dear supplier, please send the PO.'): Ap2Mail
    {
        $mail = $this->mailWith();
        $aid = $this->mails()->attachmentsOf($mail->id)[0]->id;
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draft' => $draft, 'replyTo' => 'billing@acme.test', 'cc' => 'boss@acme.test', 'draftAttachmentIds' => [$aid]]));
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draftReviewed' => true]));

        return $mail;
    }

    public function test_send_is_a_threaded_reply_from_the_ap_mailbox_with_an_idempotency_key_and_marks_the_mail_done(): void
    {
        $mail = $this->ready();
        $sent = $this->ok($this->call('POST', '/mail/' . $mail->id . '/send'));

        self::assertTrue($sent['sent']);
        self::assertSame('Done', $sent['status']);
        self::assertNotNull($sent['sentAt']);
        self::assertTrue($sent['threaded']);
        self::assertCount(1, $this->transport->replies);
        self::assertCount(0, $this->transport->standalone);
        $out = $this->transport->replies[0];
        self::assertSame('g-' . md5('Where is my money'), $out['replyTo'], 'answers the original message in its own thread');
        $message = $out['message'];
        self::assertSame(self::MAILBOX, $message->mailbox);
        self::assertSame('AP2', $message->application);
        self::assertSame(['billing@acme.test'], $message->to);
        self::assertSame(['boss@acme.test'], $message->cc);
        self::assertSame('Re: Where is my money', $message->subject);
        self::assertStringContainsString('please send the PO', $message->body);
        self::assertSame(['one.pdf'], array_map(static fn ($a): string => $a->name, $message->attachments));
        self::assertSame('%PDF one', $message->attachments[0]->content);
        self::assertStringStartsWith('ap2-reply:' . $mail->id . ':', (string) $message->idempotencyKey);

        $types = array_map(static fn ($e): string => $e->type, $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->forSupplier($mail->supplierId ?? ''));
        self::assertContains('Email sent', $types);

        $again = $this->call('POST', '/mail/' . $mail->id . '/send');
        self::assertSame(409, $again[0], 'not twice');
        self::assertCount(1, $this->transport->replies);
        self::assertSame(409, $this->call('PATCH', '/mail/' . $mail->id, ['draft' => 'changed after sending'])[0], 'a sent draft is locked');
        self::assertSame('Done', Ap2Json::str($this->ok($this->call('PATCH', '/mail/' . $mail->id, ['status' => 'Done'])), 'status'));
    }

    public function test_the_same_content_sent_twice_is_one_delivery_by_idempotency_key(): void
    {
        $mail = $this->ready();
        $this->ok($this->call('POST', '/mail/' . $mail->id . '/send'));
        // The mail row is lost to a crash after the send: the same send again must not mail the supplier twice.
        $row = $this->mails()->findMail($mail->id);
        self::assertNotNull($row);
        $this->mails()->updateMail($row->with(sent: false, sentAt: null, status: 'In progress'));

        $this->ok($this->call('POST', '/mail/' . $mail->id . '/send'));
        self::assertCount(1, $this->transport->replies, 'the ledger returned the earlier delivery');
        self::assertTrue($this->mails()->findMail($mail->id)->sent ?? false);
    }

    public function test_send_gates(): void
    {
        $mail = $this->mailWith();
        $path = '/mail/' . $mail->id . '/send';
        self::assertSame(409, $this->call('POST', $path)[0], 'no draft, not reviewed');
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draft' => 'Hello', 'replyTo' => '']));
        $issues = Ap2Json::arr($this->call('POST', $path)[1], 'error.details.issues');
        self::assertContains('The draft must be reviewed before it is sent', $issues);
        self::assertContains('To needs an email address', $issues);

        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['replyTo' => 'billing@acme.test']));
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draftReviewed' => true]));
        $this->mails()->updateMail(($this->mails()->findMail($mail->id) ?? $mail)->with(cc: 'broken address'));
        $issues = Ap2Json::arr($this->call('POST', $path)[1], 'error.details.issues');
        self::assertSame(['CC has an invalid email address: broken address'], $issues);
        self::assertSame([], $this->transport->replies);
    }

    public function test_send_needs_a_current_approved_email_approval_when_one_exists(): void
    {
        $mail = $this->ready();
        $dir = $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory::class, InMemoryAp2PersonDirectory::class);
        $dir->add(new Ap2Person(9, 'Priya Shah', 'priya@tricab.com'), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE]);
        $requester = Ap2Fixtures::actor();
        $approver = new \TriCab\Core\Finance\Ap2\Domain\Ap2Actor(9, 'Priya Shah', 'priya@tricab.com', [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE]);
        $approvals = $this->get(Ap2ApprovalService::class);
        $a = $approvals->request($requester, ['kind' => 'Email', 'mailId' => $mail->id, 'approverUserId' => 9])[0];
        $path = '/mail/' . $mail->id . '/send';

        $issues = Ap2Json::arr($this->call('POST', $path)[1], 'error.details.issues');
        self::assertSame(['The email approval is Pending; it must be Approved before the reply is sent'], $issues);

        $approvals->decide($approver, Ap2Json::str($a, 'id'), ['decision' => 'approve', 'comment' => '']);
        // Editing the draft after the approval makes the approval stale (and clears the review, so review again).
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draft' => 'A different reply']));
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draftReviewed' => true]));
        $issues = Ap2Json::arr($this->call('POST', $path)[1], 'error.details.issues');
        self::assertSame(['Content changed. Request fresh approval.'], $issues);
        self::assertSame([], $this->transport->replies);

        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draft' => 'Dear supplier, please send the PO.']));
        $this->ok($this->call('PATCH', '/mail/' . $mail->id, ['draftReviewed' => true]));
        $this->ok($this->call('POST', $path));
        self::assertCount(1, $this->transport->replies, 'the approved content goes');
    }

    public function test_send_is_refused_while_sending_is_switched_off_and_the_history_outbox_is_kept(): void
    {
        $this->boot(['FINANCE_AP2_SENDING_ENABLED' => 'false']);
        $mail = $this->ready();
        $failure = $this->call('POST', '/mail/' . $mail->id . '/send');
        self::assertSame(409, $failure[0]);
        self::assertSame([], $this->transport->replies);
        self::assertInstanceOf(HistoryOnlyAp2Outbox::class, $this->c->get(Ap2Outbox::class));
        self::assertFalse(Ap2Json::get($this->ok($this->call('GET', '/me')), 'flags.sending'));
    }

    public function test_a_failed_send_marks_nothing_and_a_retry_goes_under_a_new_key(): void
    {
        $mail = $this->ready();
        $this->transport->failWith = new FinanceMailTransportFailed('Graph is down');
        self::assertSame(502, $this->call('POST', '/mail/' . $mail->id . '/send')[0]);
        self::assertFalse($this->mails()->findMail($mail->id)->sent ?? true);

        $this->transport->failWith = null;
        $this->ok($this->call('POST', '/mail/' . $mail->id . '/send'));
        self::assertCount(1, $this->transport->replies);
        self::assertTrue($this->mails()->findMail($mail->id)->sent ?? false);
        self::assertStringContainsString(':retry-', (string) $this->transport->replies[0]['message']->idempotencyKey);
    }

    public function test_nothing_is_marked_sent_when_the_mail_service_is_switched_off(): void
    {
        $this->c->set(FinanceMailTransport::class, new \TriCab\Api\Finance\Mail\DisabledFinanceMailTransport());
        $mail = $this->ready();
        $failure = $this->call('POST', '/mail/' . $mail->id . '/send');

        self::assertSame(409, $failure[0]);
        self::assertFalse($this->mails()->findMail($mail->id)->sent ?? true);
    }

    public function test_a_send_without_the_send_permission_is_forbidden(): void
    {
        $mail = $this->ready();
        $users = $this->c->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_MAIL_READ], ['EMPLOYEE']);

        self::assertSame(403, $this->call('POST', '/mail/' . $mail->id . '/send')[0]);
        self::assertSame(403, $this->call('POST', '/mail/' . $mail->id . '/create-invoice')[0]);
        self::assertSame(200, $this->call('GET', '/mail')[0]);
    }

    public function test_the_test_recipient_guard_redirects_a_reply_and_drops_cc_through_the_real_transport(): void
    {
        $http = new RecordingMethodHttpClient();
        $http->queue = [
            ['status' => 200, 'body' => '{"access_token":"tok","expires_in":3600}'],
            ['status' => 202, 'body' => ''],
        ];
        $this->c->set(FinanceMailTransport::class, new GraphFinanceMailTransport($http, 't', 'c', 'dummy-secret', 'tester@tricab.test', pauseBetweenRetries: false));
        $mail = $this->ready();
        $sent = $this->ok($this->call('POST', '/mail/' . $mail->id . '/send'));

        self::assertTrue($sent['sent']);
        self::assertFalse($sent['threaded'], 'with the guard on nothing is threaded to the real sender');
        $payload = $http->calls[1]['payload'] ?? [];
        $graph = Ap2Json::arr($payload, 'message');
        self::assertSame([['emailAddress' => ['address' => 'tester@tricab.test']]], $graph['toRecipients']);
        self::assertArrayNotHasKey('ccRecipients', $graph);
        self::assertStringNotContainsString('billing@acme.test', json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('boss@acme.test', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    // ---------------------------------------------------------------- the Graph outbox

    public function test_approval_emails_use_the_graph_outbox_only_when_sending_is_on_and_after_the_commit(): void
    {
        self::assertInstanceOf(Ap2MailOutbox::class, $this->c->get(Ap2Outbox::class));

        $s = $this->supplier();
        $dir = $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory::class, InMemoryAp2PersonDirectory::class);
        $dir->add(new Ap2Person(9, 'Priya Shah', 'priya@tricab.com'), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE]);
        $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore::class, InMemoryAp2InvoiceStore::class)->insert(Ap2Fixtures::invoice($s));
        $this->get(Ap2ApprovalService::class)->request(Ap2Fixtures::actor(), ['kind' => 'Invoice', 'invoiceIds' => [md5('B010')], 'approverUserId' => 9]);

        self::assertCount(1, $this->transport->standalone);
        $message = $this->transport->standalone[0];
        self::assertSame(['priya@tricab.com'], $message->to);
        self::assertSame(self::MAILBOX, $message->mailbox);
        self::assertSame('AP2', $message->application);
        self::assertStringStartsWith('ap2-approval-request:', (string) $message->idempotencyKey);
        $types = array_map(static fn ($e): string => $e->type, $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->forSupplier($s->id));
        self::assertContains('Email sent', $types);
    }

    public function test_a_queued_message_is_dropped_when_its_transaction_rolls_back_and_a_failure_never_breaks_the_caller(): void
    {
        $outbox = $this->get(Ap2MailOutbox::class);
        $s = $this->supplier();
        $message = new Ap2OutboxMessage(Ap2OutboxMessage::APPROVAL_REQUEST, $s->id, new Ap2Person(7, 'Alex', 'alex@tricab.com'), 'priya@tricab.com', 'Please approve', 'Body', reference: 'ref-1');

        $outbox->enter();
        $outbox->queue($message);
        self::assertCount(0, $this->transport->standalone, 'held until the commit');
        $outbox->leave(false);
        self::assertCount(0, $this->transport->standalone, 'rolled back: never sent');

        $outbox->enter();
        $outbox->queue($message);
        $outbox->leave(true);
        self::assertCount(1, $this->transport->standalone);

        $outbox->queue($message);
        self::assertCount(1, $this->transport->standalone, 'the same reference is the same delivery');

        $this->transport->failWith = new FinanceMailTransportFailed('Graph is down');
        $outbox->queue(new Ap2OutboxMessage(Ap2OutboxMessage::DECISION_NOTICE, $s->id, new Ap2Person(7, 'Alex', 'alex@tricab.com'), 'alex@tricab.com', 'Decided', 'Body', reference: 'ref-2'));
        $types = array_map(static fn ($e): string => $e->type, $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class, InMemoryAp2HistoryStore::class)->forSupplier($s->id));
        self::assertContains('Email not sent', $types);
    }

    public function test_the_default_outbox_stays_history_only(): void
    {
        $this->boot(['FINANCE_AP2_SENDING_ENABLED' => 'false']);
        self::assertInstanceOf(HistoryOnlyAp2Outbox::class, $this->c->get(Ap2Outbox::class));
    }

    public function test_the_mailbox_reader_binding_is_the_shared_service(): void
    {
        self::assertInstanceOf(\TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService::class, $this->c->get(FinanceMailboxReader::class));
    }

    public function test_the_statement_store_is_untouched_by_a_mail_sync(): void
    {
        $this->inbox('g1', 'a@b.test', 'Statement', 'x');
        $this->sync();
        self::assertSame([], $this->store(\TriCab\Core\Finance\Ap2\Contract\Ap2StatementStore::class, InMemoryAp2StatementStore::class)->all());
    }
}
