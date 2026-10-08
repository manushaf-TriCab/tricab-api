<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Mail\GraphFinanceMailTransport;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;

/**
 * The Graph transport against a fake HTTP client: no network, and no real
 * credentials (the secret below is a dummy).
 */
final class GraphFinanceMailTransportTest extends TestCase
{
    private const SECRET = 'dummy-secret-do-not-leak';

    private FakeJsonHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeJsonHttpClient();
        $this->http->queue = [];
    }

    private function transport(?string $testRecipient = null): GraphFinanceMailTransport
    {
        return new GraphFinanceMailTransport($this->http, 'tenant-1', 'client-1', self::SECRET, $testRecipient, pauseBetweenRetries: false);
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
    private function accepted(): array
    {
        return ['status' => 202, 'body' => ''];
    }

    /**
     * The Graph message of the n-th recorded call.
     *
     * @return array<mixed>
     */
    private function sentMessage(int $call = 1): array
    {
        $payload = $this->http->calls[$call]['payload'];
        self::assertIsArray($payload);
        self::assertIsArray($payload['message']);

        return $payload['message'];
    }

    private function message(): FinanceMailMessage
    {
        return new FinanceMailMessage(
            application: 'AP',
            mailbox: 'ap@tricab.com',
            to: ['jane@tricab.com'],
            subject: 'Invoice',
            body: '<p>Hi</p>',
            cc: ['cc@tricab.com'],
            bcc: ['bcc@tricab.com'],
            attachments: [new FinanceMailAttachment('a.pdf', "PDF\x00BYTES", 'application/pdf')],
        );
    }

    public function test_it_asks_for_a_client_credentials_token_then_posts_the_message_as_the_mailbox(): void
    {
        $this->http->queue = [$this->token(), $this->accepted()];

        $this->transport()->deliver($this->message());

        [$tokenCall, $sendCall] = $this->http->calls;
        self::assertSame('form', $tokenCall['kind']);
        self::assertStringContainsString('/tenant-1/oauth2/v2.0/token', $tokenCall['url']);
        self::assertIsArray($tokenCall['fields']);
        self::assertSame('client_credentials', $tokenCall['fields']['grant_type'] ?? null);
        self::assertSame('https://graph.microsoft.com/.default', $tokenCall['fields']['scope'] ?? null);

        self::assertSame('json', $sendCall['kind']);
        self::assertSame('https://graph.microsoft.com/v1.0/users/ap%40tricab.com/sendMail', $sendCall['url']);
        self::assertSame(['Authorization' => 'Bearer tok-1'], $sendCall['headers']);
        self::assertIsArray($sendCall['payload']);
        self::assertTrue($sendCall['payload']['saveToSentItems']);

        $message = $this->sentMessage();
        self::assertSame('Invoice', $message['subject']);
        self::assertSame(['contentType' => 'HTML', 'content' => '<p>Hi</p>'], $message['body']);
        self::assertSame([['emailAddress' => ['address' => 'jane@tricab.com']]], $message['toRecipients']);
        self::assertSame([['emailAddress' => ['address' => 'cc@tricab.com']]], $message['ccRecipients']);
        self::assertSame([['emailAddress' => ['address' => 'bcc@tricab.com']]], $message['bccRecipients']);
        self::assertSame([[
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => 'a.pdf',
            'contentType' => 'application/pdf',
            'contentBytes' => base64_encode("PDF\x00BYTES"),
        ]], $message['attachments']);
    }

    public function test_text_content_is_sent_as_graph_text(): void
    {
        $this->http->queue = [$this->token(), $this->accepted()];

        $this->transport()->deliver(new FinanceMailMessage('AP', 'ap@tricab.com', ['a@tricab.com'], 'S', 'plain', contentType: 'TEXT'));

        self::assertSame(['contentType' => 'Text', 'content' => 'plain'], $this->sentMessage()['body']);
    }

    public function test_the_token_is_reused_for_the_next_send(): void
    {
        $this->http->queue = [$this->token(), $this->accepted(), $this->accepted()];

        $transport = $this->transport();
        $transport->deliver($this->message());
        $transport->deliver($this->message());

        self::assertSame(['form', 'json', 'json'], array_column($this->http->calls, 'kind'));
    }

    public function test_a_test_recipient_redirects_everything_and_drops_cc_and_bcc(): void
    {
        $this->http->queue = [$this->token(), $this->accepted()];

        $this->transport('tester@tricab.com')->deliver($this->message());

        $message = $this->sentMessage();
        self::assertSame([['emailAddress' => ['address' => 'tester@tricab.com']]], $message['toRecipients']);
        self::assertArrayNotHasKey('ccRecipients', $message);
        self::assertArrayNotHasKey('bccRecipients', $message);
    }

    public function test_throttling_is_retried_and_then_succeeds(): void
    {
        $this->http->queue = [
            $this->token(),
            ['status' => 429, 'body' => '', 'headers' => ['retry-after' => '1']],
            ['status' => 503, 'body' => ''],
            $this->accepted(),
        ];

        $this->transport()->deliver($this->message());

        self::assertCount(4, $this->http->calls);
    }

    public function test_a_client_error_is_not_retried_and_names_graphs_error_code_not_the_secret(): void
    {
        $this->http->queue = [
            $this->token(),
            ['status' => 400, 'body' => '{"error":{"code":"ErrorInvalidRecipients","message":"bad ' . self::SECRET . '"}}'],
        ];

        try {
            $this->transport()->deliver($this->message());
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertSame('Microsoft Graph refused the message with status 400 (ErrorInvalidRecipients).', $failure->getMessage());
            self::assertStringNotContainsString(self::SECRET, $failure->getMessage());
        }
        self::assertCount(2, $this->http->calls);
    }

    public function test_a_server_error_that_never_clears_fails_after_three_attempts(): void
    {
        $this->http->queue = [$this->token(), ['status' => 500, 'body' => ''], ['status' => 500, 'body' => ''], ['status' => 500, 'body' => '']];

        try {
            $this->transport()->deliver($this->message());
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertStringContainsString('status 500', $failure->getMessage());
        }
        self::assertCount(4, $this->http->calls);
    }

    public function test_a_refused_token_names_the_registration_not_the_secret(): void
    {
        $this->http->queue = [['status' => 401, 'body' => '{"error":"invalid_client","error_description":"AADSTS7000215 ' . self::SECRET . '"}']];

        try {
            $this->transport()->deliver($this->message());
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertStringContainsString('refused a token (status 401)', $failure->getMessage());
            self::assertStringNotContainsString(self::SECRET, $failure->getMessage());
        }
        self::assertCount(1, $this->http->calls);
    }

    public function test_an_unreachable_graph_is_a_transport_failure_after_retries(): void
    {
        $this->http->queue = [
            $this->token(),
            new RuntimeException('connect failed ' . self::SECRET),
            new RuntimeException('connect failed'),
            new RuntimeException('connect failed'),
        ];

        try {
            $this->transport()->deliver($this->message());
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed $failure) {
            self::assertSame('Microsoft Graph could not be reached.', $failure->getMessage());
            self::assertStringNotContainsString(self::SECRET, $failure->getMessage());
        }
    }

    public function test_after_an_authorisation_failure_the_next_send_asks_for_a_new_token(): void
    {
        $this->http->queue = [
            $this->token(),
            ['status' => 403, 'body' => '{"error":{"code":"ErrorAccessDenied"}}'],
            $this->token(),
            $this->accepted(),
        ];

        $transport = $this->transport();
        try {
            $transport->deliver($this->message());
            self::fail('Expected a failure.');
        } catch (FinanceMailTransportFailed) {
            self::addToAssertionCount(1);
        }
        $transport->deliver($this->message());

        self::assertSame(['form', 'json', 'form', 'json'], array_column($this->http->calls, 'kind'));
    }
}
