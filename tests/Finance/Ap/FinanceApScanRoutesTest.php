<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApEmailStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReader;

/**
 * The scan route (T6.7) through the real app on the in-memory stores, a fake mailbox and a queued AI client: the switch, the
 * one permission it needs, every refusal with its code and words, the three modes end to end, and the 409s (the period already
 * scanned carries `priorRuns` beside `code` and `message`; a scan in progress does not). The person is user 1.
 */
final class FinanceApScanRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap';
    private const ON = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true'];
    private const MAIL = Module::PERMISSION_AP_MAIL_READ;

    /** @var App<ContainerInterface> */
    private App $app;

    private InMemoryMailboxReader $mailbox;

    private QueuedApAiClient $ai;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(self::ON);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->mailbox = new InMemoryMailboxReader();
        $this->ai = new QueuedApAiClient();
        $container->set(MailboxReader::class, $this->mailbox);
        $container->set(FinanceAiClient::class, $this->ai);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000a', 'ap@example.test', 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('0000000000000000000000000000000c', 'closed@example.test', 'Closed', 'AP', true, ['AP' => ['read' => false, 'send' => false]]),
        ]));
    }

    /**
     * @param list<string> $permissions
     */
    private function grant(array $permissions): void
    {
        $users = $this->app->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, ...$permissions], ['EMPLOYEE']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function scan(array $body): ResponseInterface
    {
        return $this->app->handle(TestAppFactory::request('POST', self::BASE . '/scan')->withParsedBody($body));
    }

    private function live(string $id, string $mailbox = 'ap@example.test', bool $withPdf = true): void
    {
        $this->mailbox->add($mailbox, new MailboxMessage(
            new MailboxMessageSummary($id, 'Invoice please', 'billing@vendor.example.test', 'Vera Vendor', ApEmailWorld::utc('2026-10-05 01:00:00'), $withPdf, 'Preview'),
            MailboxMessage::BODY_TEXT,
            'Body',
        ), $withPdf ? [ApEmailWorld::file('a1', 'inv.pdf', 'application/pdf', '%PDF route')] : []);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function data(ResponseInterface $response): array
    {
        $json = TestAppFactory::json($response);
        self::assertTrue($json['success'], json_encode($json) ?: '');
        self::assertIsArray($json['data']);

        return $json['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(ResponseInterface $response): array
    {
        $json = TestAppFactory::json($response);
        self::assertFalse($json['success']);
        $error = $json['error'];
        self::assertIsArray($error);
        $out = [];
        foreach ($error as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    public function test_with_the_ap_switch_off_the_scan_is_not_reachable(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true']);
        $this->grant([self::MAIL]);

        self::assertSame(404, $this->scan(['mode' => 'list'])->getStatusCode());
    }

    public function test_the_scan_needs_mail_read_and_nothing_else(): void
    {
        $this->grant([Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_INVOICES_WRITE, Module::PERMISSION_AP_MAIL_SEND]);
        $refused = $this->scan(['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);
        self::assertSame(403, $refused->getStatusCode());

        $this->grant([self::MAIL]);
        self::assertSame(200, $this->scan(['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05'])->getStatusCode());
    }

    public function test_every_refusal_has_its_code_and_the_sources_words(): void
    {
        $this->grant([self::MAIL]);
        foreach ([
            [['mode' => 'list'], 422, 'FINANCE_AP_INPUT_INVALID', 'fromDate and toDate are required'],
            [['mode' => 'list', 'fromDate' => 'x', 'toDate' => '2026-10-05'], 422, 'FINANCE_AP_INPUT_INVALID', 'Dates must use YYYY-MM-DD'],
            [['mode' => 'list', 'fromDate' => '2026-10-06', 'toDate' => '2026-10-05'], 422, 'FINANCE_AP_INPUT_INVALID', 'From date cannot be after to date'],
            [['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05', 'mailboxes' => ['closed@example.test']], 422, 'FINANCE_AP_INPUT_INVALID', 'You do not have access to the active AP mailbox'],
            [['mode' => 'process', 'messageId' => 'm1'], 422, 'FINANCE_AP_INPUT_INVALID', 'messageId and mailbox are required'],
            [['mode' => 'process', 'messageId' => 'm1', 'mailbox' => 'closed@example.test'], 403, 'FINANCE_AP_FORBIDDEN', 'AP is not allowed to read the mailbox closed@example.test.'],
            [['mode' => 'process', 'messageId' => 'gone', 'mailbox' => 'ap@example.test'], 502, 'FINANCE_AP_UPSTREAM_FAILED', 'Could not fetch the message from Outlook: The mailbox, message or attachment was not found.'],
            [['mode' => 'complete', 'scanRunId' => 'nope'], 404, 'FINANCE_AP_NOT_FOUND', 'Scan run not found'],
            [['mode' => 'complete'], 404, 'FINANCE_AP_NOT_FOUND', 'Scan run not found'],
            [['mode' => 'sideways'], 422, 'FINANCE_AP_INPUT_INVALID', 'Unknown mode: sideways'],
            [[], 422, 'FINANCE_AP_INPUT_INVALID', 'Unknown mode: None'],
        ] as [$body, $status, $code, $message]) {
            $response = $this->scan($body);
            self::assertSame($status, $response->getStatusCode(), $message);
            $error = self::error($response);
            self::assertSame([$code, $message], [$error['code'], $error['message']]);
        }
    }

    public function test_the_three_modes_end_to_end(): void
    {
        $this->grant([self::MAIL]);
        $this->live('m1');

        $listed = self::data($this->scan(['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']));
        $run = $listed['scanRunId'];
        self::assertIsString($run);
        self::assertSame([['id' => 'm1', 'subject' => 'Invoice please', 'from' => 'billing@vendor.example.test', 'receivedDateTime' => '2026-10-05T01:00:00Z', 'mailbox' => 'ap@example.test', 'hasAttachments' => true]], $listed['messages']);
        self::assertSame([], $listed['mailboxErrors']);

        $processed = self::data($this->scan(['mode' => 'process', 'messageId' => 'm1', 'mailbox' => 'ap@example.test', 'scanRunId' => $run]));
        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $processed);

        $closed = self::data($this->scan(['mode' => 'complete', 'scanRunId' => $run]));
        self::assertSame([$run, 'Complete', 1, 1, 1], [$closed['id'], $closed['status'], $closed['total_messages'], $closed['processed_messages'], $closed['invoice_emails']]);

        $invoices = $this->app->getContainer()->get(ApInvoiceStore::class);
        self::assertInstanceOf(InMemoryApInvoiceStore::class, $invoices);
        $emails = $this->app->getContainer()->get(ApEmailStore::class);
        self::assertInstanceOf(InMemoryApEmailStore::class, $emails);
        self::assertNotNull($emails->findByMessage('ap@example.test', 'm1'));
        self::assertNotNull($invoices->findCaptured('m1', 'a1', 'inv.pdf'));
    }

    public function test_a_period_already_scanned_is_a_409_whose_error_carries_prior_runs_beside_code_and_message(): void
    {
        $this->grant([self::MAIL]);
        $this->live('m1');
        $first = self::data($this->scan(['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']));
        $this->scan(['mode' => 'complete', 'scanRunId' => $first['scanRunId']]);

        $response = $this->scan(['mode' => 'list', 'fromDate' => '2026-10-04', 'toDate' => '2026-10-06']);

        self::assertSame(409, $response->getStatusCode());
        $error = self::error($response);
        self::assertSame('FINANCE_AP_ALREADY_SCANNED', $error['code']);
        self::assertSame('This period has already been scanned. Scanning it again may create duplicate invoices.', $error['message']);
        self::assertIsArray($error['priorRuns']);
        self::assertCount(1, $error['priorRuns']);
        $prior = $error['priorRuns'][0];
        self::assertIsArray($prior);
        self::assertSame($first['scanRunId'], $prior['id']);
        self::assertSame(['id', 'fromDate', 'toDate', 'scannedAt', 'scannedBy', 'totalMessages', 'invoiceEmails', 'status'], array_keys($prior));
        self::assertSame(['2026-10-05', '2026-10-05', 1, 0, 'Complete'], [$prior['fromDate'], $prior['toDate'], $prior['totalMessages'], $prior['invoiceEmails'], $prior['status']]);

        // "Scan anyway": the page sends confirmRescan.
        self::assertSame(200, $this->scan(['mode' => 'list', 'fromDate' => '2026-10-04', 'toDate' => '2026-10-06', 'confirmRescan' => true])->getStatusCode());
    }

    public function test_a_scan_in_progress_is_a_409_without_prior_runs_or_the_rescan_words(): void
    {
        $this->grant([self::MAIL]);
        $this->live('m1');
        $this->scan(['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);

        $response = $this->scan(['mode' => 'list', 'fromDate' => '2026-10-07', 'toDate' => '2026-10-07']);

        self::assertSame(409, $response->getStatusCode());
        $error = self::error($response);
        self::assertSame('FINANCE_AP_CONFLICT', $error['code']);
        self::assertArrayNotHasKey('priorRuns', $error);
        self::assertDoesNotMatchRegularExpression('/already[ _]?scanned/i', json_encode($error) ?: '');
    }

    public function test_a_scoped_person_cannot_scan_a_mailbox_outside_their_scope(): void
    {
        $this->grant([self::MAIL]);
        $scopes = $this->app->getContainer()->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        $scopes->grant(1, 'AP', FinanceAccessScope::TYPE_MAILBOX, 'other@example.test');
        $this->live('m1');

        $list = $this->scan(['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);
        self::assertSame(422, $list->getStatusCode());
        self::assertSame('You do not have access to the active AP mailbox', self::error($list)['message']);

        $process = $this->scan(['mode' => 'process', 'messageId' => 'm1', 'mailbox' => 'AP@example.test']);
        self::assertSame(403, $process->getStatusCode());
        self::assertSame(['FINANCE_AP_FORBIDDEN', 'You do not have access to this mailbox'], [self::error($process)['code'], self::error($process)['message']]);
    }

    public function test_the_ap_mailbox_captures_by_hand_with_force_and_no_run(): void
    {
        $this->grant([self::MAIL]);
        $this->live('m1');
        $this->ai->notInvoice();

        $answer = self::data($this->scan(['mode' => 'process', 'messageId' => 'm1', 'mailbox' => 'ap@example.test', 'force' => true]));

        self::assertSame(['captured' => 1, 'errors' => 0, 'duplicates' => 0, 'classification' => 'Invoice'], $answer);
        $invoices = $this->app->getContainer()->get(ApInvoiceStore::class);
        self::assertInstanceOf(InMemoryApInvoiceStore::class, $invoices);
        $invoice = $invoices->findCaptured('m1', 'a1', 'inv.pdf');
        self::assertSame('Captured by hand from the AP Mailbox; the AI did not read this file as an invoice', $invoice?->reviewReason);
    }
}
