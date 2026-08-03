<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Identity\Email;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Identity\Email\GuestOtpEmailTemplate;
use TriCab\Api\Identity\Email\MicrosoftGraphGuestOtpEmailSender;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

final class MicrosoftGraphGuestOtpEmailSenderTest extends TestCase
{
    public function test_graph_success_acquires_token_and_sends_mail(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 200,
                'body' => json_encode([
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                    'access_token' => 'graph-access-token-test-value',
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 202,
                'body' => '',
            ],
        ];

        $sender = new MicrosoftGraphGuestOtpEmailSender(
            http: $http,
            tenantId: 'tenant-123',
            clientId: 'client-456',
            clientSecret: 'secret-789',
            mailbox: 'quotegen@tricab.com',
        );

        $otp = OtpCode::fromString('246810');
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M'));
        $sender->sendGuestLoginCode(EmailAddress::fromString('guest@example.com'), $otp, $expires);

        self::assertCount(2, $http->calls);
        self::assertSame('form', $http->calls[0]['kind']);
        self::assertSame(
            'https://login.microsoftonline.com/tenant-123/oauth2/v2.0/token',
            $http->calls[0]['url'],
        );
        $tokenFields = $http->calls[0]['fields'];
        self::assertNotNull($tokenFields);
        self::assertSame('client_credentials', $tokenFields['grant_type']);
        self::assertSame('https://graph.microsoft.com/.default', $tokenFields['scope']);
        self::assertSame('client-456', $tokenFields['client_id']);
        self::assertArrayHasKey('client_secret', $tokenFields);

        self::assertSame('json', $http->calls[1]['kind']);
        self::assertSame(
            'https://graph.microsoft.com/v1.0/users/quotegen%40tricab.com/sendMail',
            $http->calls[1]['url'],
        );
        self::assertSame('Bearer graph-access-token-test-value', $http->calls[1]['headers']['Authorization']);

        $payload = $http->calls[1]['payload'];
        self::assertNotNull($payload);
        $message = $payload['message'] ?? null;
        self::assertIsArray($message);
        self::assertSame(GuestOtpEmailTemplate::SUBJECT, $message['subject'] ?? null);
        $toRecipients = $message['toRecipients'] ?? null;
        self::assertIsArray($toRecipients);
        self::assertIsArray($toRecipients[0] ?? null);
        self::assertIsArray($toRecipients[0]['emailAddress'] ?? null);
        self::assertSame('guest@example.com', $toRecipients[0]['emailAddress']['address'] ?? null);
        $body = $message['body'] ?? null;
        self::assertIsArray($body);
        self::assertSame('HTML', $body['contentType'] ?? null);
        $html = $body['content'] ?? null;
        self::assertIsString($html);
        self::assertStringContainsString('246810', $html);
        self::assertStringContainsString('Hello,', $html);
        self::assertStringContainsString('Security tip', $html);
        self::assertStringContainsString('ignore this email', $html);
        self::assertStringContainsString('alt="TriCab"', $html);
        self::assertFalse($payload['saveToSentItems'] ?? true);

        // Sensitive values must never appear in exception-style public messages (smoke).
        self::assertStringNotContainsString('graph-access-token-test-value', GuestOtpEmailTemplate::SUBJECT);
    }

    public function test_graph_reuses_cached_access_token_on_second_send(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 200,
                'body' => json_encode([
                    'access_token' => 'cached-token',
                    'expires_in' => 3600,
                ], JSON_THROW_ON_ERROR),
            ],
            ['status' => 202, 'body' => ''],
            ['status' => 202, 'body' => ''],
        ];

        $sender = new MicrosoftGraphGuestOtpEmailSender(
            http: $http,
            tenantId: 'tenant-123',
            clientId: 'client-456',
            clientSecret: 'secret-789',
            mailbox: 'quotegen@tricab.com',
        );

        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M'));
        $sender->sendGuestLoginCode(
            EmailAddress::fromString('a@example.com'),
            OtpCode::fromString('111111'),
            $expires,
        );
        $sender->sendGuestLoginCode(
            EmailAddress::fromString('b@example.com'),
            OtpCode::fromString('222222'),
            $expires,
        );

        self::assertCount(3, $http->calls);
        self::assertSame('form', $http->calls[0]['kind']);
        self::assertSame('json', $http->calls[1]['kind']);
        self::assertSame('json', $http->calls[2]['kind']);
    }

    public function test_graph_send_mail_non_2xx_raises_delivery_failed_without_otp(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 200,
                'body' => json_encode(['access_token' => 'tok', 'expires_in' => 3600], JSON_THROW_ON_ERROR),
            ],
            [
                'status' => 403,
                'body' => '{"error":{"code":"ErrorAccessDenied","message":"denied"}}',
            ],
        ];

        $sender = new MicrosoftGraphGuestOtpEmailSender(
            http: $http,
            tenantId: 'tenant-123',
            clientId: 'client-456',
            clientSecret: 'secret-789',
            mailbox: 'quotegen@tricab.com',
        );

        try {
            $sender->sendGuestLoginCode(
                EmailAddress::fromString('guest@example.com'),
                OtpCode::fromString('135791'),
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M')),
            );
            self::fail('Expected GuestOtpEmailDeliveryFailed');
        } catch (GuestOtpEmailDeliveryFailed $exception) {
            self::assertSame('Guest OTP email delivery failed.', $exception->getMessage());
            self::assertStringNotContainsString('135791', $exception->getMessage());
            self::assertStringNotContainsString('135791', (string) $exception->getPrevious()?->getMessage());
            self::assertStringNotContainsString('secret-789', $exception->getMessage());
            self::assertStringContainsString('HTTP 403', (string) $exception->getPrevious()?->getMessage());
        }
    }

    public function test_graph_token_non_2xx_raises_delivery_failed(): void
    {
        $http = new FakeJsonHttpClient();
        $http->queue = [
            [
                'status' => 401,
                'body' => '{"error":"invalid_client"}',
            ],
        ];

        $sender = new MicrosoftGraphGuestOtpEmailSender(
            http: $http,
            tenantId: 'tenant-123',
            clientId: 'client-456',
            clientSecret: 'secret-789',
            mailbox: 'quotegen@tricab.com',
        );

        $this->expectException(GuestOtpEmailDeliveryFailed::class);
        $sender->sendGuestLoginCode(
            EmailAddress::fromString('guest@example.com'),
            OtpCode::fromString('999999'),
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M')),
        );
    }

    public function test_graph_transport_failure_is_wrapped_safely(): void
    {
        $http = new FakeJsonHttpClient();
        $http->transportError = new RuntimeException('network down with secret-789');

        $sender = new MicrosoftGraphGuestOtpEmailSender(
            http: $http,
            tenantId: 'tenant-123',
            clientId: 'client-456',
            clientSecret: 'secret-789',
            mailbox: 'quotegen@tricab.com',
        );

        try {
            $sender->sendGuestLoginCode(
                EmailAddress::fromString('guest@example.com'),
                OtpCode::fromString('888777'),
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M')),
            );
            self::fail('Expected GuestOtpEmailDeliveryFailed');
        } catch (GuestOtpEmailDeliveryFailed $exception) {
            self::assertSame('Guest OTP email delivery failed.', $exception->getMessage());
            self::assertStringNotContainsString('888777', $exception->getMessage());
        }
    }

    public function test_container_binds_microsoft_graph_sender(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'microsoft-graph',
            'GRAPH_TENANT_ID' => 'tenant-123',
            'GRAPH_CLIENT_ID' => 'client-456',
            'GRAPH_CLIENT_SECRET' => 'secret-789',
            'RFQ_MAILBOX' => 'quotegen@tricab.com',
        ]);
        $container = ContainerFactory::create($settings);

        self::assertInstanceOf(
            MicrosoftGraphGuestOtpEmailSender::class,
            $container->get(GuestOtpEmailSender::class),
        );
    }

    public function test_missing_graph_configuration_fails_at_container_resolution(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
            'GUEST_EMAIL_PROVIDER' => 'microsoft-graph',
            'GRAPH_TENANT_ID' => '',
            'GRAPH_CLIENT_ID' => '',
            'GRAPH_CLIENT_SECRET' => '',
            'RFQ_MAILBOX' => '',
        ]);
        $container = ContainerFactory::create($settings);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, and RFQ_MAILBOX are required');

        $container->get(GuestOtpEmailSender::class);
    }
}
