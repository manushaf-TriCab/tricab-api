<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Shared\Mailbox;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Shared\Mailbox\GraphMailboxReader;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Shared\Mailbox\MailboxAccessDenied;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/**
 * The Graph reader against a fake HTTP client and Graph responses written to Microsoft's
 * documented shape (fixtures/, hand-made, not recorded from any tenant): no network, no
 * real credentials (the secret is a dummy), and no real mailbox. The Graph calls here
 * are the ones `invoice-flow/server/graph_client.py` makes today.
 */
final class GraphMailboxReaderTest extends TestCase
{
    private const SECRET = 'dummy-secret-do-not-leak';

    private const MAILBOX = 'ap@example.test';

    private const ID1 = 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-IMMUTABLE-ID-0001=';

    private FakeJsonHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeJsonHttpClient();
        $this->http->queue = [];
    }

    private function reader(): GraphMailboxReader
    {
        return new GraphMailboxReader($this->http, 'tenant-1', 'client-1', self::SECRET, pauseBetweenRetries: false);
    }

    /**
     * @return array{status: int, body: string}
     */
    private function token(): array
    {
        return ['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'];
    }

    /**
     * @return array{status: int, body: string}
     */
    private static function fixture(string $name, int $status = 200): array
    {
        $body = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($body);

        return ['status' => $status, 'body' => $body];
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /**
     * @return list<array{url: string, headers: array<string, string>, kind: string}>
     */
    private function graphGets(): array
    {
        $gets = [];
        foreach ($this->http->calls as $call) {
            if ($call['kind'] === 'get') {
                $gets[] = ['url' => $call['url'], 'headers' => $call['headers'], 'kind' => $call['kind']];
            }
        }

        return $gets;
    }

    public function test_every_graph_request_asks_for_immutable_ids_and_the_token_request_does_not(): void
    {
        $this->http->queue = [
            $this->token(),
            self::fixture('graph-messages-page1.json'),
            self::fixture('graph-messages-page2.json'),
            self::fixture('graph-message-with-body.json'),
            self::fixture('graph-attachments-list.json'),
            self::fixture('graph-attachment-file.json'),
        ];
        $reader = $this->reader();

        $reader->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 500);
        $reader->getMessage(self::MAILBOX, self::ID1);
        $reader->listAttachments(self::MAILBOX, self::ID1);
        $reader->getAttachment(self::MAILBOX, self::ID1, 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-ATT-0001=');

        $gets = $this->graphGets();
        self::assertCount(5, $gets, 'two pages of messages, then one message, its attachment list and one attachment');
        foreach ($gets as $get) {
            self::assertSame('IdType="ImmutableId"', $get['headers']['Prefer'] ?? null, $get['url']);
            self::assertSame('Bearer tok-1', $get['headers']['Authorization'] ?? null);
            self::assertStringStartsWith('https://graph.microsoft.com/v1.0/users/ap%40example.test/messages', $get['url']);
        }
        $forms = array_values(array_filter($this->http->calls, static fn (array $call): bool => $call['kind'] === 'form'));
        self::assertCount(1, $forms, 'one token for the whole run');
        self::assertArrayNotHasKey('Prefer', $forms[0]['headers']);
    }

    public function test_the_window_order_page_size_and_fields_are_asked_for_and_no_web_link(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-messages-page2.json')];

        $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T12:30:00Z'), 500);

        $url = $this->graphGets()[0]['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('receivedDateTime ge 2026-10-01T00:00:00Z and receivedDateTime le 2026-10-08T12:30:00Z', $query['$filter']);
        self::assertSame('receivedDateTime desc', $query['$orderby']);
        self::assertSame('50', $query['$top']);
        self::assertSame('id,subject,from,receivedDateTime,hasAttachments,bodyPreview', $query['$select']);
        self::assertStringNotContainsString('webLink', $url, 'the shared mailboxes cannot be opened in Outlook');
        self::assertStringNotContainsString('body%2C', $url, 'a list does not fetch bodies');
    }

    public function test_a_window_in_another_zone_is_sent_as_utc(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-messages-page2.json')];
        $melbourne = new DateTimeZone('Australia/Melbourne');

        $this->reader()->listMessages(self::MAILBOX, new DateTimeImmutable('2026-10-07 09:00:00', $melbourne), new DateTimeImmutable('2026-10-08 09:00:00', $melbourne), 10);

        parse_str((string) parse_url($this->graphGets()[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('receivedDateTime ge 2026-10-06T22:00:00Z and receivedDateTime le 2026-10-07T22:00:00Z', $query['$filter']);
    }

    public function test_the_page_size_never_exceeds_the_cap(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-messages-page2.json')];

        $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 7);

        parse_str((string) parse_url($this->graphGets()[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame('7', $query['$top']);
    }

    public function test_pages_are_followed_and_messages_are_read_as_graph_sent_them(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-messages-page1.json'), self::fixture('graph-messages-page2.json')];

        $messages = $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 500);

        self::assertCount(3, $messages);
        self::assertSame(
            [self::ID1, 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-IMMUTABLE-ID-0002=', 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-IMMUTABLE-ID-0003='],
            array_map(static fn ($message): string => $message->id, $messages),
        );
        self::assertSame('billing@supplier.example', $messages[0]->fromAddress, 'addresses are lower-cased');
        self::assertSame('Example Supplier', $messages[0]->fromName);
        self::assertTrue($messages[0]->hasAttachments);
        self::assertFalse($messages[1]->hasAttachments);
        self::assertSame('2026-10-06T23:14:07+00:00', $messages[0]->receivedAt->format('c'));
        self::assertSame('', $messages[2]->fromName);
        $second = $this->graphGets()[1]['url'];
        self::assertStringContainsString('%24skip=50', $second, 'the second request is the nextLink, untouched');
    }

    public function test_reading_stops_at_the_cap_without_asking_for_another_page(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-messages-page1.json')];

        $messages = $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 2);

        self::assertCount(2, $messages);
        self::assertCount(1, $this->graphGets());
    }

    public function test_a_continuation_link_to_any_other_host_is_refused_and_not_followed(): void
    {
        $page = self::fixture('graph-messages-page1.json');
        $page['body'] = str_replace('https://graph.microsoft.com/v1.0/', 'https://evil.example/v1.0/', $page['body']);
        $this->http->queue = [$this->token(), $page];

        try {
            $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 500);
            self::fail('A foreign continuation link must be refused.');
        } catch (MailboxReadFailed $failure) {
            self::assertStringContainsString('continuation', $failure->getMessage());
        }
        self::assertCount(1, $this->graphGets(), 'the foreign link was never requested');
    }

    public function test_one_message_with_its_body_and_recipients(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-message-with-body.json')];

        $message = $this->reader()->getMessage(self::MAILBOX, self::ID1);

        self::assertSame(MailboxMessage::BODY_HTML, $message->bodyType);
        self::assertStringContainsString('<script>', $message->body, 'the body is returned as sent: it is untrusted and the viewer must be inert');
        self::assertSame(['ap@example.test'], array_map(static fn ($r): string => $r->address, $message->to));
        self::assertSame(['boss@supplier.example'], array_map(static fn ($r): string => $r->address, $message->cc), 'a recipient with no address is dropped');
        self::assertSame(self::ID1, $message->summary->id);
        $url = $this->graphGets()[0]['url'];
        self::assertStringContainsString('/messages/AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-IMMUTABLE-ID-0001%3D?', $url, 'the id is path-encoded');
        self::assertStringContainsString('body%2CtoRecipients%2CccRecipients', $url);
    }

    public function test_a_plain_text_body_is_reported_as_text(): void
    {
        $row = self::fixture('graph-message-with-body.json');
        $row['body'] = str_replace('"contentType": "html"', '"contentType": "text"', $row['body']);
        $this->http->queue = [$this->token(), $row];

        self::assertSame(MailboxMessage::BODY_TEXT, $this->reader()->getMessage(self::MAILBOX, self::ID1)->bodyType);
    }

    public function test_an_id_with_slashes_and_plus_signs_is_encoded_as_one_path_segment(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-message-with-body.json')];

        $this->reader()->getMessage(self::MAILBOX, 'a/b+c d');

        self::assertStringContainsString('/messages/a%2Fb%2Bc%20d?', $this->graphGets()[0]['url']);
    }

    public function test_the_attachment_list_asks_for_no_content_and_marks_inline_images(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-attachments-list.json')];

        $attachments = $this->reader()->listAttachments(self::MAILBOX, self::ID1);

        self::assertCount(2, $attachments);
        self::assertSame('INV-1042.pdf', $attachments[0]->name);
        self::assertSame(18432, $attachments[0]->size);
        self::assertFalse($attachments[0]->isInline);
        self::assertTrue($attachments[1]->isInline);
        self::assertStringNotContainsString('contentBytes', $this->graphGets()[0]['url']);
        self::assertStringContainsString('id%2Cname%2CcontentType%2Csize%2CisInline', $this->graphGets()[0]['url']);
    }

    public function test_a_file_attachment_is_decoded(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-attachment-file.json')];

        $attachment = $this->reader()->getAttachment(self::MAILBOX, self::ID1, 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-ATT-0001=');

        self::assertSame('Invoice INV-1042', $attachment->content);
        self::assertSame('application/pdf', $attachment->summary->contentType);
        self::assertStringContainsString('/attachments/AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-ATT-0001%3D', $this->graphGets()[0]['url']);
    }

    public function test_an_attached_item_has_no_content_to_read(): void
    {
        $this->http->queue = [$this->token(), self::fixture('graph-attachment-item.json')];

        $this->expectException(MailboxReadFailed::class);
        $this->expectExceptionMessage('not a file');
        $this->reader()->getAttachment(self::MAILBOX, self::ID1, 'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0A-ATT-0003=');
    }

    public function test_a_missing_message_is_not_found(): void
    {
        $this->http->queue = [$this->token(), ['status' => 404, 'body' => '{"error":{"code":"ErrorItemNotFound","message":"The specified object was not found in the store."}}']];

        $this->expectException(MailboxItemNotFound::class);
        $this->reader()->getMessage(self::MAILBOX, self::ID1);
    }

    public function test_a_refused_registration_is_access_denied_with_the_graph_code_and_no_secret(): void
    {
        $this->http->queue = [$this->token(), ['status' => 403, 'body' => '{"error":{"code":"ErrorAccessDenied","message":"Access is denied. Check credentials and try again."}}']];

        try {
            $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-02T00:00:00Z'), 10);
            self::fail('A 403 must be an access failure.');
        } catch (MailboxAccessDenied $failure) {
            self::assertStringContainsString('403', $failure->getMessage());
            self::assertStringContainsString('ErrorAccessDenied', $failure->getMessage());
            self::assertStringContainsString('Mail.Read', $failure->getMessage());
            self::assertStringNotContainsString(self::SECRET, $failure->getMessage());
            self::assertStringNotContainsString('tok-1', $failure->getMessage());
        }
        self::assertCount(1, $this->graphGets(), 'a 403 is not retried');
    }

    public function test_a_throttled_read_is_retried_and_then_succeeds(): void
    {
        $this->http->queue = [
            $this->token(),
            ['status' => 429, 'body' => '{"error":{"code":"ApplicationThrottled"}}', 'headers' => ['retry-after' => '2']],
            self::fixture('graph-messages-page2.json'),
        ];

        $messages = $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 500);

        self::assertCount(1, $messages);
        self::assertCount(2, $this->graphGets());
    }

    public function test_a_read_that_keeps_failing_is_unavailable_after_three_attempts(): void
    {
        $this->http->queue = [
            $this->token(),
            ['status' => 503, 'body' => '{"error":{"code":"ServiceUnavailable"}}'],
            ['status' => 503, 'body' => '{"error":{"code":"ServiceUnavailable"}}'],
            ['status' => 503, 'body' => '{"error":{"code":"ServiceUnavailable"}}'],
        ];

        try {
            $this->reader()->getMessage(self::MAILBOX, self::ID1);
            self::fail('Three 503s must fail.');
        } catch (MailboxReadUnavailable $failure) {
            self::assertStringContainsString('503', $failure->getMessage());
            self::assertStringContainsString('ServiceUnavailable', $failure->getMessage());
        }
        self::assertCount(3, $this->graphGets());
    }

    public function test_an_unreachable_graph_is_unavailable_and_says_nothing_of_the_cause(): void
    {
        $this->http->queue = [
            $this->token(),
            new RuntimeException('cURL error 6 with ' . self::SECRET),
            new RuntimeException('again'),
            new RuntimeException('and again'),
        ];

        try {
            $this->reader()->getMessage(self::MAILBOX, self::ID1);
            self::fail('An unreachable Graph must fail.');
        } catch (MailboxReadUnavailable $failure) {
            self::assertSame('Microsoft Graph could not be reached.', $failure->getMessage());
        }
    }

    public function test_a_token_refusal_is_unavailable_and_names_only_the_status(): void
    {
        $this->http->queue = [['status' => 401, 'body' => '{"error":"invalid_client","error_description":"AADSTS7000215: Invalid client secret ' . self::SECRET . '"}']];

        try {
            $this->reader()->getMessage(self::MAILBOX, self::ID1);
            self::fail('A refused token must fail.');
        } catch (MailboxReadUnavailable $failure) {
            self::assertStringContainsString('status 401', $failure->getMessage());
            self::assertStringNotContainsString(self::SECRET, $failure->getMessage());
        }
        self::assertSame([], $this->graphGets(), 'no Graph call is made without a token');
    }

    public function test_a_message_with_no_id_is_a_failure_not_a_blank_row(): void
    {
        $this->http->queue = [$this->token(), ['status' => 200, 'body' => '{"value":[{"subject":"No id","receivedDateTime":"2026-10-06T00:00:00Z"}]}']];

        $this->expectException(MailboxReadFailed::class);
        $this->reader()->listMessages(self::MAILBOX, self::at('2026-10-01T00:00:00Z'), self::at('2026-10-08T00:00:00Z'), 10);
    }

    public function test_an_answer_that_is_not_json_is_a_failure(): void
    {
        $this->http->queue = [$this->token(), ['status' => 200, 'body' => '<html>gateway</html>']];

        $this->expectException(MailboxReadFailed::class);
        $this->reader()->getMessage(self::MAILBOX, self::ID1);
    }
}
