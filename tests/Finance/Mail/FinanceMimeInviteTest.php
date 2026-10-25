<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Mail\GraphFinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailAttachment;
use TriCab\Core\Finance\Shared\Mail\FinanceMailCalendar;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMimeBuilder;

/**
 * A calendar invite as a MIME message (multipart/alternative with a text/calendar; method=REQUEST part), and its send
 * through Graph as base64 text to the sender's own sendMail URL. Other finance mail keeps using JSON. No network.
 */
final class FinanceMimeInviteTest extends TestCase
{
    private const ICS = "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\nSUMMARY:Due\\, Clôture\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    private function message(bool $attach = true): FinanceMailMessage
    {
        return new FinanceMailMessage(
            application: 'TASK',
            mailbox: 'una@example.test',
            to: ['ivy@example.test', 'rae@example.test'],
            subject: 'Calendar invite: Clôture, mois — très long ' . str_repeat('é', 40) . ' (due 30 Oct 2026)',
            body: '<p style="margin:0">Hi, clôture</p>',
            attachments: $attach ? [new FinanceMailAttachment('invite.ics', self::ICS, 'application/ics')] : [],
            actorUserId: 11,
            ownMailbox: true,
            calendar: new FinanceMailCalendar(self::ICS, "Hi, clôture\n\nDue: Friday"),
        );
    }

    /** @return string the raw body Graph was sent in call $n */
    private static function raw(RecordingMethodHttpClient $http, int $n): string
    {
        $payload = $http->calls[$n]['payload'];
        self::assertIsArray($payload);
        self::assertIsString($payload['raw']);

        return $payload['raw'];
    }

    /** @param list<string> $cc */
    private function mime(FinanceMailMessage $m, array $cc = []): string
    {
        return FinanceMimeBuilder::build($m, $m->to, $cc, new DateTimeImmutable('2026-10-25 01:02:03 UTC'), 'abc123', 'seed');
    }

    /** @return array{0: array<string, string>, 1: string} headers (unfolded, lower-case names) and the body */
    private static function split(string $mime): array
    {
        [$head, $body] = explode("\r\n\r\n", $mime, 2);
        $headers = [];
        foreach (explode("\r\n", (string) preg_replace('/\r\n[ \t]/', '', $head)) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[strtolower($name)] = $value;
        }

        return [$headers, $body];
    }

    public function test_the_message_is_alternative_text_html_and_calendar_inside_mixed_with_the_ics_attached(): void
    {
        $mime = $this->mime($this->message());

        self::assertSame(0, preg_match('/(?<!\r)\n/', $mime), 'CRLF only');
        [$headers, $body] = self::split($mime);
        self::assertSame('una@example.test', $headers['from']);
        self::assertSame('ivy@example.test, rae@example.test', $headers['to']);
        self::assertSame('Sun, 25 Oct 2026 01:02:03 +0000', $headers['date']);
        self::assertSame('<abc123@tricab.ai>', $headers['message-id']);
        self::assertSame('1.0', $headers['mime-version']);
        self::assertSame('multipart/mixed; boundary="mix_seed"', $headers['content-type']);
        self::assertArrayNotHasKey('cc', $headers);

        self::assertStringStartsWith("--mix_seed\r\nContent-Type: multipart/alternative; boundary=\"alt_seed\"\r\n\r\n", $body);
        self::assertStringEndsWith("--mix_seed--\r\n", $body);
        // The three alternative parts, in the order plain, html, calendar (the last is the richest).
        preg_match_all('/--alt_seed\r\nContent-Type: ([^\r]+)\r\nContent-Transfer-Encoding: base64\r\n\r\n((?:[A-Za-z0-9+\/=]+\r\n)+)/', $body, $parts, PREG_SET_ORDER);
        self::assertSame(['text/plain; charset=UTF-8', 'text/html; charset=UTF-8', 'text/calendar; charset=UTF-8; method=REQUEST'], array_column($parts, 1));
        self::assertSame("Hi, clôture\n\nDue: Friday", base64_decode($parts[0][2]));
        self::assertSame('<p style="margin:0">Hi, clôture</p>', base64_decode($parts[1][2]));
        self::assertSame(self::ICS, base64_decode($parts[2][2]));
        self::assertStringContainsString("--alt_seed--\r\n", $body);
        foreach ($parts as $part) {
            foreach (explode("\r\n", rtrim($part[2])) as $line) {
                self::assertLessThanOrEqual(76, strlen($line));
            }
        }
        self::assertStringContainsString("Content-Type: application/ics; name=\"invite.ics\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"invite.ics\"\r\n\r\n", $body);
    }

    public function test_without_attachments_the_top_level_is_the_alternative_and_cc_is_listed(): void
    {
        [$headers, $body] = self::split($this->mime($this->message(false), ['boss@example.test']));

        self::assertSame('multipart/alternative; boundary="alt_seed"', $headers['content-type']);
        self::assertSame('boss@example.test', $headers['cc']);
        self::assertStringStartsWith("--alt_seed\r\nContent-Type: text/plain", $body);
        self::assertStringNotContainsString('mix_seed', $body);
    }

    public function test_a_non_ascii_subject_is_rfc_2047_words_that_decode_back_and_fit_a_line(): void
    {
        $message = $this->message();
        [$head] = explode("\r\n\r\n", $this->mime($message), 2);
        self::assertSame(1, preg_match('/Subject: (.*?)\r\nDate:/s', $head, $m));
        $subject = $m[1] ?? '';

        self::assertStringContainsString("\r\n =?UTF-8?B?", $subject, 'folded across encoded words');
        foreach (explode("\r\n", $subject) as $line) {
            self::assertLessThanOrEqual(76, strlen($line));
        }
        preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $subject, $words);
        $decoded = implode('', array_map('base64_decode', $words[1]));
        self::assertSame($message->subject, $decoded);
        self::assertTrue(mb_check_encoding($decoded, 'UTF-8'));
        self::assertSame('Plain subject Bcc: evil@x.test', FinanceMimeBuilder::subject("Plain subject\r\nBcc: evil@x.test"), 'a line break cannot add a header');
    }

    public function test_graph_gets_the_base64_mime_as_text_plain_at_the_senders_own_send_mail_url(): void
    {
        $http = new RecordingMethodHttpClient();
        $http->queue = [['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'], ['status' => 202, 'body' => '']];
        $transport = new GraphFinanceMailTransport($http, 't', 'c', 'dummy-secret', pauseBetweenRetries: false);

        $transport->deliverMime($this->message());

        self::assertSame(['FORM', 'RAW'], array_column($http->calls, 'method'));
        $call = $http->calls[1];
        self::assertSame('https://graph.microsoft.com/v1.0/users/una%40example.test/sendMail', $call['url']);
        self::assertSame(['Authorization' => 'Bearer tok-1', 'Content-Type' => 'text/plain'], $call['headers']);
        $raw = self::raw($http, 1);
        self::assertSame(1, preg_match('/^[A-Za-z0-9+\/=]+$/', $raw), 'one base64 string, no JSON');
        $mime = base64_decode($raw, true);
        self::assertIsString($mime);
        self::assertStringContainsString("From: una@example.test\r\nTo: ivy@example.test, rae@example.test\r\n", $mime);
        self::assertStringContainsString('Content-Type: text/calendar; charset=UTF-8; method=REQUEST', $mime);
    }

    public function test_a_test_recipient_redirects_the_mime_and_drops_cc(): void
    {
        $http = new RecordingMethodHttpClient();
        $http->queue = [['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'], ['status' => 202, 'body' => '']];
        (new GraphFinanceMailTransport($http, 't', 'c', 'dummy-secret', 'qa@example.test', pauseBetweenRetries: false))->deliverMime($this->message());

        $mime = base64_decode(self::raw($http, 1), true);
        self::assertIsString($mime);
        self::assertStringContainsString("To: qa@example.test\r\n", $mime);
        self::assertStringNotContainsString('ivy@example.test', $mime);
    }

    public function test_a_refusal_names_the_status_and_a_server_error_is_retried(): void
    {
        $http = new RecordingMethodHttpClient();
        $http->queue = [['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'], ['status' => 503, 'body' => ''], ['status' => 202, 'body' => '']];
        (new GraphFinanceMailTransport($http, 't', 'c', 'dummy-secret', pauseBetweenRetries: false))->deliverMime($this->message());
        self::assertSame(['FORM', 'RAW', 'RAW'], array_column($http->calls, 'method'));

        $http = new RecordingMethodHttpClient();
        $http->queue = [['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'], ['status' => 403, 'body' => '{"error":{"code":"ErrorAccessDenied"}}']];
        try {
            (new GraphFinanceMailTransport($http, 't', 'c', 'dummy-secret', pauseBetweenRetries: false))->deliverMime($this->message());
            self::fail('Expected a refusal.');
        } catch (FinanceMailTransportFailed $e) {
            self::assertSame('Microsoft Graph refused the message with status 403 (ErrorAccessDenied).', $e->getMessage());
        }
        self::assertCount(2, $http->calls, 'no retry of a 403');
    }

    public function test_other_finance_mail_still_goes_as_json(): void
    {
        $http = new RecordingMethodHttpClient();
        $http->queue = [['status' => 200, 'body' => '{"access_token":"tok-1","expires_in":3600}'], ['status' => 202, 'body' => '']];
        $plain = new FinanceMailMessage('TASK', 'una@example.test', ['ivy@example.test'], 'Reminder', '<p>Hi</p>');

        (new GraphFinanceMailTransport($http, 't', 'c', 'dummy-secret', pauseBetweenRetries: false))->deliver($plain);

        self::assertSame(['FORM', 'POST'], array_column($http->calls, 'method'));
        $payload = $http->calls[1]['payload'];
        self::assertIsArray($payload);
        self::assertIsArray($payload['message']);
        self::assertSame('Reminder', $payload['message']['subject']);
        $this->expectException(\InvalidArgumentException::class);
        FinanceMimeBuilder::build($plain, ['ivy@example.test'], [], new DateTimeImmutable(), 'x', 'y');
    }

    public function test_a_failing_transport_error_is_not_swallowed(): void
    {
        $http = new RecordingMethodHttpClient();
        $http->queue = [['status' => 200, 'body' => '{"access_token":"t","expires_in":3600}'], new RuntimeException('x'), new RuntimeException('x'), new RuntimeException('x')];
        $this->expectException(FinanceMailTransportFailed::class);
        (new GraphFinanceMailTransport($http, 't', 'c', 'dummy-secret', pauseBetweenRetries: false))->deliverMime($this->message());
    }
}
