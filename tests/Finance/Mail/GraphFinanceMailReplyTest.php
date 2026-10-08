<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\GraphFinanceMailTransport;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSuppressed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The threaded reply on the Graph transport (T5.9) against a recording HTTP client: createReply, a PATCH with the new
 * text above Graph's quote, send; the fallback to a standalone message for a message Graph refuses; a draft that is
 * never left behind. No network, and the secret is a dummy.
 */
final class GraphFinanceMailReplyTest extends TestCase
{
    private const SECRET = 'dummy-secret-do-not-leak';

    private RecordingMethodHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new RecordingMethodHttpClient();
    }

    private function transport(?string $testRecipient = null): GraphFinanceMailTransport
    {
        return new GraphFinanceMailTransport($this->http, 'tenant-1', 'client-1', self::SECRET, $testRecipient, pauseBetweenRetries: false);
    }

    private function message(): FinanceMailMessage
    {
        return new FinanceMailMessage(
            application: 'AR',
            mailbox: 'ar@example.test',
            to: ['pat@customer.example.test'],
            subject: 'Re: Payment advice',
            body: '<p>Thanks, received.</p>',
            cc: ['boss@example.test'],
            attachments: [new FinanceMailAttachment('receipt.pdf', "PDF\x00BYTES", 'application/pdf')],
            replyToMessageId: 'AAMk-original',
        );
    }

    /** @return array{status: int, body: string} */
    private static function token(): array
    {
        return ['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'];
    }

    /** @return array{status: int, body: string} */
    private static function draft(string $id = 'draft-1', string $quoted = '<blockquote>Original text</blockquote>'): array
    {
        return ['status' => 201, 'body' => json_encode(['id' => $id, 'body' => ['contentType' => 'html', 'content' => $quoted]], JSON_THROW_ON_ERROR)];
    }

    /** @return array{status: int, body: string} */
    private static function ok(int $status = 200): array
    {
        return ['status' => $status, 'body' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(int $call): array
    {
        $payload = $this->http->calls[$call]['payload'];
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * The calls after the token request.
     *
     * @return list<string>
     */
    private function steps(): array
    {
        return array_slice($this->http->trail(), 1);
    }

    public function test_a_reply_is_create_reply_then_a_patch_with_the_new_text_above_the_quote_then_send(): void
    {
        $this->http->queue = [self::token(), self::draft(), self::ok(), self::ok(202)];

        $threaded = $this->transport()->deliverReply($this->message(), 'AAMk-original');

        self::assertTrue($threaded);
        self::assertSame(['POST messages/AAMk-original/createReply', 'PATCH messages/draft-1', 'POST messages/draft-1/send'], $this->steps());
        $patch = $this->payloadOf(2);
        self::assertSame('Re: Payment advice', $patch['subject']);
        self::assertSame(['contentType' => 'HTML', 'content' => '<p>Thanks, received.</p><blockquote>Original text</blockquote>'], $patch['body']);
        self::assertSame([['emailAddress' => ['address' => 'boss@example.test']]], $patch['ccRecipients']);
        self::assertArrayNotHasKey('toRecipients', $patch, 'Graph has already addressed the draft to the sender');
        self::assertSame(
            [['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'receipt.pdf', 'contentType' => 'application/pdf', 'contentBytes' => base64_encode("PDF\x00BYTES")]],
            $patch['attachments'],
        );
        self::assertNull($this->http->calls[1]['payload'], 'createReply and send carry no body');
        self::assertNull($this->http->calls[3]['payload']);
        foreach ([1, 2, 3] as $call) {
            self::assertSame('Bearer tok-1', $this->http->calls[$call]['headers']['Authorization']);
        }
    }

    public function test_the_message_id_is_url_encoded_and_the_mailbox_is_the_path(): void
    {
        $this->http->queue = [self::token(), self::draft(), self::ok(), self::ok(202)];

        $this->transport()->deliverReply($this->message(), 'AAMk/odd+id=');

        self::assertSame('https://graph.microsoft.com/v1.0/users/ar%40example.test/messages/AAMk%2Fodd%2Bid%3D/createReply', $this->http->calls[1]['url']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function refusedMessages(): array
    {
        return ['message not found' => [404], 'an id Graph cannot read' => [400]];
    }

    #[DataProvider('refusedMessages')]
    public function test_a_message_graph_refuses_is_sent_as_a_new_message_and_reports_false(int $status): void
    {
        $this->http->queue = [self::token(), ['status' => $status, 'body' => '{"error":{"code":"ErrorItemNotFound"}}'], ['status' => 202, 'body' => '']];

        $threaded = $this->transport()->deliverReply($this->message(), 'AAMk-gone');

        self::assertFalse($threaded);
        self::assertSame(['POST messages/AAMk-gone/createReply', 'POST sendMail'], $this->steps());
        $standalone = $this->payloadOf(2);
        self::assertIsArray($standalone['message']);
        self::assertSame('Re: Payment advice', $standalone['message']['subject']);
    }

    public function test_a_failed_patch_deletes_the_draft_and_is_a_failed_send_not_a_second_message(): void
    {
        $this->http->queue = [self::token(), self::draft(), ['status' => 413, 'body' => '{"error":{"code":"ErrorMessageSizeExceeded"}}'], self::ok(204)];

        try {
            $this->transport()->deliverReply($this->message(), 'AAMk-original');
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertSame('Microsoft Graph refused the message with status 413 (ErrorMessageSizeExceeded).', $failure->getMessage());
        }

        self::assertSame(['POST messages/AAMk-original/createReply', 'PATCH messages/draft-1', 'DELETE messages/draft-1'], $this->steps());
    }

    public function test_a_failed_send_deletes_the_draft_too_and_never_falls_back(): void
    {
        $this->http->queue = [self::token(), self::draft(), self::ok(), ['status' => 403, 'body' => '{"error":{"code":"ErrorAccessDenied"}}'], self::token(), self::ok(204)];

        try {
            $this->transport()->deliverReply($this->message(), 'AAMk-original');
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertStringContainsString('403', $failure->getMessage());
        }

        $steps = $this->http->trail();
        self::assertSame('DELETE messages/draft-1', end($steps));
        self::assertNotContains('POST sendMail', $steps);
    }

    public function test_a_server_error_on_create_reply_is_retried_and_then_fails_without_a_standalone_send(): void
    {
        $this->http->queue = [self::token(), ['status' => 503, 'body' => ''], ['status' => 503, 'body' => ''], ['status' => 503, 'body' => '']];

        try {
            $this->transport()->deliverReply($this->message(), 'AAMk-original');
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertStringContainsString('503', $failure->getMessage());
        }

        self::assertSame(array_fill(0, 3, 'POST messages/AAMk-original/createReply'), $this->steps());
    }

    public function test_throttling_is_retried_and_then_the_reply_goes(): void
    {
        $this->http->queue = [self::token(), ['status' => 429, 'body' => ''], self::draft(), self::ok(), self::ok(202)];

        self::assertTrue($this->transport()->deliverReply($this->message(), 'AAMk-original'));
    }

    public function test_an_unreachable_graph_is_a_transport_failure_after_retries(): void
    {
        $this->http->queue = [self::token(), new RuntimeException('down'), new RuntimeException('down'), new RuntimeException('down')];

        $this->expectException(FinanceMailTransportFailed::class);
        $this->expectExceptionMessage('Microsoft Graph could not be reached.');
        $this->transport()->deliverReply($this->message(), 'AAMk-original');
    }

    public function test_no_secret_reaches_an_error(): void
    {
        $this->http->queue = [self::token(), ['status' => 500, 'body' => 'client_secret=' . self::SECRET], ['status' => 500, 'body' => 'x'], ['status' => 500, 'body' => 'x']];

        try {
            $this->transport()->deliverReply($this->message(), 'AAMk-original');
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertStringNotContainsString(self::SECRET, $failure->getMessage());
        }
    }

    public function test_a_draft_without_an_id_is_a_failure_and_nothing_is_sent(): void
    {
        $this->http->queue = [self::token(), ['status' => 201, 'body' => '{}']];

        $this->expectException(FinanceMailTransportFailed::class);
        $this->transport()->deliverReply($this->message(), 'AAMk-original');
    }

    public function test_with_a_test_recipient_nothing_is_threaded_and_the_redirected_message_is_sent_instead(): void
    {
        $this->http->queue = [self::token(), ['status' => 202, 'body' => '']];

        $threaded = $this->transport('tester@example.test')->deliverReply($this->message(), 'AAMk-original');

        self::assertFalse($threaded, 'a draft would be addressed to the real sender');
        self::assertSame(['POST sendMail'], $this->steps());
        $message = $this->payloadOf(1)['message'];
        self::assertIsArray($message);
        self::assertSame([['emailAddress' => ['address' => 'tester@example.test']]], $message['toRecipients']);
        self::assertArrayNotHasKey('ccRecipients', $message);
    }

    public function test_a_client_that_cannot_patch_is_refused_clearly(): void
    {
        $plain = new FakeJsonHttpClient();
        $transport = new GraphFinanceMailTransport($plain, 't', 'c', self::SECRET, null, pauseBetweenRetries: false);

        $this->expectException(FinanceMailTransportFailed::class);
        $this->expectExceptionMessage('Threaded replies need an HTTP client that can PATCH');
        $transport->deliverReply($this->message(), 'AAMk-original');
    }

    public function test_the_disabled_transport_suppresses_a_reply_as_it_does_a_send(): void
    {
        $this->expectException(FinanceMailSuppressed::class);
        (new DisabledFinanceMailTransport())->deliverReply($this->message(), 'AAMk-original');
    }
}
