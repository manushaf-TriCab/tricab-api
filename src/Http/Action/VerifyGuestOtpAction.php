<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthErrorMapper;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\GuestAuthRateLimiter;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Request\VerifyGuestOtpRequest;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\Service\IssueGuestSessionService;
use TriCab\Core\Identity\Application\Service\VerifyGuestOtpService;

final class VerifyGuestOtpAction
{
    private const ROUTE = '/api/v1/auth/guest/verify-otp';

    public function __construct(
        private readonly VerifyGuestOtpService $verifyGuestOtp,
        private readonly IssueGuestSessionService $issueGuestSession,
        private readonly GuestAuthRateLimiter $rateLimiter,
        private readonly JsonResponder $responder,
        private readonly GuestAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $input = VerifyGuestOtpRequest::fromArray($body);
            $clientIp = GuestAuthRequestMetadata::clientIp($request);
            $userAgent = GuestAuthRequestMetadata::userAgent($request);

            $this->rateLimiter->assertVerifyOtpAllowed($input->challengeId, $clientIp);

            $user = $this->verifyGuestOtp->execute(
                email: $input->email,
                otp: $input->otp,
                challengePublicId: $input->challengeId,
            );

            $issued = $this->issueGuestSession->execute(
                user: $user,
                createdIpAddress: $clientIp,
                createdUserAgent: $userAgent,
            );

            $this->logger->log('guest_otp_verified', [
                'route' => self::ROUTE,
                'challenge_public_id' => $input->challengeId,
                'user_public_id' => $user->publicId()->toString(),
            ]);
            $this->logger->log('guest_session_issued', [
                'route' => self::ROUTE,
                'user_public_id' => $user->publicId()->toString(),
            ]);

            /** @var array<string, mixed> $userPayload */
            $userPayload = $issued->user;
            unset($userPayload['userId']);

            return $this->responder->success([
                'accessToken' => $issued->accessToken,
                'tokenType' => $issued->tokenType,
                'expiresIn' => $issued->expiresIn,
                'expiresAtUtc' => $issued->expiresAtUtc,
                'user' => $userPayload,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'guest_otp_verification_failed');
        }
    }
}
