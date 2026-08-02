<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

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
use TriCab\Core\Identity\Application\Service\RequestAndSendGuestOtpService;
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
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = RequestGuestOtpRequest::fromArray($body);
            $clientIp = GuestAuthRequestMetadata::clientIp($request);
            $userAgent = GuestAuthRequestMetadata::userAgent($request);

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
            ]);

            return $this->responder->success([
                'challengeId' => $sent->challengePublicId,
                'email' => $sent->maskedEmailAddress,
                'expiresAtUtc' => $sent->expiresAtUtc,
                'expiresInSeconds' => $expiresInSeconds,
            ], 202);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'guest_otp_request_failed');
        }
    }
}
