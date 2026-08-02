<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\ValidatedGuestTokenAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\ValidatedGuestToken;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\GuestSessionNotFound;
use TriCab\Core\Identity\Application\Service\RevokeGuestSessionService;
use TriCab\Core\Identity\Contract\SessionTokenHasher;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class LogoutGuestAction
{
    private const ROUTE = '/api/v1/auth/guest/logout';

    public function __construct(
        private readonly RevokeGuestSessionService $revokeGuestSession,
        private readonly SessionTokenHasher $tokenHasher,
        private readonly JsonResponder $responder,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $verifiedIdentity = $request->getAttribute(VerifiedIdentityAttribute::KEY);
        $guestToken = $request->getAttribute(ValidatedGuestTokenAttribute::KEY);
        $idempotentGuestLogout = $request->getAttribute(ValidatedGuestTokenAttribute::IDEMPOTENT_LOGOUT_KEY) === true;

        if ($verifiedIdentity instanceof VerifiedIdentity) {
            if ($verifiedIdentity->provider === ExternalProvider::MICROSOFT_ENTRA) {
                return $this->responder->error(
                    'AUTH_GUEST_SESSION_REQUIRED',
                    'Guest session authentication is required for this endpoint.',
                    403,
                );
            }

            if ($verifiedIdentity->provider !== ExternalProvider::EMAIL_OTP) {
                return $this->responder->error(
                    'AUTH_GUEST_SESSION_REQUIRED',
                    'Guest session authentication is required for this endpoint.',
                    403,
                );
            }
        } elseif (!$idempotentGuestLogout) {
            return $this->responder->error(
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        if ($guestToken instanceof ValidatedGuestToken) {
            try {
                $this->revokeGuestSession->revokeBySessionPublicId(
                    UuidPublicId::fromString($guestToken->guestSessionPublicId),
                );
            } catch (GuestSessionNotFound) {
                // Idempotent logout.
            }

            $this->logger->log('guest_session_revoked', [
                'route' => self::ROUTE,
                'session_public_id' => $guestToken->guestSessionPublicId,
            ]);

            return $this->responder->payload(['success' => true]);
        }

        $header = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1) {
            try {
                $this->revokeGuestSession->revokeByTokenHash($this->tokenHasher->hash($matches[1]));
            } catch (GuestSessionNotFound) {
                // Idempotent logout for already-revoked or unknown guest sessions.
            }

            $this->logger->log('guest_session_revoked', [
                'route' => self::ROUTE,
                'reason' => 'idempotent_or_token_hash',
            ]);

            return $this->responder->payload(['success' => true]);
        }

        if ($idempotentGuestLogout) {
            $this->logger->log('guest_session_revoked', [
                'route' => self::ROUTE,
                'reason' => 'already_revoked',
            ]);

            return $this->responder->payload(['success' => true]);
        }

        return $this->responder->error(
            'UNAUTHENTICATED',
            'Authentication is required.',
            401,
        );
    }
}
