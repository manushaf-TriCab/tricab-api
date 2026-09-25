<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use DateInterval;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\GuestAuthRateLimiter;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Request\RequestGuestOtpRequest;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Application\Service\RequestAndSendGuestOtpService;
use TriCab\Core\Identity\Application\Service\RequestGuestOtpService;
use TriCab\Core\Identity\Domain\Service\EmailMasker;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class RequestGuestOtpAction
{
    private const ROUTE = '/api/v1/auth/guest/request-otp';

    public function __construct(
        private readonly RequestAndSendGuestOtpService $requestAndSendGuestOtp,
        private readonly GuestAuthRateLimiter $rateLimiter,
        private readonly JsonResponder $responder,
        private readonly GuestAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
        private readonly Clock $clock = new SystemClock(),
        private readonly UuidV4Generator $uuids = new UuidV4Generator(),
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $clientIp = GuestAuthRequestMetadata::clientIp($request);
        $emailFingerprint = null;
        $email = null;

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = RequestGuestOtpRequest::fromArray($body);
            $userAgent = GuestAuthRequestMetadata::userAgent($request);
            $email = $input->email;
            $emailFingerprint = $this->emailFingerprint($email);

            $this->rateLimiter->assertRequestOtpAllowed($input->email, $clientIp);

            $sent = $this->requestAndSendGuestOtp->execute(
                email: $input->email,
                requestedIpAddress: $clientIp,
                requestedUserAgent: $userAgent,
            );

            $expiresAt = new DateTimeImmutable($sent->expiresAtUtc);
            $expiresInSeconds = max(0, $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp());

            $this->logger->log('guest_otp_requested', [
                'route' => self::ROUTE,
                'challenge_public_id' => $sent->challengePublicId,
                'masked_email' => $sent->maskedEmailAddress,
                'email_fingerprint' => $emailFingerprint,
                'request_ip' => $clientIp,
            ]);

            return $this->responder->success([
                'challengeId' => $sent->challengePublicId,
                'email' => $sent->maskedEmailAddress,
                'expiresAtUtc' => $sent->expiresAtUtc,
                'expiresInSeconds' => $expiresInSeconds,
            ], 202);
        } catch (GuestLoginNotEligible $exception) {
            $this->logger->log('guest_otp_eligibility_rejected', [
                'route' => self::ROUTE,
                'safe_reason_code' => $exception->reasonCode,
                'email_fingerprint' => $emailFingerprint,
                'request_ip' => $clientIp,
            ]);

            // Answer exactly as for a sent code, so the response does not reveal whether an
            // address is registered. Verifying the made-up challenge fails like a wrong code.
            return $this->acceptedWithoutChallenge((string) $email);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'guest_otp_request_failed');
        }
    }

    private function acceptedWithoutChallenge(string $email): ResponseInterface
    {
        $now = $this->clock->now();
        $expiresAt = $now->add(new DateInterval(RequestGuestOtpService::DEFAULT_TTL));

        return $this->responder->success([
            'challengeId' => $this->uuids->generate()->toString(),
            'email' => EmailMasker::mask(EmailAddress::fromString($email)),
            'expiresAtUtc' => $expiresAt->format(DATE_ATOM),
            'expiresInSeconds' => max(0, $expiresAt->getTimestamp() - $now->getTimestamp()),
        ], 202);
    }

    private function emailFingerprint(string $email): ?string
    {
        try {
            $normalized = EmailAddress::fromString($email)->toString();
        } catch (Throwable) {
            return null;
        }

        return hash('sha256', $normalized);
    }
}
