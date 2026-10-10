<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApAuditStore;
use TriCab\Api\Finance\Ap\InMemoryApEmailStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The composer's seven routes (T6.10) through the real app on the in-memory stores, a scripted model and a transport that sends
 * nothing: one permission each, the mailbox scope's 404s, every validation message, the sending switch, the send-from grant,
 * idempotency, the 3 MB limit with the attached invoice, threading, the history's shape and statuses, and the drafts. The person is
 * user 1.
 */
final class FinanceApMailRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap';

    private const READ = [Module::PERMISSION_AP_INVOICES_READ];
    private const SEND = [Module::PERMISSION_AP_MAIL_SEND];

    /** @var App<ContainerInterface> */
    private App $app;

    private RecordingReplyMailTransport $transport;

    private ScriptedApAiClient $ai;

    protected function setUp(): void
    {
        $this->boot(['FINANCE_AP_SENDING_ENABLED' => 'true', 'FINANCE_AP_MAILBOX' => 'AP@example.test']);
    }

    /**
     * @param array<string, string> $env
     */
    private function boot(array $env, bool $recordingTransport = true): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true'] + $env);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ap@example.test', 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m2', 'melissa@example.test', 'Melissa', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m3', 'readonly@example.test', 'Read only', 'AP', true, ['AP' => ['read' => true, 'send' => false]]),
        ]));
        $this->transport = new RecordingReplyMailTransport();
        if ($recordingTransport) {
            $container->set(FinanceMailTransport::class, $this->transport);
        }
        $this->ai = new ScriptedApAiClient();
        $container->set(FinanceAiClient::class, $this->ai);
        $this->seedInvoice(ApInvoiceFixtures::invoice('i1', attachment: 'Acme INV-1.pdf', vendorEmail: 'Billing@vendor.example.test'), '%PDF-1.4 the invoice');
        $this->seedInvoice(ApInvoiceFixtures::invoice('nodoc'));
        $this->seedInvoice(ApInvoiceFixtures::invoice('other', mailbox: 'melissa@example.test'));
        $this->seedEmail('e1', 'ap@example.test');
        $this->seedEmail('e-other', 'melissa@example.test');
    }

    private function container(): ContainerInterface
    {
        return $this->app->getContainer();
    }

    private function invoices(): InMemoryApInvoiceStore
    {
        $store = $this->container()->get(ApInvoiceStore::class);
        self::assertInstanceOf(InMemoryApInvoiceStore::class, $store);

        return $store;
    }

    private function audit(): InMemoryApAuditStore
    {
        $store = $this->container()->get(ApAuditStore::class);
        self::assertInstanceOf(InMemoryApAuditStore::class, $store);

        return $store;
    }

    private function ledger(): InMemoryFinanceMailLedger
    {
        $ledger = $this->container()->get(FinanceMailLedger::class);
        self::assertInstanceOf(InMemoryFinanceMailLedger::class, $ledger);

        return $ledger;
    }

    private function seedInvoice(ApInvoice $invoice, ?string $bytes = null): void
    {
        if ($bytes !== null) {
            $attachments = $this->container()->get(ApAttachmentService::class);
            $files = $this->container()->get(FinanceAttachmentService::class);
            self::assertInstanceOf(ApAttachmentService::class, $attachments);
            self::assertInstanceOf(FinanceAttachmentService::class, $files);
            $file = $attachments->keep($invoice->attachment, 'application/pdf', $bytes, 1);
            $files->attach(Module::APP_AP, $file->id, ApAttachmentService::RECORD_TYPE, $invoice->id, 1);
            $invoice = $invoice->with(fileId: $file->id);
        }
        $this->invoices()->insert($invoice);
    }

    private function seedEmail(string $id, string $mailbox): void
    {
        $store = $this->container()->get(ApEmailStore::class);
        self::assertInstanceOf(InMemoryApEmailStore::class, $store);
        $store->insert(new ApEmail(
            $id,
            'graph-' . $id,
            $mailbox,
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            senderName: 'Vera Vendor',
            senderEmail: 'accounts@acme.example.test',
            subject: 'Where is our payment?',
            bodyPreview: 'We have not been paid.',
        ));
    }

    private function scope(string ...$mailboxes): void
    {
        $scopes = $this->container()->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        foreach ($mailboxes as $mailbox) {
            $scopes->grant(1, 'AP', FinanceAccessScope::TYPE_MAILBOX, $mailbox);
        }
    }

    /**
     * @param list<string> $permissions
     * @param array<string, mixed>|null $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, array $permissions, ?array $body = null): array
    {
        $users = $this->container()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $response = $this->app->handle($request);

        return [$response->getStatusCode(), TestAppFactory::json($response)];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function errorOf(array $json): array
    {
        self::assertFalse($json['success']);
        $error = $json['error'];
        self::assertIsArray($error);
        $typed = [];
        foreach ($error as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function dataOf(array $json): array
    {
        self::assertTrue($json['success'], json_encode($json) ?: '');
        $data = $json['data'];
        self::assertIsArray($data);
        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(array $json): array
    {
        self::assertTrue($json['success']);
        $data = $json['data'];
        self::assertIsArray($data);
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function vendorBody(array $over = []): array
    {
        return $over + ['to' => 'billing@vendor.example.test', 'subject' => 'Question', 'body' => 'Where is INV-1?'];
    }

    // ---- permissions -------------------------------------------------------------------

    public function test_each_route_needs_its_own_permission_and_nothing_goes_out_without_it(): void
    {
        $send = ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b', 'instruction' => 'x'];
        $gets = ['/send-mailboxes', '/invoices/i1/outbound-messages', '/emails/e1/outbound-messages'];
        $posts = ['/invoices/i1/email-draft', '/emails/e1/email-draft', '/invoices/i1/send-email', '/emails/e1/send-reply'];

        foreach ($gets as $path) {
            self::assertSame(403, $this->call('GET', $path, [])[0], $path);
            self::assertSame(403, $this->call('GET', $path, self::SEND)[0], $path . ': sending does not read');
            self::assertSame(200, $this->call('GET', $path, self::READ)[0], $path);
        }
        foreach ($posts as $path) {
            self::assertSame(403, $this->call('POST', $path, [], $send)[0], $path);
            self::assertSame(403, $this->call('POST', $path, self::READ, $send)[0], $path . ': a reader cannot send or draft');
            self::assertSame(403, $this->call('POST', $path, [Module::PERMISSION_AP_MAIL_READ, Module::PERMISSION_AP_INVOICES_WRITE], $send)[0], $path);
        }
        self::assertSame([], $this->transport->standalone);
        self::assertSame([], $this->transport->replies);
        self::assertSame([], $this->ai->calls);
    }

    // ---- send mailboxes ----------------------------------------------------------------

    public function test_send_mailboxes_answers_the_sources_shape_in_the_envelope(): void
    {
        [$status, $json] = $this->call('GET', '/send-mailboxes', self::READ);

        self::assertSame(200, $status);
        self::assertSame(['success' => true, 'data' => ['mailboxes' => [
            ['email' => 'ap@example.test', 'label' => 'Accounts Payable'],
            ['email' => 'melissa@example.test', 'label' => 'Melissa'],
        ], 'default' => 'ap@example.test']], $json);
    }

    public function test_send_mailboxes_follows_the_persons_scope(): void
    {
        $this->scope('melissa@example.test');

        [, $json] = $this->call('GET', '/send-mailboxes', self::READ);

        self::assertSame([['email' => 'melissa@example.test', 'label' => 'Melissa']], self::dataOf($json)['mailboxes']);
    }

    // ---- vendor email ------------------------------------------------------------------

    public function test_a_vendor_email_answers_the_outbound_row_without_threaded_and_the_history_then_shows_it(): void
    {
        [$status, $json] = $this->call('POST', '/invoices/i1/send-email', self::SEND, self::vendorBody(['cc' => 'Boss@example.test', 'attach_invoice' => true]));

        self::assertSame(200, $status);
        $row = self::dataOf($json);
        self::assertSame(['i1', 'Sent', 'Question', ['billing@vendor.example.test'], ['boss@example.test'], true, 'ap@example.test'], [$row['invoice_id'], $row['status'], $row['subject'], $row['to_addresses'], $row['cc_addresses'], $row['attach_invoice'], $row['from_mailbox']]);
        self::assertArrayNotHasKey('threaded', $row);
        self::assertCount(1, $this->transport->standalone);
        self::assertSame(['Acme INV-1.pdf'], array_map(static fn ($f): string => $f->name, $this->transport->standalone[0]->attachments));

        [$status, $history] = $this->call('GET', '/invoices/i1/outbound-messages', self::READ);
        self::assertSame(200, $status);
        $rows = self::rowsOf($history);
        self::assertCount(1, $rows);
        self::assertSame($row['id'], $rows[0]['id']);
        foreach (['id', 'status', 'subject', 'sent_at', 'cc_addresses', 'to_addresses', 'error'] as $key) {
            self::assertArrayHasKey($key, $rows[0], 'the pages read ' . $key);
        }
        self::assertSame(['Sent', null, ['boss@example.test']], [$rows[0]['status'], $rows[0]['error'], $rows[0]['cc_addresses']]);
        self::assertSame('vendor_email_sent', $this->audit()->forEntity('invoice', 'i1')[0]->action);
        self::assertSame([], self::rowsOf($this->call('GET', '/emails/e1/outbound-messages', self::READ)[1]));
    }

    public function test_the_vendor_refusals_are_422_with_the_ap_code_and_the_sources_words(): void
    {
        $cases = [
            [['to' => 'nope'], 'A valid vendor email is required'],
            [['cc' => 'bad'], 'Invalid CC address: bad'],
            [['subject' => ''], 'Subject and message are required'],
            [['attachments' => 'x'], 'Attachments must be a list'],
            [['attachments' => [['name' => 'a.txt', 'data' => 'not base64!']]], 'a.txt could not be read'],
        ];
        foreach ($cases as [$over, $message]) {
            [$status, $json] = $this->call('POST', '/invoices/i1/send-email', self::SEND, self::vendorBody($over));
            self::assertSame(422, $status, $message);
            self::assertSame(['FINANCE_AP_INPUT_INVALID', $message], [self::errorOf($json)['code'], self::errorOf($json)['message']]);
        }
        self::assertSame([], $this->transport->standalone);
        self::assertSame([], $this->audit()->all());
    }

    public function test_attach_invoice_with_no_document_is_a_422_and_nothing_is_recorded(): void
    {
        [$status, $json] = $this->call('POST', '/invoices/nodoc/send-email', self::SEND, self::vendorBody(['attach_invoice' => true]));

        self::assertSame(422, $status);
        self::assertSame('The invoice attachment could not be found', self::errorOf($json)['message']);
        self::assertSame([], $this->ledger()->all());
        self::assertSame([], self::rowsOf($this->call('GET', '/invoices/nodoc/outbound-messages', self::READ)[1]));
    }

    public function test_the_attached_invoice_counts_towards_the_three_megabyte_limit_over_the_route(): void
    {
        $big = base64_encode(str_repeat('x', 3 * 1024 * 1024 - 5));
        $files = [['name' => 'big.bin', 'content_type' => 'application/octet-stream', 'data' => $big]];

        [$status] = $this->call('POST', '/invoices/i1/send-email', self::SEND, self::vendorBody(['attachments' => $files]));
        self::assertSame(200, $status, 'the typed file alone fits');

        [$status, $json] = $this->call('POST', '/invoices/i1/send-email', self::SEND, self::vendorBody(['attachments' => $files, 'attach_invoice' => true]));
        self::assertSame(422, $status);
        self::assertSame('Attachments come to more than 3 MB in total. Send a link instead, or attach fewer files.', self::errorOf($json)['message']);
        self::assertCount(1, $this->transport->standalone);
    }

    public function test_the_send_from_grant_is_a_403_naming_the_mailbox(): void
    {
        foreach (['readonly@example.test', 'nobody@example.test'] as $from) {
            [$status, $json] = $this->call('POST', '/invoices/i1/send-email', self::SEND, self::vendorBody(['from' => $from]));
            self::assertSame(403, $status);
            self::assertSame(['FINANCE_AP_FORBIDDEN', 'Tri-AP is not allowed to send from ' . $from . '. Ask a finance administrator to grant it that mailbox.'], [self::errorOf($json)['code'], self::errorOf($json)['message']]);
        }
        [$status] = $this->call('POST', '/emails/e1/send-reply', self::SEND, ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b', 'from' => 'readonly@example.test']);
        self::assertSame(403, $status);
        self::assertSame([], $this->transport->standalone);
    }

    public function test_out_of_scope_or_missing_records_are_404_on_every_route(): void
    {
        $this->scope('melissa@example.test');
        $body = ['to' => 'a@b.example.test', 'subject' => 's', 'body' => 'b', 'instruction' => 'x', 'from' => 'melissa@example.test'];

        foreach ([
            ['GET', '/invoices/i1/outbound-messages', self::READ],
            ['GET', '/invoices/ghost/outbound-messages', self::READ],
            ['GET', '/emails/e1/outbound-messages', self::READ],
            ['POST', '/invoices/i1/email-draft', self::SEND],
            ['POST', '/emails/e1/email-draft', self::SEND],
            ['POST', '/invoices/i1/send-email', self::SEND],
            ['POST', '/emails/e1/send-reply', self::SEND],
            ['POST', '/emails/ghost/send-reply', self::SEND],
        ] as [$method, $path, $permissions]) {
            [$status, $json] = $this->call($method, $path, $permissions, $method === 'POST' ? $body : null);
            self::assertSame(404, $status, $path);
            self::assertSame(['FINANCE_AP_NOT_FOUND', 'Not found'], [self::errorOf($json)['code'], self::errorOf($json)['message']], $path);
        }
        self::assertSame([], $this->transport->standalone);
        self::assertSame([], $this->ai->calls);

        [$status] = $this->call('POST', '/invoices/other/send-email', self::SEND, $body);
        self::assertSame(200, $status, 'the scoped person reaches their own mailbox');
    }

    // ---- reply -------------------------------------------------------------------------

    public function test_a_reply_to_the_sender_is_threaded_and_answers_the_row_with_threaded(): void
    {
        [$status, $json] = $this->call('POST', '/emails/e1/send-reply', self::SEND, ['to' => 'Accounts@acme.example.test', 'subject' => 'Re: Where is our payment?', 'body' => "Hello\nIt is coming.", 'cc' => ['boss@example.test']]);

        self::assertSame(200, $status);
        $row = self::dataOf($json);
        self::assertTrue($row['threaded']);
        self::assertSame(['e1', null, 'Sent', ['accounts@acme.example.test']], [$row['email_id'], $row['invoice_id'], $row['status'], $row['to_addresses']]);
        self::assertCount(1, $this->transport->replies);
        self::assertSame('graph-e1', $this->transport->replies[0]['replyTo']);

        $rows = self::rowsOf($this->call('GET', '/emails/e1/outbound-messages', self::READ)[1]);
        self::assertSame(['Sent', null, "Hello\nIt is coming."], [$rows[0]['status'], $rows[0]['error'], $rows[0]['body']]);
        self::assertTrue($this->audit()->forEntity('email', 'e1')[0]->details['threaded']);
    }

    public function test_a_typed_in_recipient_is_not_threaded_and_a_transport_fallback_says_so(): void
    {
        [, $json] = $this->call('POST', '/emails/e1/send-reply', self::SEND, ['to' => 'someone@else.example.test', 'subject' => 's', 'body' => 'b']);
        self::assertFalse(self::dataOf($json)['threaded']);
        self::assertSame([], $this->transport->replies);

        $this->transport->threads = false;
        [, $json] = $this->call('POST', '/emails/e1/send-reply', self::SEND, ['to' => 'accounts@acme.example.test', 'subject' => 's', 'body' => 'b']);
        self::assertFalse(self::dataOf($json)['threaded']);
        self::assertCount(1, $this->transport->replies);
    }

    public function test_a_repeated_idempotency_key_sends_once(): void
    {
        $body = ['to' => 'accounts@acme.example.test', 'subject' => 's', 'body' => 'b', 'idempotency_key' => 'dialog-1'];

        [, $first] = $this->call('POST', '/emails/e1/send-reply', self::SEND, $body);
        [$status, $second] = $this->call('POST', '/emails/e1/send-reply', self::SEND, $body);

        self::assertSame(200, $status);
        self::assertSame(self::dataOf($first)['id'], self::dataOf($second)['id']);
        self::assertCount(1, $this->transport->replies);
        self::assertCount(1, $this->ledger()->all());
        self::assertCount(1, self::rowsOf($this->call('GET', '/emails/e1/outbound-messages', self::READ)[1]));
        self::assertCount(1, $this->audit()->forEntity('email', 'e1'));

        [$status, $json] = $this->call('POST', '/emails/e1/send-reply', self::SEND, ['idempotency_key' => str_repeat('k', 101)] + $body);
        self::assertSame(422, $status);
        self::assertSame('idempotency_key must be text of at most 100 characters', self::errorOf($json)['message']);
    }

    public function test_a_failed_send_is_a_502_the_history_reads_failed_and_nothing_is_audited(): void
    {
        $this->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        [$status, $json] = $this->call('POST', '/invoices/i1/send-email', self::SEND, self::vendorBody());

        self::assertSame(502, $status);
        $error = self::errorOf($json);
        self::assertSame(['FINANCE_AP_UPSTREAM_FAILED', 'send', 'Failed to send email: Microsoft Graph refused the message with status 500.'], [$error['code'], $error['reason'], $error['message']]);
        $rows = self::rowsOf($this->call('GET', '/invoices/i1/outbound-messages', self::READ)[1]);
        self::assertSame(['Failed', 'Microsoft Graph refused the message with status 500.', null], [$rows[0]['status'], $rows[0]['error'], $rows[0]['sent_at']]);
        self::assertSame([], $this->audit()->all());
    }

    public function test_with_the_default_mail_off_transport_a_send_is_refused_as_not_sent(): void
    {
        $this->boot(['FINANCE_AP_SENDING_ENABLED' => 'true'], false);

        [$status, $json] = $this->call('POST', '/emails/e1/send-reply', self::SEND, ['to' => 'accounts@acme.example.test', 'subject' => 's', 'body' => 'b']);

        self::assertSame(502, $status);
        self::assertSame('Failed to send email: Mail is switched off', self::errorOf($json)['message']);
        $rows = self::rowsOf($this->call('GET', '/emails/e1/outbound-messages', self::READ)[1]);
        self::assertSame(['Failed', 'Mail is switched off'], [$rows[0]['status'], $rows[0]['error']]);
        self::assertSame([], $this->audit()->all());
    }

    public function test_with_the_sending_switch_off_both_sends_are_a_503_and_me_says_so_but_drafts_and_reads_work(): void
    {
        $this->boot([]);

        foreach ([['/invoices/i1/send-email', self::vendorBody()], ['/emails/e1/send-reply', ['to' => 'accounts@acme.example.test', 'subject' => 's', 'body' => 'b']]] as [$path, $body]) {
            [$status, $json] = $this->call('POST', $path, self::SEND, $body);
            self::assertSame(503, $status);
            self::assertSame(['FINANCE_AP_UNAVAILABLE', 'Sending is switched off'], [self::errorOf($json)['code'], self::errorOf($json)['message']]);
        }
        self::assertSame([], $this->transport->standalone);
        self::assertSame([], $this->transport->replies);
        self::assertSame([], $this->ledger()->all());

        [, $me] = $this->call('GET', '/me', [Module::PERMISSION_AP_ACCESS]);
        self::assertFalse(self::dataOf($me)['sending_enabled']);
        self::assertSame(200, $this->call('GET', '/send-mailboxes', self::READ)[0]);
        self::assertSame(200, $this->call('GET', '/invoices/i1/outbound-messages', self::READ)[0]);
        self::assertSame(200, $this->call('POST', '/invoices/i1/email-draft', self::SEND, ['instruction' => 'Ask when'])[0]);
        self::assertSame(200, $this->call('POST', '/emails/e1/email-draft', self::SEND, ['instruction' => 'Say soon'])[0]);

        $this->boot(['FINANCE_AP_SENDING_ENABLED' => 'true']);
        [, $me] = $this->call('GET', '/me', [Module::PERMISSION_AP_ACCESS]);
        self::assertTrue(self::dataOf($me)['sending_enabled']);
    }

    // ---- drafts ------------------------------------------------------------------------

    public function test_the_drafts_answer_subject_body_and_to(): void
    {
        [$status, $json] = $this->call('POST', '/invoices/i1/email-draft', self::SEND, ['instruction' => 'Ask for remittance', 'previous_draft' => 'Old']);
        self::assertSame(200, $status);
        self::assertSame(['subject' => 'Question about INV-42', 'body' => 'When will this be paid?', 'to' => 'Billing@vendor.example.test'], self::dataOf($json));

        [$status, $json] = $this->call('POST', '/emails/e1/email-draft', self::SEND, ['instruction' => 'Say soon']);
        self::assertSame(200, $status);
        self::assertSame(['subject' => 'Question about INV-42', 'body' => 'When will this be paid?', 'to' => 'accounts@acme.example.test'], self::dataOf($json));
        $email = $this->ai->calls[1]['data']['email'];
        self::assertIsArray($email);
        self::assertSame('accounts@acme.example.test', $email['sender']);
        self::assertSame([], $this->audit()->all(), 'a draft writes no audit event');
    }

    public function test_an_empty_instruction_is_a_422_with_the_sources_words_and_an_ai_failure_a_502(): void
    {
        [$status, $json] = $this->call('POST', '/invoices/i1/email-draft', self::SEND, ['instruction' => '  ']);
        self::assertSame([422, 'FINANCE_AP_INPUT_INVALID', 'Tell the AI what you need to ask the vendor'], [$status, self::errorOf($json)['code'], self::errorOf($json)['message']]);
        [$status, $json] = $this->call('POST', '/emails/e1/email-draft', self::SEND, []);
        self::assertSame([422, 'Tell the AI what the reply should say'], [$status, self::errorOf($json)['message']]);
        self::assertSame([], $this->ai->calls);

        $this->ai->answers['ap_vendor_email_draft'] = FinanceAiFailure::of(FinanceAiFailure::UNAVAILABLE, 'The AI service is not available.');
        $this->ai->answers['ap_reply_draft'] = FinanceAiFailure::of(FinanceAiFailure::BAD_MODEL_ANSWER, 'The AI service gave an answer that could not be used.');
        [$status, $json] = $this->call('POST', '/invoices/i1/email-draft', self::SEND, ['instruction' => 'x']);
        self::assertSame([502, 'FINANCE_AP_UPSTREAM_FAILED', 'ai', 'The AI service is not available.'], [$status, self::errorOf($json)['code'], self::errorOf($json)['reason'], self::errorOf($json)['message']]);
        [$status, $json] = $this->call('POST', '/emails/e1/email-draft', self::SEND, ['instruction' => 'x']);
        self::assertSame([502, 'The AI service gave an answer that could not be used.'], [$status, self::errorOf($json)['message']]);
    }

    public function test_the_history_lists_the_newest_first_and_never_the_other_records(): void
    {
        foreach (['First', 'Second'] as $subject) {
            $this->call('POST', '/invoices/i1/send-email', self::SEND, self::vendorBody(['subject' => $subject]));
        }
        $this->call('POST', '/invoices/nodoc/send-email', self::SEND, self::vendorBody(['subject' => 'Other']));

        $rows = self::rowsOf($this->call('GET', '/invoices/i1/outbound-messages', self::READ)[1]);

        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing(['First', 'Second'], array_column($rows, 'subject'));
        self::assertSame(['Other'], array_column(self::rowsOf($this->call('GET', '/invoices/nodoc/outbound-messages', self::READ)[1]), 'subject'));
    }
}
