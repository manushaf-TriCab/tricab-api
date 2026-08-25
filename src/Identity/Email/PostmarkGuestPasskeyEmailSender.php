<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Email;

use DateTimeImmutable;
use Throwable;
use TriCab\Api\Infrastructure\Http\JsonHttpClient;
use TriCab\Core\Identity\Application\Exception\GuestOtpEmailDeliveryFailed;
use TriCab\Core\Identity\Contract\GuestPasskeyEmailSender;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

final class PostmarkGuestPasskeyEmailSender implements GuestPasskeyEmailSender
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

    public function sendPasskeySetupLink(
        EmailAddress $emailAddress,
        string $setupUrl,
        DateTimeImmutable $expiresAtUtc,
    ): void {
        unset($expiresAtUtc);
        $from = trim($this->fromName) === ''
            ? $this->fromEmail
            : sprintf('%s <%s>', $this->fromName, $this->fromEmail);

        try {
            $response = $this->http->postJson(
                self::ENDPOINT,
                [
                    'X-Postmark-Server-Token' => $this->serverToken,
                ],
                [
                    'From' => $from,
                    'To' => $emailAddress->toString(),
                    'Subject' => GuestPasskeyEmailTemplate::subject(),
                    'HtmlBody' => GuestPasskeyEmailTemplate::html($setupUrl),
                    'TextBody' => GuestPasskeyEmailTemplate::text($setupUrl),
                    'MessageStream' => $this->messageStream,
                ],
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
