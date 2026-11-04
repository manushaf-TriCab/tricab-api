<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail\Intake;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Intake\GraphMailDeltaSource;
use TriCab\Api\Shared\Mailbox\GraphMailboxReader;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxSyncExpired;

/** The delta query against a fake Graph transport: URLs, headers, paging, removal, 410. No network, no tenant. */
final class GraphMailDeltaSourceTest extends TestCase
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private FakeJsonHttpClient $http;

    private GraphMailDeltaSource $source;

    protected function setUp(): void
    {
        $this->http = new FakeJsonHttpClient();
        $this->http->queue = [['status' => 200, 'body' => '{"access_token":"tok","expires_in":3600}']];
        $this->source = new GraphMailDeltaSource(new GraphMailboxReader($this->http, 't', 'c', 'dummy-secret', pauseBetweenRetries: false), 25);
    }

    /**
     * @param list<array<string, mixed>> $value
     * @param array<string, mixed> $extra
     * @return array{status: int, body: string}
     */
    private static function page(array $value, array $extra = [], int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode(['value' => $value] + $extra, JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function row(string $id, array $over = []): array
    {
        return $over + [
            '@odata.type' => '#microsoft.graph.message',
            'id' => $id,
            'internetMessageId' => '<' . $id . '@customer.example>',
            'conversationId' => 'conv-' . $id,
            'subject' => 'Quote for cable',
            'from' => ['emailAddress' => ['name' => 'Jo', 'address' => 'Jo@Customer.example']],
            'toRecipients' => [['emailAddress' => ['name' => 'TriCab', 'address' => 'melbourne@tricab.com']]],
            'ccRecipients' => [],
            'receivedDateTime' => '2026-10-18T09:00:00Z',
            'bodyPreview' => 'Hi',
            'isRead' => false,
            'hasAttachments' => true,
            'internetMessageHeaders' => [['name' => 'Precedence', 'value' => 'bulk'], ['name' => 'Auto-Submitted', 'value' => 'no']],
        ];
    }

    public function test_the_first_call_is_the_inbox_delta_from_the_window_with_only_the_fields_needed(): void
    {
        $this->http->queue[] = self::page([], ['@odata.deltaLink' => self::GRAPH . '/delta?token=1']);

        $this->source->changes('melbourne@tricab.com', null, new DateTimeImmutable('2026-10-16 10:00:00 UTC'));

        $call = $this->http->calls[1];
        self::assertStringStartsWith(self::GRAPH . '/users/melbourne%40tricab.com/mailFolders/inbox/messages/delta?', $call['url']);
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
        self::assertSame('receivedDateTime ge 2026-10-16T10:00:00Z', $query['$filter']);
        self::assertSame(
            'id,internetMessageId,conversationId,subject,from,toRecipients,ccRecipients,receivedDateTime,bodyPreview,isRead,hasAttachments,internetMessageHeaders',
            $query['$select'],
        );
        self::assertStringNotContainsString('body,', $query['$select'], 'bodies are not asked for');
        self::assertSame('IdType="ImmutableId", odata.maxpagesize=25', $call['headers']['Prefer']);
        self::assertSame('Bearer tok', $call['headers']['Authorization']);
    }

    public function test_a_row_becomes_a_message_with_headers_and_a_final_page_hands_back_the_delta_link(): void
    {
        $this->http->queue[] = self::page([self::row('m1')], ['@odata.deltaLink' => self::GRAPH . '/delta?deltatoken=abc']);

        $changes = $this->source->changes('melbourne@tricab.com', null, new DateTimeImmutable('2026-10-16 UTC'));

        self::assertTrue($changes->complete);
        self::assertSame(self::GRAPH . '/delta?deltatoken=abc', $changes->link);
        $mail = $changes->messages[0];
        self::assertSame('m1', $mail->graphMessageId);
        self::assertSame('<m1@customer.example>', $mail->internetMessageId);
        self::assertSame('jo@customer.example', $mail->from->address);
        self::assertTrue($mail->hasAttachments);
        self::assertSame(['precedence' => 'bulk', 'auto-submitted' => 'no'], $mail->headers);
    }

    public function test_a_row_with_no_headers_key_has_unknown_headers_not_empty_ones(): void
    {
        $row = self::row('m1');
        unset($row['internetMessageHeaders']);
        $this->http->queue[] = self::page([$row], ['@odata.deltaLink' => self::GRAPH . '/d']);

        self::assertNull($this->source->changes('m@x.example', null, new DateTimeImmutable('2026-10-16 UTC'))->messages[0]->headers);
    }

    public function test_a_next_link_is_not_complete_and_is_what_to_resume_from(): void
    {
        $this->http->queue[] = self::page([self::row('m1')], ['@odata.nextLink' => self::GRAPH . '/page2?skiptoken=x']);
        $changes = $this->source->changes('m@x.example', null, new DateTimeImmutable('2026-10-16 UTC'));
        self::assertFalse($changes->complete);
        self::assertSame(self::GRAPH . '/page2?skiptoken=x', $changes->link);

        $this->http->queue[] = self::page([self::row('m2')], ['@odata.deltaLink' => self::GRAPH . '/d?t=2']);
        $next = $this->source->changes('m@x.example', $changes->link, new DateTimeImmutable('2026-10-16 UTC'));
        self::assertSame(self::GRAPH . '/page2?skiptoken=x', $this->http->calls[2]['url'], 'the saved link is followed as given');
        self::assertTrue($next->complete);
    }

    public function test_removed_rows_are_reported_as_removed_and_not_as_messages(): void
    {
        $this->http->queue[] = self::page([['id' => 'gone', '@removed' => ['reason' => 'deleted']], self::row('m1')], ['@odata.deltaLink' => self::GRAPH . '/d']);

        $changes = $this->source->changes('m@x.example', null, new DateTimeImmutable('2026-10-16 UTC'));

        self::assertSame(['gone'], $changes->removedIds);
        self::assertCount(1, $changes->messages);
    }

    public function test_a_410_is_a_sync_expired_failure(): void
    {
        $this->http->queue[] = ['status' => 410, 'body' => '{"error":{"code":"syncStateNotFound"}}'];
        $this->expectException(MailboxSyncExpired::class);
        $this->source->changes('m@x.example', self::GRAPH . '/d?old=1', new DateTimeImmutable('2026-10-16 UTC'));
    }

    public function test_a_link_that_is_not_graphs_is_never_followed(): void
    {
        $this->expectException(MailboxReadFailed::class);
        try {
            $this->source->changes('m@x.example', 'https://evil.example/steal', new DateTimeImmutable('2026-10-16 UTC'));
        } finally {
            self::assertCount(0, $this->http->calls, 'no request, not even a token, went anywhere');
        }
    }

    public function test_a_response_naming_a_foreign_next_link_is_refused(): void
    {
        $this->http->queue[] = self::page([], ['@odata.nextLink' => 'https://evil.example/next']);
        $this->expectException(MailboxReadFailed::class);
        $this->source->changes('m@x.example', null, new DateTimeImmutable('2026-10-16 UTC'));
    }

    public function test_a_page_with_neither_link_is_refused_rather_than_losing_the_cursor(): void
    {
        $this->http->queue[] = self::page([self::row('m1')]);
        $this->expectException(MailboxReadFailed::class);
        $this->source->changes('m@x.example', null, new DateTimeImmutable('2026-10-16 UTC'));
    }

    public function test_headers_are_read_with_their_own_call(): void
    {
        $this->http->queue[] = ['status' => 200, 'body' => json_encode(['id' => 'm1', 'internetMessageHeaders' => [['name' => 'List-Unsubscribe', 'value' => '<mailto:u@x.example>'], ['name' => 'Received', 'value' => 'a'], ['name' => 'Received', 'value' => 'b']]], JSON_THROW_ON_ERROR)];

        $headers = $this->source->headers('m@x.example', 'm1');

        self::assertSame('<mailto:u@x.example>', $headers['list-unsubscribe']);
        self::assertSame('a', $headers['received'], 'the first of a repeated header wins');
        self::assertStringContainsString('select=id%2CinternetMessageHeaders', $this->http->calls[1]['url']);
        self::assertSame('IdType="ImmutableId"', $this->http->calls[1]['headers']['Prefer']);
    }

    public function test_the_inbox_subtree_is_walked_breadth_first_through_child_folders_only(): void
    {
        $this->http->queue[] = self::page([
            ['id' => 'AAA=', 'displayName' => 'NZ PROJECTS - GUY', 'childFolderCount' => 0],
            ['id' => 'BBB=', 'displayName' => 'Archive 2025', 'childFolderCount' => 1],
        ]);
        $this->http->queue[] = self::page([['id' => 'CCC=', 'displayName' => 'Old', 'childFolderCount' => 0]]);

        $folders = $this->source->folders('nzprojects@tricab.com');

        self::assertSame(['inbox', 'AAA=', 'BBB=', 'CCC='], array_map(static fn ($f): string => $f->id, $folders));
        self::assertSame('Inbox/Archive 2025/Old', $folders[3]->path);
        self::assertStringStartsWith(self::GRAPH . '/users/nzprojects%40tricab.com/mailFolders/inbox/childFolders?', $this->http->calls[1]['url']);
        self::assertStringStartsWith(self::GRAPH . '/users/nzprojects%40tricab.com/mailFolders/BBB%3D/childFolders?', $this->http->calls[2]['url']);
        self::assertCount(3, $this->http->calls, 'token + two listings; nothing outside the Inbox is listed');
        foreach (array_slice($this->http->calls, 1) as $call) {
            self::assertSame('get', $call['kind'], 'Graph GET only (the first call is the token request)');
        }
    }

    public function test_a_folder_id_goes_into_the_delta_url_encoded(): void
    {
        $this->http->queue[] = self::page([], ['@odata.deltaLink' => self::GRAPH . '/delta?token=1']);

        $this->source->changes('nzprojects@tricab.com', null, new DateTimeImmutable('2026-10-16 10:00:00 UTC'), 'AAMk/x+y=');

        self::assertStringStartsWith(self::GRAPH . '/users/nzprojects%40tricab.com/mailFolders/AAMk%2Fx%2By%3D/messages/delta?', $this->http->calls[1]['url']);
    }

    public function test_the_backfill_lists_with_a_filter_not_delta_and_adds_deleted_items_only_when_asked(): void
    {
        $this->http->queue[] = self::page([]);
        self::assertSame(['inbox'], array_map(static fn ($f): string => $f->id, $this->source->backfillFolders('m@tricab.com', false)));
        $this->http->queue[] = self::page([]);
        self::assertSame(['inbox', 'deleteditems'], array_map(static fn ($f): string => $f->id, $this->source->backfillFolders('m@tricab.com', true)));

        $this->http->queue[] = self::page([self::row('m1')], ['@odata.nextLink' => self::GRAPH . '/next?skip=1']);
        $page = $this->source->listPage('m@tricab.com', new \TriCab\Core\Mail\Intake\MailFolder('deleteditems', 'Deleted Items', 'Deleted Items'), null, new DateTimeImmutable('2026-10-16 10:00:00 UTC'));

        $last = $this->http->calls[count($this->http->calls) - 1] ?? self::fail('no call was made');
        $url = $last['url'];
        self::assertStringContainsString('/mailFolders/deleteditems/messages?', $url);
        self::assertStringNotContainsString('/delta', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('receivedDateTime ge 2026-10-16T10:00:00Z', $query['$filter']);
        self::assertSame('receivedDateTime desc', $query['$orderby']);
        self::assertFalse($page->complete);
        self::assertSame(self::GRAPH . '/next?skip=1', $page->link);
        self::assertCount(1, $page->messages);
    }
}
