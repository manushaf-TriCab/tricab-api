<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\PasskeyAuthErrorMapper;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;
use TriCab\Api\Http\Request\GuestAuthRequestMetadata;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\IssuedGuestSession;
use TriCab\Core\Identity\Application\DTO\PasskeyRpConfig;
use TriCab\Core\Identity\Application\Service\CompletePasskeyAuthenticationService;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

final class PasskeyAuthenticationCompleteAction
{
    private const ROUTE = '/api/v1/auth/passkeys/authentication/complete';

    public function __construct(
        private readonly CompletePasskeyAuthenticationService $service,
        private readonly PasskeyRpConfig $rp,
        private readonly ApplicationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly PasskeyAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $body = JsonRequestBody::requireObject($request);
            $challengeId = $body['challengeId'] ?? null;
            $credential = $body['credential'] ?? null;
            if (!is_string($challengeId) || $challengeId === '' || $credential === null) {
                throw HttpRequestValidationFailed::invalid('Passkey sign-in could not be completed.');
            }
            $json = is_string($credential) ? $credential : json_encode($credential, JSON_THROW_ON_ERROR);
            $issued = $this->transaction->run(function () use ($request, $challengeId, $json): IssuedGuestSession {
                return $this->service->execute(
                    challengeId: $challengeId,
                    assertionJson: $json,
                    clientOrigin: PasskeyRegistrationSessionReader::origin($request, $this->rp->primaryOrigin()),
                    clientIp: GuestAuthRequestMetadata::clientIp($request),
                    userAgent: GuestAuthRequestMetadata::userAgent($request),
                );
            });
            $this->logger->log('PASSKEY_AUTH_SUCCEEDED', ['route' => self::ROUTE]);
            $user = $issued->user;
            unset($user['userId']);

            return $this->responder->success([
                'accessToken' => $issued->accessToken,
                'tokenType' => $issued->tokenType,
                'expiresIn' => $issued->expiresIn,
                'expiresAtUtc' => $issued->expiresAtUtc,
                'user' => $user,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'PASSKEY_AUTH_FAILED');
        }
    }
}
