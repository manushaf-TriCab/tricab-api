<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Payload;
use TriCab\Core\Mail\Application\DTO\CreatedDraft;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Contract\MailBodyFetcher;
use TriCab\Core\Mail\Contract\RfqDraftCreator;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Mail\Infrastructure\MailboxBodyFetcher;
use TriCab\Core\Mail\Infrastructure\UnavailableRfqDraftCreator;
use TriCab\Core\Mail\Module;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;

/** The routes through the real app: what they return, what they refuse and what a refusal leaves alone. */
final class MailRoutesTest extends MailAppTestCase
{
    /** @return array{0: string, 1: string} the thread id and the message id of one uncertain thread */
    private function uncertain(string $key = 'u1', bool $injection = false): array
    {
        $recorded = MailFixtures::classified($this->recorder, $key, 'uncertain', 0.4, injection: $injection);

        return [$recorded->threadId, $recorded->messageId];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function review(string $threadId, array $body, int $expect = 200): array
    {
        [$status, $json] = $this->json('POST', '/api/v1/mail/threads/' . $threadId . '/review', $body);
        self::assertSame($expect, $status, json_encode($json, JSON_THROW_ON_ERROR));

        return $json;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> the `data` of a review that was accepted
     */
    private function reviewed(string $threadId, array $body): array
    {
        return Payload::obj($this->review($threadId, $body)['data']);
    }

    public function test_me_reports_what_the_person_may_do_and_the_mailboxes(): void
    {
        $this->boot(self::reviewer());
        $this->recorder->recordSync('melbourne@tricab.com', 'connected', MailFixtures::at('2026-10-18 09:30:00'), 'TriCab Melbourne');

        $me = $this->data('/api/v1/mail/me');
        self::assertTrue($me['canReview']);
        self::assertTrue($me['canExport']);
        $mailbox = Payload::obj(self::at($me, 'mailboxes', 0));
        self::assertSame(['id', 'address', 'displayName', 'connection', 'lastSyncAt'], array_keys($mailbox));
        self::assertSame('melbourne@tricab.com', $mailbox['address']);

        $this->boot([Module::PERMISSION_ACCESS]);
        $me = $this->data('/api/v1/mail/me');
        self::assertFalse($me['canReview']);
        self::assertFalse($me['canExport']);
    }

    public function test_queues_and_the_thread_list_page_through_the_real_routes(): void
    {
        $this->boot(self::reviewer());
        $this->uncertain('u1');
        MailFixtures::classified($this->recorder, 'r1', 'rfq', 0.95, at: '2026-10-18 10:00:00');
        MailFixtures::classified($this->recorder, 'r2', 'rfq', 0.9, at: '2026-10-18 11:00:00');

        $queues = $this->data('/api/v1/mail/queues');
        self::assertSame(['all', 'uncertain', 'rfq', 'rfq_followup', 'not_rfq', 'skipped', 'marked'], array_keys($queues));
        self::assertSame(['total' => 3, 'unread' => 3], $queues['all']);
        self::assertSame(['total' => 2, 'unread' => 2], $queues['rfq']);

        $page = $this->data('/api/v1/mail/threads?queue=rfq&pageSize=1&sort=oldest');
        self::assertSame(2, $page['total']);
        self::assertCount(1, Payload::list($page['items']));
        $cursor = Payload::str($page['nextCursor']);
        $second = $this->data('/api/v1/mail/threads?queue=rfq&pageSize=1&sort=oldest&cursor=' . rawurlencode($cursor));
        self::assertNull($second['nextCursor']);
        self::assertNotSame(self::at($page, 'items', 0, 'id'), self::at($second, 'items', 0, 'id'));

        self::assertCount(1, Payload::list($this->data('/api/v1/mail/threads?unreadOnly=true&search=Quote&queue=uncertain')['items']));
    }

    public function test_a_bad_query_is_422(): void
    {
        $this->boot(self::reviewer());

        foreach (['queue=trash', 'sort=random', 'attachments=maybe', 'pageSize=0', 'pageSize=101', 'cursor=%25%25'] as $query) {
            [$status, $json] = $this->json('GET', '/api/v1/mail/threads?' . $query);
            self::assertSame(422, $status, $query);
            self::assertSame('MAIL_INPUT_INVALID', self::at($json, 'error', 'code'));
        }
    }

    public function test_the_thread_detail_carries_the_guards_answer(): void
    {
        $this->boot(self::reviewer());
        [$thread] = $this->uncertain();

        $detail = $this->data('/api/v1/mail/threads/' . $thread);
        self::assertSame(['mark_read', 'confirm_label', 'correct_label', 'create_draft', 'ignore'], $detail['allowedActions']);
        self::assertSame('undecided_label', self::at($detail, 'blockers', 'create_draft', 0, 'kind'));
        self::assertSame(self::at($detail, 'blockers', 'create_draft'), self::at($detail, 'blockers', 'ignore'));
        self::assertNull($detail['decision']);
        self::assertSame([], $detail['links']);
    }

    public function test_an_unknown_thread_message_or_attachment_is_404(): void
    {
        $this->boot(self::reviewer());

        foreach (['/api/v1/mail/threads/nope', '/api/v1/mail/messages/nope/body', '/api/v1/mail/attachments/nope'] as $path) {
            [$status, $json] = $this->json('GET', $path);
            self::assertSame(404, $status, $path);
            self::assertSame('MAIL_NOT_FOUND', self::at($json, 'error', 'code'));
        }
        [$status] = $this->json('POST', '/api/v1/mail/threads/nope/review', ['action' => 'mark_read']);
        self::assertSame(404, $status);
    }

    public function test_marking_the_label_then_ignoring_is_the_whole_review(): void
    {
        $this->boot(self::reviewer());
        [$thread] = $this->uncertain();

        $blocked = $this->review($thread, ['action' => 'ignore'], 409);
        self::assertFalse($blocked['success']);
        self::assertSame('MAIL_ACTION_REFUSED', self::at($blocked, 'error', 'code'));
        self::assertSame([['kind' => 'undecided_label', 'message' => 'Mark the label as right or wrong first.']], self::at($blocked, 'error', 'blockers'));
        self::assertSame('open', self::at($this->data('/api/v1/mail/threads/' . $thread), 'summary', 'reviewState'), 'a refusal changes nothing');

        $marked = $this->reviewed($thread, ['action' => 'correct_label', 'label' => 'not_rfq', 'note' => 'It is an invoice']);
        self::assertSame('correct', self::at($marked, 'decision', 'kind'));
        self::assertSame('not_rfq', self::at($marked, 'decision', 'label'));
        self::assertSame('It is an invoice', self::at($marked, 'decision', 'note'));
        self::assertTrue(self::at($marked, 'summary', 'decided'));
        self::assertSame('not_rfq', self::at($marked, 'summary', 'effectiveLabel'));
        self::assertSame('uncertain', self::at($marked, 'summary', 'label'));
        self::assertSame([], $marked['blockers']);

        $ignored = $this->reviewed($thread, ['action' => 'ignore', 'note' => 'spam']);
        self::assertSame('ignored', self::at($ignored, 'summary', 'reviewState'));
        self::assertSame('already_actioned', self::at($ignored, 'blockers', 'create_draft', 0, 'kind'));
        self::assertSame(['received', 'classified', 'label_corrected', 'ignored'], array_column(Payload::objList($ignored['activity']), 'kind'));
        self::assertSame('system', self::at($ignored, 'activity', 0, 'actor'));
        self::assertSame('Ignored: spam', self::at($ignored, 'activity', 3, 'description'));

        $again = $this->review($thread, ['action' => 'ignore'], 409);
        self::assertSame('already_actioned', self::at($again, 'error', 'blockers', 0, 'kind'));
    }

    public function test_the_injection_flag_refuses_with_409_even_after_the_label_is_marked(): void
    {
        $this->boot(self::reviewer());
        [$thread] = $this->uncertain('inj', true);
        $this->review($thread, ['action' => 'confirm_label']);

        $refused = $this->review($thread, ['action' => 'create_draft'], 409);
        self::assertSame(['injection'], array_column(Payload::objList(self::at($refused, 'error', 'blockers')), 'kind'));
    }

    public function test_create_draft_refuses_with_503_and_changes_nothing_while_the_rfq_parser_cannot_read_email(): void
    {
        $this->boot(self::reviewer());
        self::assertInstanceOf(UnavailableRfqDraftCreator::class, $this->container()->get(RfqDraftCreator::class));
        [$thread] = $this->uncertain();
        $this->review($thread, ['action' => 'confirm_label']);

        $json = $this->review($thread, ['action' => 'create_draft'], 503);
        self::assertSame('MAIL_DRAFTS_UNAVAILABLE', self::at($json, 'error', 'code'));
        self::assertSame(UnavailableRfqDraftCreator::REASON, self::at($json, 'error', 'message'));
        $detail = $this->data('/api/v1/mail/threads/' . $thread);
        self::assertSame('open', self::at($detail, 'summary', 'reviewState'));
        self::assertSame([], $detail['links']);
    }

    public function test_create_draft_links_the_rfq_draft_and_marks_the_thread_drafted(): void
    {
        $this->boot(self::reviewer());
        $creator = new class () implements RfqDraftCreator {
            public ?MailActor $actor = null;

            public function createFromThread(MailActor $actor, string $threadId, string $subject): CreatedDraft
            {
                $this->actor = $actor;

                return new CreatedDraft('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'RFQ-D-3001', $subject);
            }
        };
        $this->container()->set(RfqDraftCreator::class, $creator);
        [$thread] = $this->uncertain();
        $this->review($thread, ['action' => 'confirm_label']);

        $detail = $this->reviewed($thread, ['action' => 'create_draft']);
        self::assertSame('drafted', self::at($detail, 'summary', 'reviewState'));
        self::assertSame(['kind', 'publicId', 'reference', 'title', 'href', 'createdAt'], array_keys(Payload::obj(self::at($detail, 'links', 0))));
        self::assertSame('/admin/rfqs/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', self::at($detail, 'links', 0, 'href'));
        self::assertSame('RFQ-D-3001', self::at($detail, 'links', 0, 'reference'));
        $last = Payload::objList($detail['activity']);
        $last = end($last);
        self::assertIsArray($last);
        self::assertSame('draft_created', $last['kind']);
        self::assertSame('Created RFQ draft RFQ-D-3001.', $last['description']);
        self::assertNotNull($creator->actor);
        self::assertSame('thilanh@tricab.com', $creator->actor->email);
        self::assertSame('already_actioned', self::at($detail, 'blockers', 'create_draft', 0, 'kind'));
    }

    public function test_someone_without_review_may_mark_read_but_nothing_else(): void
    {
        $this->boot([Module::PERMISSION_ACCESS]);
        [$thread] = $this->uncertain();

        $read = $this->reviewed($thread, ['action' => 'mark_read']);
        self::assertFalse(self::at($read, 'summary', 'unread'));
        self::assertSame(['mark_unread'], $read['allowedActions']);
        self::assertTrue(self::at($this->reviewed($thread, ['action' => 'mark_unread']), 'summary', 'unread'));

        foreach ([['action' => 'confirm_label'], ['action' => 'correct_label', 'label' => 'rfq'], ['action' => 'create_draft'], ['action' => 'ignore']] as $body) {
            $json = $this->review($thread, $body, 403);
            self::assertSame('MAIL_ACTION_FORBIDDEN', self::at($json, 'error', 'code'), $body['action']);
        }
        self::assertNull($this->data('/api/v1/mail/threads/' . $thread)['decision']);
    }

    public function test_a_reviewer_deletes_a_conversation_from_the_inbox_whatever_its_state(): void
    {
        $this->boot(self::reviewer());
        [$thread, $message] = $this->uncertain('d1');
        $this->review($thread, ['action' => 'correct_label', 'label' => 'not_rfq']);
        $this->review($thread, ['action' => 'ignore']);
        [$kept] = $this->uncertain('d2');

        [$status, $json] = $this->json('DELETE', '/api/v1/mail/threads/' . $thread);
        self::assertSame(200, $status, json_encode($json, JSON_THROW_ON_ERROR));
        self::assertSame(['id' => $thread, 'deleted' => true], $json['data']);

        self::assertSame(404, $this->call('GET', '/api/v1/mail/threads/' . $thread)->getStatusCode());
        self::assertSame(404, $this->call('GET', '/api/v1/mail/messages/' . $message . '/body')->getStatusCode());
        self::assertSame([$kept], array_column(Payload::objList($this->data('/api/v1/mail/threads')['items']), 'id'));
        self::assertSame(1, self::at($this->data('/api/v1/mail/queues'), 'all', 'total'));
        self::assertSame(404, $this->call('DELETE', '/api/v1/mail/threads/' . $thread)->getStatusCode(), 'already deleted');
        self::assertSame(404, $this->call('DELETE', '/api/v1/mail/threads/0123456789abcdef0123456789abcdef')->getStatusCode());
    }

    public function test_deleting_needs_review_and_access(): void
    {
        $this->boot([Module::PERMISSION_ACCESS]);
        [$thread] = $this->uncertain();
        self::assertSame(403, $this->call('DELETE', '/api/v1/mail/threads/' . $thread)->getStatusCode());

        $this->boot([Module::PERMISSION_REVIEW]);
        [$thread] = $this->uncertain();
        [$status, $json] = $this->json('DELETE', '/api/v1/mail/threads/' . $thread);
        self::assertSame(403, $status);
        self::assertSame('MAIL_ACTION_FORBIDDEN', self::at($json, 'error', 'code'));

        $this->boot(self::reviewer());
        [$thread] = $this->uncertain();
        self::assertSame(200, $this->call('DELETE', '/api/v1/mail/threads/' . $thread)->getStatusCode());
    }

    public function test_a_bad_review_body_is_422(): void
    {
        $this->boot(self::reviewer());
        [$thread] = $this->uncertain();

        foreach ([
            [],
            ['action' => 'delete'],
            ['action' => 'correct_label'],
            ['action' => 'correct_label', 'label' => 'maybe'],
            ['action' => 'ignore', 'note' => str_repeat('x', 1001)],
            ['action' => 'ignore', 'note' => ['no']],
        ] as $body) {
            $json = $this->review($thread, $body, 422);
            self::assertSame('MAIL_INPUT_INVALID', self::at($json, 'error', 'code'), json_encode($body, JSON_THROW_ON_ERROR));
        }
    }

    public function test_the_body_is_503_with_a_clear_message_while_the_graph_settings_are_empty(): void
    {
        $this->boot(self::reviewer());
        [, $message] = $this->uncertain();

        [$status, $json] = $this->json('GET', '/api/v1/mail/messages/' . $message . '/body');
        self::assertSame(503, $status);
        self::assertSame('MAIL_BODY_UNAVAILABLE', self::at($json, 'error', 'code'));
        self::assertStringContainsString('RFQ_MAIL_GRAPH_', Payload::str(self::at($json, 'error', 'message')));

        $recorded = MailFixtures::classified($this->recorder, 'att', 'rfq', 0.9, attachments: [new NewAttachment('a1', 'drawing.pdf', 'application/pdf', 100)]);
        $detail = $this->data('/api/v1/mail/threads/' . $recorded->threadId);
        [$status, $json] = $this->json('GET', '/api/v1/mail/attachments/' . Payload::str(self::at($detail, 'messages', 0, 'attachments', 0, 'id')));
        self::assertSame(503, $status);
        self::assertSame('MAIL_BODY_UNAVAILABLE', self::at($json, 'error', 'code'));
    }

    public function test_the_body_and_a_download_come_cleaned_and_safe_from_exchange(): void
    {
        $this->boot(self::reviewer());
        $reader = new InMemoryMailboxReader();
        $reader->add(
            'melbourne@tricab.com',
            new MailboxMessage(new MailboxMessageSummary('graph-b1', 'Quote', 'jo@customer.example', 'Jo', MailFixtures::at(), true, 'Hi'), MailboxMessage::BODY_HTML, '<p onclick="x()">Hi</p><script>bad()</script><img src="https://t.example/p.gif">'),
            [new MailboxAttachment(new MailboxAttachmentSummary('a1', "evil\"name.pdf", 'text/html', 18), '<script>1</script>')],
        );
        $this->container()->set(MailBodyFetcher::class, new MailboxBodyFetcher($reader));
        $recorded = MailFixtures::classified($this->recorder, 'b1', 'rfq', 0.9, attachments: [new NewAttachment('a1', "evil\"name.pdf", 'text/html', 18)]);
        $detail = $this->data('/api/v1/mail/threads/' . $recorded->threadId);

        $body = $this->data('/api/v1/mail/messages/' . $recorded->messageId . '/body');
        self::assertSame(['messageId', 'html', 'remoteImagesBlocked', 'quotedHtml'], array_keys($body));
        self::assertSame('<p>Hi</p><span></span>', $body['html']);
        self::assertTrue($body['remoteImagesBlocked']);
        self::assertNull($body['quotedHtml']);

        $response = $this->call('GET', '/api/v1/mail/attachments/' . Payload::str(self::at($detail, 'messages', 0, 'attachments', 0, 'id')));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<script>1</script>', (string) $response->getBody());
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="evil_name.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('sandbox', $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function test_an_attachment_over_the_parsers_limit_is_413_and_never_fetched(): void
    {
        $this->boot(self::reviewer());
        $reader = new InMemoryMailboxReader();
        $this->container()->set(MailBodyFetcher::class, new MailboxBodyFetcher($reader));
        $recorded = MailFixtures::classified($this->recorder, 'big', 'rfq', 0.9, attachments: [
            new NewAttachment('doc', 'huge.pdf', 'application/pdf', 20 * 1024 * 1024 + 1),
            new NewAttachment('img', 'huge.png', 'image/png', 8 * 1024 * 1024 + 1),
        ]);
        $files = Payload::objList(self::at($this->data('/api/v1/mail/threads/' . $recorded->threadId), 'messages', 0, 'attachments'));
        self::assertCount(2, $files);

        foreach ($files as $file) {
            [$status, $json] = $this->json('GET', '/api/v1/mail/attachments/' . Payload::str($file['id']));
            self::assertSame(413, $status, Payload::str($file['fileName']));
            self::assertSame('MAIL_ATTACHMENT_TOO_LARGE', self::at($json, 'error', 'code'));
        }
        self::assertSame(0, $reader->reads);
    }

    public function test_a_message_gone_from_the_mailbox_is_404_not_a_server_error(): void
    {
        $this->boot(self::reviewer());
        $this->container()->set(MailBodyFetcher::class, new MailboxBodyFetcher(new InMemoryMailboxReader()));
        [, $message] = $this->uncertain();

        [$status, $json] = $this->json('GET', '/api/v1/mail/messages/' . $message . '/body');
        self::assertSame(404, $status);
        self::assertSame('MAIL_NOT_FOUND', self::at($json, 'error', 'code'));
    }
}
