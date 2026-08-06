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
 * Microsoft Graph mail adapter for guest OTP delivery (client credentials).
 * Never logs OTP, access tokens, client secrets, or Authorization headers.
 */
final class MicrosoftGraphGuestOtpEmailSender implements GuestOtpEmailSender
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const SEND_MAIL_URL_TEMPLATE = 'https://graph.microsoft.com/v1.0/users/%s/sendMail';

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly JsonHttpClient $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $mailbox,
        private readonly string $fromDisplayName = 'TriCab',
        private readonly ?string $logoUrl = null,
    ) {
        if (
            trim($tenantId) === ''
            || trim($clientId) === ''
            || trim($clientSecret) === ''
            || trim($mailbox) === ''
        ) {
            throw GuestOtpEmailProviderException::configuration(
                'GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, and RFQ_MAILBOX are required.',
            );
        }
    }

    public function sendGuestLoginCode(
        EmailAddress $emailAddress,
        OtpCode $otpCode,
        DateTimeImmutable $expiresAtUtc,
    ): void {
        $accessToken = $this->acquireAccessToken();

        $payload = [
            'message' => [
                'subject' => GuestOtpEmailTemplate::subject(),
                'body' => [
                    'contentType' => 'HTML',
                    'content' => GuestOtpEmailTemplate::html(
                        $otpCode,
                        $expiresAtUtc,
                        $this->logoUrl,
                    ),
                ],
                'toRecipients' => [
                    [
                        'emailAddress' => [
                            'address' => $emailAddress->toString(),
                        ],
                    ],
                ],
                'from' => [
                    'emailAddress' => [
                        'address' => $this->mailbox,
                        'name' => $this->fromDisplayName,
                    ],
                ],
            ],
            'saveToSentItems' => false,
        ];

        try {
            $response = $this->http->postJson(
                sprintf(self::SEND_MAIL_URL_TEMPLATE, rawurlencode($this->mailbox)),
                [
                    'Authorization' => 'Bearer ' . $accessToken,
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

    private function acquireAccessToken(): string
    {
        $now = time();
        if (
            $this->cachedAccessToken !== null
            && $this->cachedAccessToken !== ''
            && $now < ($this->cachedAccessTokenExpiresAt - 60)
        ) {
            return $this->cachedAccessToken;
        }

        try {
            $response = $this->http->postForm(
                sprintf(self::TOKEN_URL_TEMPLATE, rawurlencode($this->tenantId)),
                [],
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => self::GRAPH_SCOPE,
                    'grant_type' => 'client_credentials',
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

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw GuestOtpEmailDeliveryFailed::fromThrowable(
                GuestOtpEmailProviderException::transportFailure(),
            );
        }

        if (!is_array($decoded)) {
            throw GuestOtpEmailDeliveryFailed::fromThrowable(
                GuestOtpEmailProviderException::transportFailure(),
            );
        }

        $token = $decoded['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw GuestOtpEmailDeliveryFailed::fromThrowable(
                GuestOtpEmailProviderException::transportFailure(),
            );
        }

        $expiresIn = $decoded['expires_in'] ?? 3600;
        $ttl = is_int($expiresIn) || (is_string($expiresIn) && is_numeric($expiresIn))
            ? (int) $expiresIn
            : 3600;

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $now + max(60, $ttl);

        return $token;
    }
}
