<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Exception\HttpRequestValidationFailed;

final class PasskeyRegistrationSessionReader
{
    public static function token(ServerRequestInterface $request): string
    {
        $header = trim($request->getHeaderLine('X-TriCab-Passkey-Registration-Session'));
        if ($header !== '') {
            return $header;
        }
        $cookies = $request->getCookieParams();
        $cookie = $cookies[VerifyPasskeySetupLinkAction::COOKIE] ?? null;
        if (is_string($cookie) && trim($cookie) !== '') {
            return trim($cookie);
        }
        $body = $request->getParsedBody();
        if (is_array($body) && is_string($body['registrationSessionToken'] ?? null) && trim($body['registrationSessionToken']) !== '') {
            return trim($body['registrationSessionToken']);
        }

        throw HttpRequestValidationFailed::invalid('Passkey setup session is invalid or has expired.');
    }

    public static function origin(ServerRequestInterface $request, string $fallback): string
    {
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin !== '') {
            return $origin;
        }

        return $fallback;
    }
}
