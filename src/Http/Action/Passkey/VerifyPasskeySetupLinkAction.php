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
use TriCab\Core\Identity\Application\Service\VerifyGuestPasskeySetupTokenService;

final class VerifyPasskeySetupLinkAction
{
    private const ROUTE = '/api/v1/auth/guest/passkey/setup-link/verify';
    public const COOKIE = 'tricab_passkey_reg';

    public function __construct(
        private readonly VerifyGuestPasskeySetupTokenService $service,
        private readonly JsonResponder $responder,
        private readonly PasskeyAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
        private readonly bool $secureCookies = false,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            $body = JsonRequestBody::requireObject($request);
            $token = $body['token'] ?? null;
            if (!is_string($token) || trim($token) === '') {
                throw HttpRequestValidationFailed::invalid('This passkey setup link is invalid or has expired.');
            }
            $result = $this->service->execute(trim($token), GuestAuthRequestMetadata::clientIp($request));
            $this->logger->log('PASSKEY_SETUP_LINK_VERIFIED', ['route' => self::ROUTE]);
            $cookie = sprintf(
                '%s=%s; Path=/; HttpOnly; SameSite=None; Max-Age=%d%s',
                self::COOKIE,
                $result['registrationSessionToken'],
                $result['expiresIn'],
                $this->secureCookies ? '; Secure' : '',
            );

            return $this->responder->success($result, 200, ['Set-Cookie' => $cookie]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, self::ROUTE, 'PASSKEY_SETUP_LINK_VERIFIED');
        }
    }
}
