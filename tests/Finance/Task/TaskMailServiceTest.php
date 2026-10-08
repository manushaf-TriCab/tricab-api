<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskMailLinks;
use TriCab\Api\Finance\Task\InMemoryTaskReportRecipientStore;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Access\FinanceAccessScope;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;
use TriCab\Core\Finance\Task\Service\TaskMailService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * Task mail over the real shared mail service on its in-memory twins and a recording transport:
 * no test here sends mail or touches a network (T2.6).
 */
final class TaskMailServiceTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemoryFinanceMailLedger $ledger;

    private RecordingFinanceMailTransport $transport;

    private InMemoryTaskMailLinks $links;

    private InMemoryTaskReportRecipientStore $recipients;

    private InMemoryFinanceAccessScopeReader $scopes;

    private InMemoryFinanceTaskMemberDirectory $directory;

    private TaskService $tasks;

    private TaskMailService $mail;

    private TaskActor $una;

    private TaskActor $max;

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->directory->add(11, 'Una User', 'una@example.test');
        $this->directory->add(12, 'Max Manager', 'max@example.test');
        $this->directory->add(14, 'Other Person', 'other@example.test');
        $this->store = new InMemoryTaskStore();
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00.5'), 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $this->store, $this->directory, $time);

        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->links = new InMemoryTaskMailLinks();
        $this->recipients = new InMemoryTaskReportRecipientStore();
        $this->scopes = new InMemoryFinanceAccessScopeReader();
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('m1', 'finance@example.test', 'Finance Team', '', true, ['TASK' => ['read' => false, 'send' => true]]),
            new FinanceMailbox('m2', 'ap@example.test', 'Accounts Payable', '', true, ['TASK' => ['read' => false, 'send' => true], 'AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m3', 'noteam@example.test', 'No Tri-Task grant', '', true, ['AP' => ['read' => true, 'send' => true]]),
            new FinanceMailbox('m4', 'old@example.test', 'Retired', '', false, ['TASK' => ['read' => false, 'send' => true]]),
        ]);
        $sender = new FinanceMailService($registry, $this->ledger, $this->transport, null, null);
        $this->mail = $this->service($sender, $registry, 'Finance@Example.test');

        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_MAIL_SEND];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL]);
    }

    private function service(FinanceMailService $sender, InMemoryFinanceMailboxRegistry $registry, string $from): TaskMailService
    {
        return new TaskMailService(
            $this->tasks,
            $this->directory,
            $this->recipients,
            $sender,
            $this->ledger,
            $registry,
            $this->scopes,
            $this->links,
            new TaskTime(FixedClock::utc('2026-10-07 01:00:00.5'), 'Australia/Melbourne'),
            $from,
        );
    }

    private function task(string $name = 'Bank rec', int $preparer = 11): string
    {
        $t = $this->tasks->create($this->max, ['name' => $name, 'preparer_id' => $preparer]);
        self::assertIsString($t['id']);

        return $t['id'];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function email(string $taskId, array $over = []): array
    {
        return $over + ['task_id' => $taskId, 'to' => 'una@example.test', 'subject' => 'Reminder', 'body' => "Hi Una,\n\nPlease finish."];
    }

    /**
     * @param list<mixed> $emails
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private function payload(array $emails, array $over = []): array
    {
        return $over + ['kind' => 'task_email', 'emails' => $emails];
    }

    // ==== mailboxes ============================================================================

    public function test_the_send_mailboxes_are_the_active_ones_with_a_tri_task_send_grant_default_first(): void
    {
        $list = $this->mail->sendMailboxes($this->una);

        self::assertSame('finance@example.test', $list['default']);
        self::assertSame(
            [['email' => 'finance@example.test', 'label' => 'Finance Team'], ['email' => 'ap@example.test', 'label' => 'Accounts Payable']],
            $list['mailboxes'],
        );
    }

    public function test_a_mailbox_scope_narrows_the_list_and_no_rows_means_all(): void
    {
        $this->scopes->grant(11, 'TASK', FinanceAccessScope::TYPE_MAILBOX, 'AP@example.test');

        self::assertSame(['ap@example.test'], array_column($this->mail->sendMailboxes($this->una)['mailboxes'], 'email'));
        self::assertCount(2, $this->mail->sendMailboxes($this->max)['mailboxes']);
        // Another app's scope does not narrow Tri-Task's.
        $this->scopes->grant(12, 'AP', FinanceAccessScope::TYPE_MAILBOX, 'noteam@example.test');
        self::assertCount(2, $this->mail->sendMailboxes($this->max)['mailboxes']);
    }

    // ==== a send ===============================================================================

    public function test_a_send_goes_through_the_shared_service_with_the_sources_html_the_actor_and_a_stored_key(): void
    {
        $id = $this->task();

        $out = $this->mail->send($this->una, $this->payload([$this->email($id, ['cc' => 'max@example.test'])]));

        self::assertSame(['sent' => 1, 'results' => [['to' => 'una@example.test', 'ok' => true]]], $out);
        self::assertCount(1, $this->transport->sent);
        $message = $this->transport->sent[0];
        self::assertSame('TASK', $message->application);
        self::assertSame('finance@example.test', $message->mailbox);
        self::assertSame(['una@example.test'], $message->to);
        self::assertSame(['max@example.test'], $message->cc);
        self::assertSame('Reminder', $message->subject);
        self::assertSame('HTML', $message->contentType);
        self::assertSame('<p style="margin:0 0 1em 0">Hi Una,</p><p style="margin:0 0 1em 0">Please finish.</p>', $message->body);
        self::assertSame(11, $message->actorUserId);

        $link = $this->links->all()[0];
        self::assertSame('task_email', $link->kind);
        self::assertSame($id, $link->taskId);
        self::assertSame('task-task_email:' . $link->id, $message->idempotencyKey, 'the key is stored in the ledger');
        $delivery = $this->ledger->findById($link->deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('sent', $delivery->status);
        self::assertSame('task-task_email:' . $link->id, $delivery->idempotencyKey);
    }

    public function test_the_default_sender_is_used_when_from_is_omitted_and_a_chosen_one_when_given(): void
    {
        $id = $this->task();
        $this->mail->send($this->una, $this->payload([$this->email($id)]));
        $this->mail->send($this->una, $this->payload([$this->email($id)], ['from' => ' AP@Example.test ']));

        self::assertSame(['finance@example.test', 'ap@example.test'], array_map(static fn (FinanceMailMessage $m): string => $m->mailbox, $this->transport->sent));
    }

    public function test_the_from_must_be_a_mailbox_tri_task_may_send_from_and_the_scope_allows(): void
    {
        $id = $this->task();
        foreach (['noteam@example.test', 'old@example.test', 'nobody@example.test'] as $from) {
            try {
                $this->mail->send($this->una, $this->payload([$this->email($id)], ['from' => $from]));
                self::fail('Expected a refusal for ' . $from);
            } catch (TaskForbidden $e) {
                self::assertStringContainsString($from, $e->getMessage());
            }
        }
        $this->scopes->grant(11, 'TASK', FinanceAccessScope::TYPE_MAILBOX, 'ap@example.test');
        try {
            $this->mail->send($this->una, $this->payload([$this->email($id)], ['from' => 'finance@example.test']));
            self::fail('Expected a refusal: the scope leaves finance@ out.');
        } catch (TaskForbidden) {
            $this->addToAssertionCount(1);
        }
        self::assertSame([], $this->transport->sent);
        self::assertSame([], $this->ledger->all());
    }

    public function test_with_no_configured_default_and_no_from_it_is_a_503_not_a_guess(): void
    {
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);
        $mail = $this->service(new FinanceMailService($registry, $this->ledger, $this->transport), $registry, '');

        try {
            $mail->send($this->una, $this->payload([$this->email($this->task())]));
            self::fail('Expected a refusal.');
        } catch (TaskUnavailable $e) {
            self::assertSame('No sending mailbox is configured', $e->getMessage());
            self::assertSame(503, $e->httpStatus());
        }
        self::assertSame('', $mail->sendMailboxes($this->una)['default']);
    }

    // ==== validation: all or nothing ===========================================================

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badRequests(): iterable
    {
        yield 'kind missing' => [['emails' => [['task_id' => 'x']]], 'kind must be task_email or reminder'];
        yield 'kind wrong' => [['kind' => 'daily_summary', 'emails' => [['task_id' => 'x']]], 'kind must be task_email or reminder'];
        yield 'no emails' => [['kind' => 'reminder'], 'No emails to send'];
        yield 'empty emails' => [['kind' => 'reminder', 'emails' => []], 'No emails to send'];
        yield 'emails not a list' => [['kind' => 'reminder', 'emails' => ['a' => 1]], 'No emails to send'];
        yield 'twenty-one emails' => [['kind' => 'reminder', 'emails' => array_fill(0, 21, ['task_id' => 'x'])], 'Up to 20 emails at once'];
    }

    /** @param array<string, mixed> $data */
    #[\PHPUnit\Framework\Attributes\DataProvider('badRequests')]
    public function test_a_malformed_request_is_refused_before_anything_happens(array $data, string $message): void
    {
        try {
            $this->mail->send($this->una, $data);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertSame([], $this->ledger->all());
    }

    public function test_one_bad_email_stops_the_whole_request_and_nothing_is_sent(): void
    {
        $id = $this->task();
        $cases = [
            ['Each email must be an object', ['not an object']],
            ['Every email needs a task_id', [['to' => 'una@example.test', 'subject' => 's', 'body' => 'b']]],
            ['Every email needs a recipient, subject and body', [$this->email($id, ['to' => ''])]],
            ['Every email needs a recipient, subject and body', [$this->email($id, ['subject' => '  '])]],
            ['Every email needs a recipient, subject and body', [$this->email($id, ['body' => " \n "])]],
            ['These addresses are not Tri-Task members or summary recipients: stranger@example.test', [$this->email($id, ['to' => 'una@example.test, stranger@example.test'])]],
            ['These addresses are not Tri-Task members or summary recipients: nope, x@y.test', [$this->email($id, ['to' => 'una@example.test', 'cc' => 'nope; x@y.test'])]],
        ];
        foreach ($cases as [$message, $emails]) {
            // The first email is good: a bad second one must still stop everything.
            $request = $this->payload([$this->email($id), ...$emails]);
            try {
                $this->mail->send($this->una, $request);
                self::fail('Expected a refusal: ' . $message);
            } catch (TaskInvalid $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->transport->sent);
        self::assertSame([], $this->ledger->all());
        self::assertSame([], $this->links->all());
    }

    public function test_each_email_needs_a_task_the_caller_may_see(): void
    {
        $mine = $this->task('Mine', 11);
        $theirs = $this->task('Theirs', 14);

        try {
            $this->mail->send($this->una, $this->payload([$this->email($mine), $this->email($theirs)]));
            self::fail('Expected a 404.');
        } catch (TaskNotFound $e) {
            self::assertSame('Task not found', $e->getMessage());
        }
        self::assertSame([], $this->transport->sent);
        self::assertSame(1, $this->mail->send($this->max, $this->payload([$this->email($theirs, ['to' => 'other@example.test'])]))['sent']);
    }

    public function test_a_summary_recipient_may_be_written_to_and_an_address_is_matched_in_any_case(): void
    {
        $id = $this->task();
        $now = new DateTimeImmutable('2026-10-07 01:00:00', new DateTimeZone('UTC'));
        $this->recipients->insert(new TaskReportRecipient('r1', 'cfo@example.test', 'The CFO', null, $now, $now));

        $out = $this->mail->send($this->una, $this->payload([$this->email($id, ['to' => ['CFO@Example.test', 'UNA@example.test']])]));

        self::assertSame(1, $out['sent']);
        self::assertSame(['cfo@example.test', 'una@example.test'], $this->transport->sent[0]->to);
    }

    public function test_up_to_twenty_emails_are_allowed(): void
    {
        $id = $this->task();

        $out = $this->mail->send($this->una, $this->payload(array_fill(0, 20, $this->email($id))));

        self::assertSame(20, $out['sent']);
        self::assertCount(20, $this->links->all());
        self::assertCount(20, array_unique(array_map(static fn (FinanceMailMessage $m): ?string => $m->idempotencyKey, $this->transport->sent)), 'every email has its own key');
    }

    // ==== attachments ==========================================================================

    public function test_attachments_are_decoded_and_reach_the_message_and_only_their_names_the_ledger(): void
    {
        $id = $this->task();

        $this->mail->send($this->una, $this->payload([$this->email($id)], ['attachments' => [['name' => 'a.pdf', 'content_type' => 'application/pdf', 'data' => base64_encode('PDF')]]]));

        self::assertSame('PDF', $this->transport->sent[0]->attachments[0]->content);
        self::assertSame('a.pdf', $this->transport->sent[0]->attachments[0]->name);
        self::assertSame(['a.pdf'], $this->ledger->all()[0]->attachmentNames);
    }

    public function test_a_bad_attachment_stops_the_request(): void
    {
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('a/b.pdf is not a usable file name');
        $this->mail->send($this->una, $this->payload([$this->email($this->task())], ['attachments' => [['name' => 'a/b.pdf', 'data' => 'YQ==']]]));
    }

    // ==== results per email, failures and "mail is off" ========================================

    public function test_a_failed_email_does_not_stop_the_rest_and_is_reported_with_its_reason(): void
    {
        $id = $this->task();
        $failing = new class () implements FinanceMailTransport {
            private int $calls = 0;

            public function deliver(FinanceMailMessage $message): void
            {
                if (++$this->calls === 2) {
                    throw new FinanceMailTransportFailed('Graph answered 403 ErrorAccessDenied');
                }
            }
        };
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);
        $mail = $this->service(new FinanceMailService($registry, $this->ledger, $failing), $registry, 'finance@example.test');

        $out = $mail->send($this->una, $this->payload([$this->email($id, ['to' => 'una@example.test']), $this->email($id, ['to' => 'una@example.test, max@example.test']), $this->email($id, ['to' => 'una@example.test'])]));

        self::assertSame(2, $out['sent']);
        self::assertSame(
            [
                ['to' => 'una@example.test', 'ok' => true],
                ['to' => 'una@example.test, max@example.test', 'ok' => false, 'error' => 'Graph answered 403 ErrorAccessDenied'],
                ['to' => 'una@example.test', 'ok' => true],
            ],
            $out['results'],
        );
        $statuses = array_map(static fn ($d): string => $d->status, $this->ledger->all());
        self::assertSame(['sent', 'failed', 'sent'], $statuses);
        self::assertCount(3, $this->links->all(), 'the failed one is linked too, so the history shows it');
    }

    public function test_with_mail_switched_off_nothing_is_reported_as_sent(): void
    {
        $id = $this->task();
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);
        $mail = $this->service(new FinanceMailService($registry, $this->ledger, new DisabledFinanceMailTransport()), $registry, 'finance@example.test');

        $out = $mail->send($this->una, $this->payload([$this->email($id), $this->email($id)]));

        self::assertSame(0, $out['sent']);
        self::assertSame(
            [
                ['to' => 'una@example.test', 'ok' => false, 'error' => 'Mail is switched off', 'suppressed' => true],
                ['to' => 'una@example.test', 'ok' => false, 'error' => 'Mail is switched off', 'suppressed' => true],
            ],
            $out['results'],
        );
        self::assertSame(['suppressed', 'suppressed'], array_map(static fn ($d): string => $d->status, $this->ledger->all()));
        self::assertCount(2, $this->links->all());
    }

    public function test_an_invalid_message_from_the_shared_rules_is_a_per_email_failure(): void
    {
        $id = $this->task();

        $out = $this->mail->send($this->una, $this->payload([$this->email($id, ['subject' => str_repeat('s', 501)]), $this->email($id)]));

        self::assertSame(1, $out['sent']);
        self::assertFalse($out['results'][0]['ok']);
        $error = $out['results'][0]['error'];
        self::assertIsString($error);
        self::assertStringContainsString('subject', $error);
        self::assertTrue($out['results'][1]['ok']);
    }

    // ==== history ==============================================================================

    public function test_the_task_mail_history_is_newest_first_from_the_ledger_with_no_body(): void
    {
        $id = $this->task();
        $clock = FixedClock::utc('2026-10-07 02:00:00');
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);
        $mail = new TaskMailService(
            $this->tasks, $this->directory, $this->recipients,
            new FinanceMailService($registry, $this->ledger, $this->transport),
            $this->ledger, $registry, $this->scopes, $this->links, new TaskTime($clock, 'Australia/Melbourne'), 'finance@example.test',
        );
        $mail->send($this->una, $this->payload([$this->email($id, ['subject' => 'First'])]));
        $clock->advance('+1 minute');
        $mail->send($this->una, $this->payload([$this->email($id, ['subject' => 'Second', 'cc' => 'max@example.test'])], ['kind' => 'reminder']));

        $history = $mail->history($this->una, $id);

        self::assertSame(['Second', 'First'], array_column($history, 'subject'));
        self::assertSame(['reminder', 'task_email'], array_column($history, 'kind'));
        self::assertSame(['sent', 'sent'], array_column($history, 'status'));
        self::assertSame(['una@example.test'], $history[0]['to']);
        self::assertSame(['max@example.test'], $history[0]['cc']);
        self::assertSame('finance@example.test', $history[0]['from']);
        self::assertSame('', $history[0]['error']);
        self::assertNotNull($history[0]['sent_at']);
        self::assertSame(['id', 'kind', 'from', 'to', 'cc', 'subject', 'status', 'error', 'sent_at'], array_keys($history[0]), 'no body anywhere');
    }

    public function test_the_task_mail_history_is_a_404_for_a_task_the_caller_may_not_see_and_empty_otherwise(): void
    {
        $theirs = $this->task('Theirs', 14);
        $mine = $this->task('Mine', 11);

        self::assertSame([], $this->mail->history($this->una, $mine));
        $this->expectException(TaskNotFound::class);
        $this->mail->history($this->una, $theirs);
    }

    public function test_the_html_of_a_suppressed_send_is_never_kept_anywhere(): void
    {
        $id = $this->task();
        $this->mail->send($this->una, $this->payload([$this->email($id, ['body' => 'secret body text'])]));

        foreach ($this->ledger->all() as $row) {
            self::assertStringNotContainsString('secret body text', json_encode($row, JSON_THROW_ON_ERROR));
            self::assertSame(hash('sha256', '<p style="margin:0 0 1em 0">secret body text</p>'), $row->bodySha256);
        }
        foreach ($this->links->all() as $link) {
            self::assertStringNotContainsString('secret', json_encode($link, JSON_THROW_ON_ERROR));
        }
    }
}
