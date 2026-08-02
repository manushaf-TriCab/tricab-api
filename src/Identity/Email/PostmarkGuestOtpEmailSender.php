<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Email;

use DateTimeImmutable;
use JsonException;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Contract\GuestOtpEmailSender;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\OtpCode;

/**
 * Postmark transactional email adapter for guest OTP delivery.
 */
final class PostmarkGuestOtpEmailSender implements GuestOtpEmailSender
{
    private const ENDPOINT = 'https://api.postmarkapp.com/email';

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $serverToken,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly string $messageStream = 'outbound',
    ) {
        if (trim($serverToken) === '' || trim($fromEmail) === '') {
            throw GuestOtpEmailProviderException::configuration('Postmark token and from email are required.');
        }
    }

    public function sendGuestLoginCode(
        EmailAddress $emailAddress,
        OtpCode $otpCode,
        DateTimeImmutable $expiresAtUtc,
    ): void {
        $from = trim($this->fromName) === ''
            ? $this->fromEmail
            : sprintf('%s <%s>', $this->fromName, $this->fromEmail);

        $payload = [
            'From' => $from,
            'To' => $emailAddress->toString(),
            'Subject' => GuestOtpEmailTemplate::subject(),
            'HtmlBody' => GuestOtpEmailTemplate::html($otpCode, $expiresAtUtc),
            'TextBody' => GuestOtpEmailTemplate::text($otpCode, $expiresAtUtc),
            'MessageStream' => $this->messageStream,
        ];

        try {
            $response = $this->http->postJson(
                self::ENDPOINT,
                [
                    'X-Postmark-Server-Token' => $this->serverToken,
                ],
                $payload,
            );
        } catch (JsonException) {
            throw GuestOtpEmailDeliveryFailed::fromThrowable(
                GuestOtpEmailProviderException::transportFailure(),
            );
        } catch (Throwable) {
            throw GuestOtpEmailDeliveryFailed::fromThrowable(
                GuestOtpEmailProviderException::transportFailure(),
            );
        }

        $status = $response['status'];
        if ($status < 200 || $status > 299) {
            throw GuestOtpEmailDeliveryFailed::fromThrowable(
                GuestOtpEmailProviderException::unexpectedStatus($status),
            );
        }
    }
}
