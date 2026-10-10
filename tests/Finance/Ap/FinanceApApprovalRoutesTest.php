<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\App;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApApproverDirectory;
use TriCab\Api\Finance\Ap\InMemoryApAuditStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Tests\Finance\Mail\RecordingReplyMailTransport;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap\Contract\ApApproverDirectory;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Identity\Contract\UserRepository;

/**
 * The approval routes (T6.11) through the real app on the in-memory stores and a transport that sends nothing: one permission
 * each, every validation message, the shapes the Approvals pages read, the 409 carrying the batch, the mailbox scope's 404s
 * (also on the decision), the sending switch (decision D80) and a mail failure as a warning. The person is user 1.
 */
final class FinanceApApprovalRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap';

    private const READ = [Module::PERMISSION_AP_INVOICES_READ];
    private const PREPARE = [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_PREPARE];
    private const DECIDE = [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_DECIDE];

    /** @var App<ContainerInterface> */
    private App $app;

    private RecordingReplyMailTransport $transport;

    protected function setUp(): void
    {
        $this->boot(['FINANCE_AP_SENDING_ENABLED' => 'true', 'FINANCE_AP_MAILBOX' => 'AP@example.test']);
    }

    /**
     * @param array<string, string> $env
     */
    private function boot(array $env): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true'] + $env);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'ap@example.test', 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
        ]));
        $this->transport = new RecordingReplyMailTransport();
        $container->set(FinanceMailTransport::class, $this->transport);
        $directory = $container->get(ApApproverDirectory::class);
        self::assertInstanceOf(InMemoryApApproverDirectory::class, $directory);
        $directory->add(21, 'Ann Approver', 'ann@example.test');
        $directory->add(22, 'Bob Approver', 'bob@example.test');
        $this->invoice(ApInvoiceFixtures::invoice('i1', vendor: 'Acme', invoiceNo: 'INV-1'));
        $this->invoice(ApInvoiceFixtures::invoice('i2', vendor: 'Bravo', invoiceNo: 'INV-2'));
        $this->invoice(ApInvoiceFixtures::invoice('other', mailbox: 'melissa@example.test'));
    }

    private function container(): ContainerInterface
    {
        return $this->app->getContainer();
    }

    private function invoice(\TriCab\Core\Finance\Ap\Domain\ApInvoice $invoice): void
    {
        $store = $this->container()->get(ApInvoiceStore::class);
        self::assertInstanceOf(InMemoryApInvoiceStore::class, $store);
        $store->insert($invoice);
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
    private static function dataOf(array $json): array
    {
        self::assertTrue($json['success'], json_encode($json) ?: '');

        return self::map($json['data']);
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function errorOf(array $json): array
    {
        self::assertFalse($json['success']);

        return self::map($json['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $over
     * @return array<string, mixed> the batch
     */
    private function create(array $ids = ['i1', 'i2'], array $over = []): array
    {
        [$status, $json] = $this->call('POST', '/approval-batches', self::PREPARE, $over + ['invoice_ids' => $ids, 'note' => 'Please check']);
        self::assertSame(201, $status, json_encode($json) ?: '');

        return self::map(self::dataOf($json)['batch']);
    }

    // ---- permissions ---------------------------------------------------------------------

    public function test_each_route_needs_its_own_permission(): void
    {
        $batch = $this->create();
        $id = $batch['id'];
        self::assertIsString($id);

        foreach (['/approvers', '/approval-batches', '/approval-batches/' . $id] as $path) {
            self::assertSame(403, $this->call('GET', $path, [])[0], $path);
            self::assertSame(403, $this->call('GET', $path, [Module::PERMISSION_AP_APPROVALS_DECIDE, Module::PERMISSION_AP_APPROVALS_PREPARE])[0], $path . ': deciding is not reading');
            self::assertSame(200, $this->call('GET', $path, self::READ)[0], $path);
        }
        self::assertSame(403, $this->call('POST', '/approval-batches', self::READ, ['invoice_ids' => ['i1']])[0]);
        self::assertSame(403, $this->call('POST', '/approval-batches', self::DECIDE, ['invoice_ids' => ['i1']])[0], 'an Approver does not prepare');
        self::assertSame(403, $this->call('POST', '/approval-batches/' . $id . '/decision', self::PREPARE, ['decision' => 'approve'])[0], 'a Preparer cannot decide');
        self::assertSame(403, $this->call('POST', '/approval-batches/' . $id . '/decision', self::READ, ['decision' => 'approve'])[0]);
        self::assertSame('Pending', self::map(self::dataOf($this->call('GET', '/approval-batches/' . $id, self::READ)[1]))['status']);
        self::assertSame(200, $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'approve'])[0]);
    }

    // ---- approvers -----------------------------------------------------------------------

    public function test_approvers_are_id_full_name_and_email_rows(): void
    {
        [$status, $json] = $this->call('GET', '/approvers', self::READ);

        self::assertSame(200, $status);
        self::assertSame([
            ['id' => '21', 'full_name' => 'Ann Approver', 'email' => 'ann@example.test'],
            ['id' => '22', 'full_name' => 'Bob Approver', 'email' => 'bob@example.test'],
        ], $json['data']);
    }

    public function test_nobody_is_an_empty_list_with_a_200(): void
    {
        $directory = $this->container()->get(ApApproverDirectory::class);
        self::assertInstanceOf(InMemoryApApproverDirectory::class, $directory);
        $directory->remove(21);
        $directory->remove(22);

        [$status, $json] = $this->call('GET', '/approvers', self::READ);

        self::assertSame(200, $status);
        self::assertSame([], $json['data']);
    }

    // ---- create --------------------------------------------------------------------------

    public function test_a_request_answers_201_with_the_batch_the_pages_read_and_a_null_warning(): void
    {
        [$status, $json] = $this->call('POST', '/approval-batches', self::PREPARE, ['invoice_ids' => ['i1', 'i2'], 'note' => 'Please check']);

        self::assertSame(201, $status);
        $data = self::dataOf($json);
        self::assertSame(['batch', 'warning'], array_keys($data));
        self::assertNull($data['warning']);
        $batch = self::map($data['batch']);
        self::assertSame(
            ['id', 'prepared_by', 'status', 'note', 'decided_by', 'decided_at', 'decision_comment', 'created_date', 'updated_date', 'invoices', 'decisions', 'prepared_by_user', 'decided_by_user', 'notified_emails'],
            array_keys($batch),
        );
        self::assertSame('Pending', $batch['status']);
        self::assertSame('Please check', $batch['note']);
        self::assertSame('1', $batch['prepared_by']);
        $preparer = self::map($batch['prepared_by_user']);
        self::assertSame('1', $preparer['id']);
        self::assertNotSame('', $preparer['email']);
        self::assertNotSame('', $preparer['full_name']);
        self::assertNull($batch['decided_by_user']);
        self::assertSame(['ann@example.test', 'bob@example.test'], $batch['notified_emails']);
        $invoices = $batch['invoices'];
        self::assertIsArray($invoices);
        self::assertSame(['i1', 'i2'], array_column($invoices, 'id'));
        self::assertSame(['Pending approval', 'Pending approval'], array_column($invoices, 'approval_status'));
        self::assertCount(1, $this->transport->standalone);
        self::assertSame('AP approval request: 2 invoice(s)', $this->transport->standalone[0]->subject);
        self::assertSame('ap@example.test', $this->transport->standalone[0]->mailbox);
        $id = $batch['id'];
        self::assertIsString($id);
        self::assertMatchesRegularExpression('#<a href=\'https?://[^/\']+/finance/ap/approvals/' . $id . '\'>Review this approval request in Invoice Flow</a>#', $this->transport->standalone[0]->body);
        $audit = $this->container()->get(ApAuditStore::class);
        self::assertInstanceOf(InMemoryApAuditStore::class, $audit);
        self::assertSame(['approval_requested'], array_map(static fn ($e): string => $e->action, $audit->forEntity('approval_batch', $id)));
    }

    public function test_the_validation_messages_are_422_with_the_ap_code_and_create_nothing(): void
    {
        $cases = [
            [[], 'Select at least one invoice'],
            [['invoice_ids' => []], 'Select at least one invoice'],
            [['invoice_ids' => 'i1'], 'Select at least one invoice'],
            [['invoice_ids' => ['i1', 'nope']], 'One or more invoices are unavailable'],
            [['invoice_ids' => ['i1'], 'recipients' => 'ann@example.test'], 'Recipients must be a list of email addresses'],
            [['invoice_ids' => ['i1'], 'recipients' => ['missing@domain']], '“missing@domain” is not a valid email address'],
            [['invoice_ids' => ['i1'], 'recipients' => []], 'No active Approvers are configured. Assign the Approver role to someone, or enter an address to notify.'],
            [['invoice_ids' => ['i1'], 'recipients' => array_map(static fn (int $n): string => 'p' . $n . '@example.test', range(1, 21))], 'An approval request cannot notify more than 20 addresses'],
        ];
        foreach ($cases as [$body, $message]) {
            [$status, $json] = $this->call('POST', '/approval-batches', self::PREPARE, $body);

            self::assertSame(422, $status, $message);
            $error = self::errorOf($json);
            self::assertSame('FINANCE_AP_INPUT_INVALID', $error['code']);
            self::assertSame($message, $error['message']);
        }
        self::assertSame([], self::dataOf($this->call('GET', '/approval-batches', self::READ)[1]));
        self::assertSame([], $this->transport->standalone);
    }

    public function test_an_out_of_scope_invoice_is_unavailable(): void
    {
        $this->scope('ap@example.test');

        [$status, $json] = $this->call('POST', '/approval-batches', self::PREPARE, ['invoice_ids' => ['i1', 'other']]);

        self::assertSame(422, $status);
        self::assertSame('One or more invoices are unavailable', self::errorOf($json)['message']);
    }

    public function test_typed_recipients_that_cannot_decide_come_back_as_the_warning(): void
    {
        [$status, $json] = $this->call('POST', '/approval-batches', self::PREPARE, ['invoice_ids' => ['i1'], 'recipients' => ['Cfo@Example.test']]);

        self::assertSame(201, $status);
        self::assertSame('Nobody notified holds Approver access in TriCab Finance, so no one on this list can complete the request.', self::dataOf($json)['warning']);
        self::assertSame(['cfo@example.test'], self::map(self::dataOf($json)['batch'])['notified_emails']);
    }

    public function test_with_sending_off_the_batch_is_created_nothing_is_mailed_and_the_warning_says_so(): void
    {
        $this->boot([]);

        [$status, $json] = $this->call('POST', '/approval-batches', self::PREPARE, ['invoice_ids' => ['i1']]);

        self::assertSame(201, $status);
        self::assertSame('Approval mail is switched off for Tri-AP, so no one was notified.', self::dataOf($json)['warning']);
        self::assertSame('Pending', self::map(self::dataOf($json)['batch'])['status']);
        self::assertSame([], $this->transport->standalone);
    }

    public function test_a_mail_failure_is_a_201_with_a_warning(): void
    {
        $this->transport->failWith = new FinanceMailTransportFailed('Microsoft Graph refused the message with status 500.');

        [$status, $json] = $this->call('POST', '/approval-batches', self::PREPARE, ['invoice_ids' => ['i1']]);

        self::assertSame(201, $status);
        self::assertSame('Approval was created, but notification email failed: Microsoft Graph refused the message with status 500.', self::dataOf($json)['warning']);
    }

    // ---- read ----------------------------------------------------------------------------

    public function test_the_list_and_the_read_answer_the_same_batch_and_an_unknown_one_is_404(): void
    {
        $batch = $this->create(['i1']);
        $id = $batch['id'];
        self::assertIsString($id);

        $list = $this->call('GET', '/approval-batches', self::READ)[1];
        $one = $this->call('GET', '/approval-batches/' . $id, self::READ)[1];

        self::assertSame([$batch], $list['data']);
        self::assertSame($batch, $one['data']);
        [$status, $json] = $this->call('GET', '/approval-batches/nope', self::READ);
        self::assertSame(404, $status);
        self::assertSame(['FINANCE_AP_NOT_FOUND', 'Not found'], [self::errorOf($json)['code'], self::errorOf($json)['message']]);
    }

    public function test_a_batch_with_an_invoice_out_of_scope_is_not_listed_and_is_a_404(): void
    {
        $visible = $this->create(['i1']);
        $hidden = $this->create(['i1', 'other']);
        $this->scope('ap@example.test');

        [, $list] = $this->call('GET', '/approval-batches', self::READ);

        self::assertSame([$visible['id']], array_column(self::rows($list), 'id'));
        self::assertSame(404, $this->call('GET', '/approval-batches/' . self::idOf($hidden), self::READ)[0]);
        self::assertSame(200, $this->call('GET', '/approval-batches/' . self::idOf($visible), self::READ)[0]);
    }

    /**
     * @param array<string, mixed> $batch
     */
    private static function idOf(array $batch): string
    {
        self::assertIsString($batch['id']);

        return $batch['id'];
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private static function rows(array $json): array
    {
        $data = $json['data'];
        self::assertIsArray($data);

        return array_values(array_map(self::map(...), $data));
    }

    // ---- decide --------------------------------------------------------------------------

    public function test_a_decision_answers_the_whole_batch_with_the_notification_warning_merged_in(): void
    {
        $id = self::idOf($this->create());

        [$status, $json] = $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'approve', 'comment' => 'Looks right']);

        self::assertSame(200, $status);
        $data = self::dataOf($json);
        self::assertSame('Approved', $data['status']);
        self::assertSame('Looks right', $data['decision_comment']);
        self::assertSame('1', $data['decided_by']);
        self::assertArrayHasKey('notification_warning', $data);
        self::assertNull($data['notification_warning']);
        $decider = self::map($data['decided_by_user']);
        self::assertSame('1', $decider['id']);
        self::assertNotSame('', $decider['email']);
        self::assertNotNull($data['decided_at']);
        $invoices = $data['invoices'];
        self::assertIsArray($invoices);
        self::assertSame(['Approved', 'Approved'], array_column($invoices, 'approval_status'));
        $decisions = $data['decisions'];
        self::assertIsArray($decisions);
        self::assertSame(['Approved'], array_column($decisions, 'decision'));
        self::assertSame(['ann@example.test', 'bob@example.test'], $data['notified_emails']);
        self::assertCount(2, $this->transport->standalone);
        self::assertStringStartsWith('AP approval approved: 2 invoice(s)', $this->transport->standalone[1]->subject);
        $audit = $this->container()->get(ApAuditStore::class);
        self::assertInstanceOf(InMemoryApAuditStore::class, $audit);
        self::assertSame(['approval_requested', 'approval_approved'], array_map(static fn ($e): string => $e->action, $audit->forEntity('approval_batch', $id)));
    }

    public function test_the_second_decision_is_a_409_carrying_the_batch_and_sends_nothing(): void
    {
        $id = self::idOf($this->create());
        $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'reject', 'comment' => 'No']);
        $mails = count($this->transport->standalone);

        [$status, $json] = $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'approve']);

        self::assertSame(409, $status);
        $error = self::errorOf($json);
        self::assertSame('FINANCE_AP_CONFLICT', $error['code']);
        self::assertSame('This request was already rejected', $error['message']);
        $batch = self::map($error['batch']);
        self::assertSame(['Rejected', 'No'], [$batch['status'], $batch['decision_comment']]);
        self::assertSame($mails, count($this->transport->standalone));
    }

    public function test_the_decision_word_is_validated_and_an_unknown_batch_is_404(): void
    {
        $id = self::idOf($this->create());

        [$status, $json] = $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'maybe']);
        self::assertSame(422, $status);
        self::assertSame('decision must be approve or reject', self::errorOf($json)['message']);
        self::assertSame(422, $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, [])[0]);
        self::assertSame(404, $this->call('POST', '/approval-batches/nope/decision', self::DECIDE, ['decision' => 'approve'])[0]);
    }

    public function test_a_decision_on_a_batch_out_of_the_persons_scope_is_a_404(): void
    {
        $id = self::idOf($this->create(['i1', 'other']));
        $this->scope('ap@example.test');

        [$status, $json] = $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'approve']);

        self::assertSame(404, $status);
        self::assertSame('Not found', self::errorOf($json)['message']);
    }

    public function test_with_sending_off_the_decision_is_recorded_and_the_notification_warning_says_so(): void
    {
        $this->boot([]);
        $id = self::idOf($this->create());

        [$status, $json] = $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'reject']);

        self::assertSame(200, $status);
        $data = self::dataOf($json);
        self::assertSame('Rejected', $data['status']);
        self::assertSame('Approval mail is switched off for Tri-AP, so no one was notified.', $data['notification_warning']);
        self::assertSame([], $this->transport->standalone);
    }

    public function test_a_mail_failure_on_the_decision_is_a_200_with_a_notification_warning(): void
    {
        $id = self::idOf($this->create());
        $this->transport->failWith = new FinanceMailTransportFailed('Down.');

        [$status, $json] = $this->call('POST', '/approval-batches/' . $id . '/decision', self::DECIDE, ['decision' => 'approve']);

        self::assertSame(200, $status);
        self::assertSame('The decision was recorded, but the Preparer notification failed: Down.', self::dataOf($json)['notification_warning']);
        self::assertSame('Approved', self::dataOf($json)['status']);
    }
}
