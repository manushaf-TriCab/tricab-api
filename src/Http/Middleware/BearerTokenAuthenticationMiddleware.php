<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthTokenValidationFailed;
use TriCab\Api\Identity\Authentication\CompositeBearerTokenValidator;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;
use TriCab\Api\Identity\Authentication\EntraTokenValidationFailed;
use TriCab\Api\Identity\Authentication\EntraTokenValidationNotConfigured;
use TriCab\Api\Identity\Authentication\ValidatedGuestTokenAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;

/**
 * Resolves Bearer tokens via CompositeBearerTokenValidator (Entra + guest).
 * Does not contain business rules; only authentication adapter orchestration.
 */
final class BearerTokenAuthenticationMiddleware implements MiddlewareInterface
{
    private const GUEST_LOGOUT_PATH = '/api/v1/auth/guest/logout';

    public function __construct(
        private readonly CompositeBearerTokenValidator $tokenValidator,
        private readonly JsonResponder $responder,
        private readonly EntraJwtValidationDebugLogger $debugLogger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(VerifiedIdentityAttribute::KEY) instanceof VerifiedIdentity) {
            return $handler->handle($request);
        }

        $header = trim($request->getHeaderLine('Authorization'));
        $authorizationPresent = $header !== '';
        $bearerDetected = $authorizationPresent && preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) === 1;

        if (!$authorizationPresent) {
            $this->debugLogger->log([
                'path' => $request->getUri()->getPath(),
                'authorization_header_present' => false,
                'bearer_token_detected' => false,
                'token_parsed' => false,
                'jwt_validation_stage_reached' => false,
                'validation_failure_reason' => 'no authorization header; request continues without VerifiedIdentity',
                'http_response_code' => 'deferred',
            ]);

            return $handler->handle($request);
        }

        if (!$bearerDetected) {
            $this->debugLogger->log([
                'path' => $request->getUri()->getPath(),
                'authorization_header_present' => true,
                'bearer_token_detected' => false,
                'token_parsed' => false,
                'jwt_validation_stage_reached' => false,
                'validation_failure_reason' => 'authorization header is not a Bearer token',
                'http_response_code' => 401,
            ]);

            return $this->responder->error(
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        try {
            $authResult = $this->tokenValidator->validate($matches[1]);
        } catch (EntraTokenValidationNotConfigured $exception) {
            $this->debugLogger->log([
                'path' => $request->getUri()->getPath(),
                'authorization_header_present' => true,
                'bearer_token_detected' => true,
                'token_parsed' => false,
                'jwt_validation_stage_reached' => false,
                'provider' => 'microsoft_entra',
                'validation_failure_reason' => $this->debugLogger->classifyFailure($exception->getMessage()),
                'http_response_code' => 501,
            ]);

            return $this->responder->error(
                'ENTRA_NOT_CONFIGURED',
                $exception->getMessage(),
                501,
            );
        } catch (AuthTokenValidationFailed $exception) {
            if ($this->allowsIdempotentGuestLogout($request, $exception)) {
                $this->debugLogger->log([
                    'path' => $request->getUri()->getPath(),
                    'authorization_header_present' => true,
                    'bearer_token_detected' => true,
                    'token_parsed' => true,
                    'jwt_validation_stage_reached' => true,
                    'provider' => 'email_otp',
                    'stage' => 'guest_token_validation',
                    'validation_failure_reason' => $exception->errorCode,
                    'http_response_code' => 'continue_idempotent_logout',
                ]);

                return $handler->handle(
                    $request->withAttribute(ValidatedGuestTokenAttribute::IDEMPOTENT_LOGOUT_KEY, true),
                );
            }

            $this->debugLogger->log([
                'path' => $request->getUri()->getPath(),
                'authorization_header_present' => true,
                'bearer_token_detected' => true,
                'token_parsed' => true,
                'jwt_validation_stage_reached' => true,
                'provider' => 'email_otp',
                'stage' => 'guest_token_validation',
                'validation_failure_reason' => $exception->errorCode,
                'http_response_code' => $exception->httpStatus,
            ]);

            return $this->responder->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        } catch (EntraTokenValidationFailed $exception) {
            $this->debugLogger->log([
                'path' => $request->getUri()->getPath(),
                'authorization_header_present' => true,
                'bearer_token_detected' => true,
                'token_parsed' => !str_contains(strtolower($exception->getMessage()), 'malformed'),
                'jwt_validation_stage_reached' => true,
                'provider' => 'microsoft_entra',
                'validation_failure_reason' => $this->debugLogger->classifyFailure($exception->getMessage()),
                'validator_message' => $this->safeValidatorMessage($exception->getMessage()),
                'http_response_code' => $exception->httpStatus,
            ]);

            return $this->responder->error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
            );
        }

        $this->debugLogger->log([
            'path' => $request->getUri()->getPath(),
            'authorization_header_present' => true,
            'bearer_token_detected' => true,
            'token_parsed' => true,
            'jwt_validation_stage_reached' => true,
            'provider' => $authResult->identity->provider,
            'validation_failure_reason' => 'none',
            'http_response_code' => 'continue',
        ]);

        $request = $request->withAttribute(VerifiedIdentityAttribute::KEY, $authResult->identity);
        if ($authResult->guestToken !== null) {
            $request = $request->withAttribute(ValidatedGuestTokenAttribute::KEY, $authResult->guestToken);
        }

        return $handler->handle($request);
    }

    private function allowsIdempotentGuestLogout(
        ServerRequestInterface $request,
        AuthTokenValidationFailed $exception,
    ): bool {
        if ($request->getMethod() !== 'POST' || $request->getUri()->getPath() !== self::GUEST_LOGOUT_PATH) {
            return false;
        }

        return in_array($exception->errorCode, [
            AuthTokenValidationFailed::AUTH_SESSION_REVOKED,
            AuthTokenValidationFailed::AUTH_SESSION_NOT_FOUND,
        ], true);
    }

    private function safeValidatorMessage(string $message): string
    {
        if (preg_match('/[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{10,}/', $message) === 1) {
            return 'Microsoft Entra token validation failed.';
        }

        return substr($message, 0, 240);
    }
}
