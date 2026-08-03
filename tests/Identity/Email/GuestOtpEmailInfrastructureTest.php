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
use TriCab\Api\Identity\Email\DevelopmentGuestOtpEmailSender;
use TriCab\Api\Identity\Email\GuestOtpEmailProviderException;
use TriCab\Api\Identity\Email\GuestOtpEmailTemplate;
use TriCab\Api\Identity\Email\PostmarkGuestOtpEmailSender;
use TriCab\Api\Tests\Doubles\FakeJsonHttpClient;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

final class GuestOtpEmailInfrastructureTest extends TestCase
{
    public function test_postmark_success_sends_expected_payload_without_otp_in_subject(): void
    {
        $http = new FakeJsonHttpClient();
        $sender = new PostmarkGuestOtpEmailSender(
            http: $http,
            serverToken: 'test-token',
            fromEmail: 'noreply@tricab.com',
            fromName: 'TriCab',
            messageStream: 'outbound',
        );

        $expires = new DateTimeImmutable('2026-07-31 00:05:00', new DateTimeZone('UTC'));
        $otp = OtpCode::fromString('654321');
        $sender->sendGuestLoginCode(EmailAddress::fromString('guest@example.com'), $otp, $expires);

        self::assertCount(1, $http->calls);
        self::assertSame('https://api.postmarkapp.com/email', $http->calls[0]['url']);
        self::assertSame('test-token', $http->calls[0]['headers']['X-Postmark-Server-Token']);
        $payload = $http->calls[0]['payload'];
        self::assertNotNull($payload);
        self::assertSame('guest@example.com', $payload['To']);
        self::assertSame(GuestOtpEmailTemplate::SUBJECT, $payload['Subject']);
        self::assertStringNotContainsString('654321', GuestOtpEmailTemplate::SUBJECT);
        $htmlBody = $payload['HtmlBody'];
        $textBody = $payload['TextBody'];
        self::assertIsString($htmlBody);
        self::assertIsString($textBody);
        self::assertStringContainsString('654321', $htmlBody);
        self::assertStringContainsString('654321', $textBody);
        self::assertStringContainsString('expires in', $textBody);
        self::assertStringContainsString('24 hours', $htmlBody);
        self::assertStringContainsString('Hello,', $htmlBody);
        self::assertStringContainsString('Security tip', $htmlBody);
    }

    public function test_postmark_non_2xx_does_not_expose_otp(): void
    {
        $http = new FakeJsonHttpClient();
        $http->status = 422;
        $http->body = '{"ErrorCode":400,"Message":"bad"}';

        $sender = new PostmarkGuestOtpEmailSender(
            http: $http,
            serverToken: 'test-token',
            fromEmail: 'noreply@tricab.com',
            fromName: 'TriCab',
        );

        try {
            $sender->sendGuestLoginCode(
                EmailAddress::fromString('guest@example.com'),
                OtpCode::fromString('111222'),
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M')),
            );
            self::fail('Expected GuestOtpEmailDeliveryFailed');
        } catch (GuestOtpEmailDeliveryFailed $exception) {
            self::assertSame('Guest OTP email delivery failed.', $exception->getMessage());
            self::assertStringNotContainsString('111222', $exception->getMessage());
            self::assertStringNotContainsString('111222', (string) $exception->getPrevious()?->getMessage());
        }
    }

    public function test_postmark_network_failure_is_wrapped_safely(): void
    {
        $http = new FakeJsonHttpClient();
        $http->transportError = new RuntimeException('connection timed out with secret 999888');

        $sender = new PostmarkGuestOtpEmailSender(
            http: $http,
            serverToken: 'test-token',
            fromEmail: 'noreply@tricab.com',
            fromName: 'TriCab',
        );

        try {
            $sender->sendGuestLoginCode(
                EmailAddress::fromString('guest@example.com'),
                OtpCode::fromString('999888'),
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M')),
            );
            self::fail('Expected GuestOtpEmailDeliveryFailed');
        } catch (GuestOtpEmailDeliveryFailed $exception) {
            self::assertSame('Guest OTP email delivery failed.', $exception->getMessage());
            self::assertStringNotContainsString('999888', $exception->getMessage());
        }
    }

    public function test_development_provider_stores_message_in_memory_without_reveal_by_default(): void
    {
        $sender = new DevelopmentGuestOtpEmailSender(revealEnabled: false, isProduction: false);
        $otp = OtpCode::fromString('424242');
        $expires = new DateTimeImmutable('2026-07-31T00:05:00+00:00');

        $sender->sendGuestLoginCode(EmailAddress::fromString('guest@example.com'), $otp, $expires);

        $last = $sender->lastMessage();
        self::assertNotNull($last);
        self::assertSame('guest@example.com', $last['to']);
        self::assertSame('424242', $last['otpCode']);
        self::assertSame(GuestOtpEmailTemplate::SUBJECT, $last['subject']);
    }

    public function test_development_provider_rejected_in_production(): void
    {
        $this->expectException(GuestOtpEmailProviderException::class);

        new DevelopmentGuestOtpEmailSender(revealEnabled: false, isProduction: true);
    }

    public function test_settings_reject_development_provider_and_reveal_in_production(): void
    {
        try {
            Settings::fromEnvironment([
                'APP_ENV' => 'production',
                'GUEST_EMAIL_PROVIDER' => 'development',
            ]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('development is not allowed', $exception->getMessage());
        }

        try {
            Settings::fromEnvironment([
                'APP_ENV' => 'production',
                'GUEST_EMAIL_PROVIDER' => 'postmark',
                'GUEST_OTP_DEV_REVEAL' => 'true',
                'POSTMARK_SERVER_TOKEN' => 'token',
                'POSTMARK_FROM_EMAIL' => 'noreply@tricab.com',
            ]);
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('GUEST_OTP_DEV_REVEAL', $exception->getMessage());
        }
    }

    public function test_missing_postmark_configuration_fails_at_container_resolution(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'GUEST_EMAIL_PROVIDER' => 'postmark',
            'POSTMARK_SERVER_TOKEN' => '',
            'POSTMARK_FROM_EMAIL' => '',
            'USER_REPOSITORY' => 'memory',
        ]);

        $container = ContainerFactory::create($settings);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('POSTMARK_SERVER_TOKEN and POSTMARK_FROM_EMAIL are required');

        $container->get(GuestOtpEmailSender::class);
    }

    public function test_templates_include_expiration_guidance_and_subject_omits_otp(): void
    {
        $otp = OtpCode::fromString('737373');
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT5M'));

        self::assertSame('Your TriCab guest login code', GuestOtpEmailTemplate::subject());
        self::assertStringNotContainsString('737373', GuestOtpEmailTemplate::subject());
        self::assertStringContainsString('expires in', GuestOtpEmailTemplate::text($otp, $expires));
        self::assertStringContainsString('expires in', GuestOtpEmailTemplate::html($otp, $expires));
        self::assertStringContainsString('only once', GuestOtpEmailTemplate::text($otp, $expires));
        self::assertStringContainsString('24 hours', GuestOtpEmailTemplate::html($otp, $expires));
        self::assertStringContainsString('ignore this email', GuestOtpEmailTemplate::text($otp, $expires));
        self::assertStringContainsString('Hello,', GuestOtpEmailTemplate::html($otp, $expires));
        self::assertStringContainsString('Security tip', GuestOtpEmailTemplate::html($otp, $expires));
        self::assertStringContainsString('alt="TriCab"', GuestOtpEmailTemplate::html($otp, $expires));
        self::assertStringContainsString('5 minutes', GuestOtpEmailTemplate::html($otp, $expires));
    }

    public function test_container_binds_development_sender_by_default_in_development(): void
    {
        $settings = Settings::fromEnvironment([
            'APP_ENV' => 'development',
            'USER_REPOSITORY' => 'memory',
        ]);
        $container = ContainerFactory::create($settings);

        self::assertInstanceOf(
            DevelopmentGuestOtpEmailSender::class,
            $container->get(GuestOtpEmailSender::class),
        );
    }
}
