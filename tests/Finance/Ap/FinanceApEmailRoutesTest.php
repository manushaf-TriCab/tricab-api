<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ap\InMemoryApAuditStore;
use TriCab\Api\Finance\Ap\InMemoryApEmailStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApEmailStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApEmail;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScopeReader;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailboxRegistry;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;

/**
 * The AP Mailbox's routes (T6.6) through the real app on the in-memory stores and a fake mailbox: one permission each, the
 * mailbox scope's 404s, every validation message, paging, the invoice links, the live reads with their headers and the bulk
 * limits. The person is user 1, `thilanh@tricab.com`.
 */
final class FinanceApEmailRoutesTest extends TestCase
{
    private const BASE = '/api/v1/finance/ap';
    private const ON = ['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP_ENABLED' => 'true'];

    private const MAIL = Module::PERMISSION_AP_MAIL_READ;
    private const WRITE = Module::PERMISSION_AP_INVOICES_WRITE;

    /** @var App<ContainerInterface> */
    private App $app;

    private InMemoryMailboxReader $mailbox;

    protected function setUp(): void
    {
        $this->app = TestAppFactory::create(self::ON);
        $container = $this->app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->mailbox = new InMemoryMailboxReader();
        $container->set(MailboxReader::class, $this->mailbox);
        $container->set(FinanceMailboxRegistry::class, new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000a', 'ap@example.test', 'Accounts Payable', 'AP', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('0000000000000000000000000000000c', 'closed@example.test', 'Closed', 'AP', true, ['AP' => ['read' => false, 'send' => false]]),
        ]));
    }

    private function emails(): InMemoryApEmailStore
    {
        $store = $this->app->getContainer()->get(ApEmailStore::class);
        self::assertInstanceOf(InMemoryApEmailStore::class, $store);

        return $store;
    }

    private function invoices(): InMemoryApInvoiceStore
    {
        $store = $this->app->getContainer()->get(ApInvoiceStore::class);
        self::assertInstanceOf(InMemoryApInvoiceStore::class, $store);

        return $store;
    }

    private function audit(): InMemoryApAuditStore
    {
        $store = $this->app->getContainer()->get(ApAuditStore::class);
        self::assertInstanceOf(InMemoryApAuditStore::class, $store);

        return $store;
    }

    private function mailAudit(): InMemoryFinanceAuditLog
    {
        $log = $this->app->getContainer()->get(FinanceAuditLog::class);
        self::assertInstanceOf(InMemoryFinanceAuditLog::class, $log);

        return $log;
    }

    private function seed(string $id, mixed ...$over): ApEmail
    {
        $email = new ApEmail(
            $id,
            'msg-' . $id,
            'ap@example.test',
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            ApEmailWorld::utc('2026-10-05 02:00:00'),
            receivedAt: ApEmailWorld::utc('2026-10-05 01:00:00'),
            senderName: 'Vera Vendor',
            senderEmail: 'billing@vendor.example.test',
            subject: 'Invoice ' . $id,
            bodyPreview: 'Preview ' . $id,
            hasAttachments: true,
        );
        $email = $over === [] ? $email : $email->with(...$over);
        $this->emails()->insert($email);

        return $email;
    }

    /**
     * @param list<\TriCab\Core\Shared\Mailbox\MailboxAttachment> $attachments
     */
    private function live(string $graphId, string $body = '<p>Hi</p>', array $attachments = []): void
    {
        $this->mailbox->add('ap@example.test', new MailboxMessage(
            new MailboxMessageSummary($graphId, 'Live subject', 'billing@vendor.example.test', 'Vera Vendor', ApEmailWorld::utc('2026-10-05 01:00:00'), $attachments !== [], 'preview'),
            MailboxMessage::BODY_HTML,
            $body,
            [new MailboxRecipient('ap@example.test', 'AP')],
        ), $attachments);
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

    private function scope(string ...$mailboxes): void
    {
        $scopes = $this->app->getContainer()->get(FinanceAccessScopeReader::class);
        self::assertInstanceOf(InMemoryFinanceAccessScopeReader::class, $scopes);
        foreach ($mailboxes as $mailbox) {
            $scopes->grant(1, 'AP', FinanceAccessScope::TYPE_MAILBOX, $mailbox);
        }
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = TestAppFactory::request($method, self::BASE . $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
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
     * The `emails` of a bulk answer.
     *
     * @param array<array-key, mixed> $answer
     * @return list<array<string, mixed>>
     */
    private static function emailRows(array $answer): array
    {
        return self::rowsOf($answer['emails'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(mixed $rows): array
    {
        self::assertIsArray($rows);
        $out = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $one = [];
            foreach ($row as $key => $value) {
                $one[(string) $key] = $value;
            }
            $out[] = $one;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function error(ResponseInterface $response): array
    {
        $json = TestAppFactory::json($response);
        self::assertFalse($json['success']);
        $error = $json['error'];
        self::assertIsArray($error);
        self::assertIsString($error['code']);
        self::assertIsString($error['message']);

        return [$error['code'], $error['message']];
    }

    // ---- the switch and the permissions ------------------------------------------------------------

    public function test_with_the_ap_switch_off_the_mailbox_is_not_reachable(): void
    {
        $this->app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true']);
        $this->grant([self::MAIL, self::WRITE]);

        foreach ([['GET', '/emails'], ['GET', '/emails/stats'], ['POST', '/emails/bulk'], ['GET', '/emails/x/body'], ['PATCH', '/emails/x']] as [$method, $path]) {
            self::assertSame(404, $this->call($method, $path)->getStatusCode(), $method . ' ' . $path);
        }
    }

    public function test_a_reader_reads_but_cannot_change_and_a_writer_alone_cannot_read(): void
    {
        $this->seed('e1');
        $reads = [['GET', '/emails'], ['GET', '/emails/stats'], ['GET', '/emails/e1/body'], ['GET', '/emails/e1/attachments'], ['GET', '/emails/e1/attachments/a1/file']];
        $writes = [['PATCH', '/emails/e1'], ['POST', '/emails/bulk']];

        $this->grant([self::MAIL]);
        foreach ($writes as [$method, $path]) {
            self::assertSame(403, $this->call($method, $path, ['classification' => 'Other'])->getStatusCode(), $method . ' ' . $path);
        }
        self::assertSame(200, $this->call('GET', '/emails')->getStatusCode());

        $this->grant([self::WRITE, Module::PERMISSION_AP_INVOICES_READ]);
        foreach ($reads as [$method, $path]) {
            self::assertSame(403, $this->call($method, $path)->getStatusCode(), $method . ' ' . $path);
        }
        self::assertSame(200, $this->call('PATCH', '/emails/e1', ['classification' => 'Other'])->getStatusCode());
    }

    // ---- the list and the counts ---------------------------------------------------------------------

    public function test_the_list_is_a_bare_array_of_rows_with_the_pages_keys_and_the_invoice_ids(): void
    {
        $this->grant([self::MAIL]);
        $this->seed('e1');
        $this->invoices()->insert(ApInvoiceFixtures::invoice('i1', emailRecordId: 'e1'));

        $rows = self::data($this->call('GET', '/emails'));

        self::assertTrue(array_is_list($rows));
        $row = $rows[0];
        self::assertIsArray($row);
        self::assertSame(['i1'], $row['invoice_ids']);
        self::assertSame([], $row['duplicate_invoice_ids']);
        self::assertSame('msg-e1', $row['graph_message_id']);
        foreach (['id', 'received_at', 'mailbox', 'sender_name', 'sender_email', 'subject', 'body_preview', 'classification', 'classification_reason', 'review_status', 'has_attachments', 'deleted_at', 'deleted_by', 'proposed_response'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
    }

    public function test_the_list_pages_stably_to_a_short_page_and_caps_the_limit(): void
    {
        $this->grant([self::MAIL]);
        foreach (range(1, 7) as $n) {
            $this->seed('p' . $n);
        }

        $seen = [];
        for ($offset = 0; ; $offset += 3) {
            $page = self::data($this->call('GET', '/emails?limit=3&offset=' . $offset));
            foreach ($page as $row) {
                self::assertIsArray($row);
                $seen[] = $row['id'];
            }
            if (count($page) < 3) {
                break;
            }
        }

        self::assertSame(['p7', 'p6', 'p5', 'p4', 'p3', 'p2', 'p1'], $seen);
        self::assertCount(7, self::data($this->call('GET', '/emails?limit=5000')));
    }

    public function test_the_lists_validation_messages_are_422s_with_the_ap_code(): void
    {
        $this->grant([self::MAIL]);
        foreach ([
            '/emails?fromDate=2026-13-45' => 'Dates must use YYYY-MM-DD',
            '/emails?toDate=soon' => 'Dates must use YYYY-MM-DD',
            '/emails?fromDate=2026-10-06&toDate=2026-10-05' => 'From date cannot be after to date',
            '/emails?classification=Error' => 'classification must be Invoice or Other',
            '/emails?classification=' => 'classification must be Invoice or Other',
            '/emails?limit=abc' => 'limit must be a whole number',
            '/emails?limit=0' => 'limit must be at least 1',
            '/emails?offset=1.5' => 'offset must be a whole number',
            '/emails/stats?fromDate=bad' => 'Dates must use YYYY-MM-DD',
            '/emails/stats?fromDate=2026-10-06&toDate=2026-10-05' => 'From date cannot be after to date',
        ] as $path => $message) {
            $response = $this->call('GET', $path);
            self::assertSame(422, $response->getStatusCode(), $path);
            self::assertSame(['FINANCE_AP_INPUT_INVALID', $message], self::error($response), $path);
        }
    }

    public function test_the_list_applies_the_period_the_filters_the_bin_and_the_scope(): void
    {
        $this->grant([self::MAIL]);
        $this->seed('in', classification: ApEmail::INVOICE);
        $this->seed('out', receivedAt: ApEmailWorld::utc('2026-10-05 13:00:00'));
        $this->seed('bin', deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'), deletedBy: 'dee@example.test');
        $this->seed('other', mailbox: 'other@example.test');
        $this->scope('ap@example.test');

        $ids = static fn (array $rows): array => array_column($rows, 'id');
        self::assertSame(['out', 'in'], $ids(self::data($this->call('GET', '/emails'))));
        self::assertSame(['in'], $ids(self::data($this->call('GET', '/emails?fromDate=2026-10-05&toDate=2026-10-05'))));
        self::assertSame(['in'], $ids(self::data($this->call('GET', '/emails?classification=Invoice'))));
        self::assertSame(['bin'], $ids(self::data($this->call('GET', '/emails?deleted=1'))));
    }

    public function test_the_stats_answer_the_four_counts(): void
    {
        $this->grant([self::MAIL]);
        $this->seed('a', classification: ApEmail::INVOICE);
        $this->seed('b', classification: ApEmail::INVOICE);
        $this->seed('c');
        $this->seed('d', deletedAt: ApEmailWorld::utc('2026-10-06 00:00:00'));

        self::assertSame(['total' => 3, 'invoice' => 2, 'other' => 1, 'errors' => 0], self::data($this->call('GET', '/emails/stats')));
        self::assertSame(['total' => 0, 'invoice' => 0, 'other' => 0, 'errors' => 0], self::data($this->call('GET', '/emails/stats?fromDate=2020-01-01')));
    }

    // ---- reclassify ----------------------------------------------------------------------------------

    public function test_a_reclassification_answers_the_row_and_audits_it(): void
    {
        $this->grant([self::WRITE]);
        $this->seed('e1');

        $row = self::data($this->call('PATCH', '/emails/e1', ['classification' => 'Invoice', 'reason' => 'Looks right']));

        self::assertSame(['Invoice', 'Looks right', 'Verified'], [$row['classification'], $row['classification_reason'], $row['review_status']]);
        self::assertArrayNotHasKey('invoice_ids', $row);
        $events = $this->audit()->forEntity('email', 'e1');
        self::assertSame(['email_reclassified'], array_map(static fn ($e): string => $e->action, $events));
        self::assertSame(1, $events[0]->actorUserId);
    }

    public function test_reclassify_refusals_and_404s(): void
    {
        $this->grant([self::WRITE]);
        $this->seed('e1');
        $this->seed('theirs', mailbox: 'other@example.test');
        $this->scope('ap@example.test');

        $bad = $this->call('PATCH', '/emails/e1', ['classification' => 'Maybe']);
        self::assertSame(422, $bad->getStatusCode());
        self::assertSame(['FINANCE_AP_INPUT_INVALID', 'classification must be Invoice or Other'], self::error($bad));
        self::assertSame(['FINANCE_AP_INPUT_INVALID', 'reason must be text'], self::error($this->call('PATCH', '/emails/e1', ['classification' => 'Other', 'reason' => ['x']])));
        foreach (['/emails/nope', '/emails/theirs'] as $path) {
            $response = $this->call('PATCH', $path, ['classification' => 'Other']);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame(['FINANCE_AP_NOT_FOUND', 'Not found'], self::error($response));
        }
        self::assertSame(404, $this->call('PATCH', '/emails/nope', ['classification' => 'Bad'])->getStatusCode(), 'the 404 comes before the validation, as the source');
    }

    // ---- bulk ----------------------------------------------------------------------------------------

    public function test_bulk_runs_the_three_actions_and_answers_updated_skipped_and_the_rows(): void
    {
        $this->grant([self::WRITE]);
        $this->seed('e1');
        $this->seed('e2');
        $this->seed('theirs', mailbox: 'other@example.test');
        $this->scope('ap@example.test');

        $binned = self::data($this->call('POST', '/emails/bulk', ['action' => 'bin', 'ids' => ['e1', 'e2', 'e1', 'theirs', 'nope']]));
        self::assertSame(2, $binned['updated']);
        self::assertSame(2, $binned['skipped']);
        self::assertCount(2, self::emailRows($binned));
        self::assertSame('thilanh@tricab.com', self::emailRows($binned)[0]['deleted_by']);

        $restored = self::data($this->call('POST', '/emails/bulk', ['action' => 'restore', 'ids' => ['e1']]));
        self::assertSame(1, $restored['updated']);
        self::assertNull(self::emailRows($restored)[0]['deleted_at']);

        $classified = self::data($this->call('POST', '/emails/bulk', ['action' => 'classify', 'ids' => ['e1', 'e2'], 'classification' => 'Invoice']));
        self::assertSame(2, $classified['updated']);
        $first = self::emailRows($classified)[0];
        self::assertSame(['Manually classified', 'Verified'], [$first['classification_reason'], $first['review_status']]);
        $actions = array_map(static fn ($e): string => $e->action, $this->audit()->all());
        self::assertSame(['email_binned', 'email_binned', 'email_restored', 'email_reclassified', 'email_reclassified'], $actions);
        self::assertSame([], $this->audit()->forEntity('email', 'theirs'));
    }

    public function test_bulk_validation_messages_and_the_thousand_limit(): void
    {
        $this->grant([self::WRITE]);
        foreach ([
            [['action' => 'purge', 'ids' => ['a']], 'action must be classify, bin or restore'],
            [['action' => 'bin'], 'ids must be a non-empty list of email ids'],
            [['action' => 'bin', 'ids' => []], 'ids must be a non-empty list of email ids'],
            [['action' => 'bin', 'ids' => ['a', 3]], 'ids must be a non-empty list of email ids'],
            [['action' => 'bin', 'ids' => array_map(strval(...), range(1, 1001))], 'At most 1000 emails at a time'],
            [['action' => 'classify', 'ids' => ['a'], 'classification' => 'Error'], 'classification must be Invoice or Other'],
        ] as [$body, $message]) {
            $response = $this->call('POST', '/emails/bulk', $body);
            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertSame(['FINANCE_AP_INPUT_INVALID', $message], self::error($response));
        }
        self::assertSame(['updated' => 0, 'skipped' => 1000, 'emails' => []], self::data($this->call('POST', '/emails/bulk', ['action' => 'bin', 'ids' => array_map(strval(...), range(1, 1000))])));
    }

    // ---- the live reads --------------------------------------------------------------------------------

    public function test_the_body_is_live_untrusted_and_not_cached(): void
    {
        $this->grant([self::MAIL]);
        $this->seed('e1', graphMessageId: 'imm-1');
        $this->live('imm-1', '<script>alert(1)</script>');

        $response = $this->call('GET', '/emails/e1/body');
        $body = self::data($response);

        self::assertSame('<script>alert(1)</script>', $body['content']);
        self::assertSame('html', $body['content_type']);
        self::assertSame([['name' => 'AP', 'address' => 'ap@example.test']], $body['to']);
        self::assertSame([], $body['cc']);
        self::assertSame(['name' => 'Vera Vendor', 'address' => 'billing@vendor.example.test'], $body['from']);
        self::assertTrue($body['untrusted']);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame(['finance.mail.message_read'], array_map(static fn ($e): string => $e->action, $this->mailAudit()->events));
    }

    public function test_the_live_reads_are_404_out_of_scope_403_without_a_grant_and_502_when_the_mailbox_fails(): void
    {
        $this->grant([self::MAIL]);
        $this->seed('theirs', mailbox: 'other@example.test');
        $this->seed('closed', mailbox: 'closed@example.test');
        $this->seed('gone', graphMessageId: 'missing');
        $this->scope('ap@example.test', 'closed@example.test');

        foreach (['/emails/theirs/body', '/emails/nope/attachments', '/emails/theirs/attachments/a/file'] as $path) {
            $response = $this->call('GET', $path);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame(['FINANCE_AP_NOT_FOUND', 'Not found'], self::error($response));
        }
        $denied = $this->call('GET', '/emails/closed/body');
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('FINANCE_AP_FORBIDDEN', self::error($denied)[0]);

        foreach (['/emails/gone/body' => 'message', '/emails/gone/attachments' => 'attachments', '/emails/gone/attachments/a/file' => 'attachment'] as $path => $what) {
            $response = $this->call('GET', $path);
            self::assertSame(502, $response->getStatusCode(), $path);
            [$code, $message] = self::error($response);
            self::assertSame('FINANCE_AP_UPSTREAM_FAILED', $code);
            self::assertStringStartsWith('Could not fetch the ' . $what . ' from Outlook: ', $message);
        }
    }

    public function test_the_attachment_list_and_file_with_the_sandbox_headers(): void
    {
        $this->grant([self::MAIL]);
        $this->seed('e1', graphMessageId: 'imm-1');
        $this->live('imm-1', attachments: [
            ApEmailWorld::file('a1', 'bill.pdf', 'application/pdf', '%PDF-1.7'),
            ApEmailWorld::file('a2', 'logo.png', 'image/png', 'png', true),
            ApEmailWorld::file('a3', 'page.html', 'text/html', '<script>1</script>'),
            ApEmailWorld::file('a4', 'empty.pdf', 'application/pdf', ''),
        ]);

        self::assertSame(['a1', 'a3', 'a4'], array_column(self::data($this->call('GET', '/emails/e1/attachments')), 'id'));
        self::assertSame(['id', 'name', 'content_type', 'size'], array_keys(self::rowsOf(self::data($this->call('GET', '/emails/e1/attachments')))[0]));

        $inline = $this->call('GET', '/emails/e1/attachments/a1/file?inline=1');
        $inline->getBody()->rewind();
        self::assertSame(200, $inline->getStatusCode());
        self::assertSame('%PDF-1.7', (string) $inline->getBody());
        self::assertSame('application/pdf', $inline->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('inline; filename="bill.pdf"', $inline->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $inline->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $inline->getHeaderLine('Content-Security-Policy'));
        self::assertSame('private, no-store', $inline->getHeaderLine('Cache-Control'));

        $download = $this->call('GET', '/emails/e1/attachments/a1/file');
        self::assertSame('application/octet-stream', $download->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="bill.pdf"', $download->getHeaderLine('Content-Disposition'));

        $html = $this->call('GET', '/emails/e1/attachments/a3/file?inline=1');
        self::assertSame('application/octet-stream', $html->getHeaderLine('Content-Type'), 'HTML is forced to a download');
        self::assertStringStartsWith('attachment;', $html->getHeaderLine('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $html->getHeaderLine('Content-Security-Policy'));

        $empty = $this->call('GET', '/emails/e1/attachments/a4/file');
        self::assertSame(404, $empty->getStatusCode());
        self::assertSame(['FINANCE_AP_NOT_FOUND', 'This attachment has no file content'], self::error($empty));
        self::assertSame(4, count(array_filter($this->mailAudit()->events, static fn ($e): bool => $e->action === 'finance.mail.attachment_read')));
    }
}
