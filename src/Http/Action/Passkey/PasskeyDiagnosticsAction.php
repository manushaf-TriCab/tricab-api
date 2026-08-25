<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Passkey\FakePasskeyCeremony;
use TriCab\Core\Identity\Contract\PasskeyCeremony;

/**
 * Development-only adapter proof. Never registered when APP_ENV=production.
 * Does not return challenges, tokens, JWTs, cookies, or private credential material.
 */
final class PasskeyDiagnosticsAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PasskeyCeremony $ceremony,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        if (!$this->settings->isDevelopment()) {
            return $this->responder->error('NOT_FOUND', 'Not found.', 404);
        }

        return $this->responder->success([
            'ceremony' => (new ReflectionClass($this->ceremony))->getShortName(),
            'fakeCeremony' => $this->ceremony instanceof FakePasskeyCeremony,
            'repository' => $this->settings->userRepository,
            'rpId' => $this->settings->passkeyRpId,
            'allowedOriginCount' => count($this->settings->passkeyAllowedOrigins),
            'appEnv' => $this->settings->appEnv,
            'routes' => [
                'POST /api/v1/auth/passkeys/registration/options',
                'POST /api/v1/auth/passkeys/registration/complete',
                'POST /api/v1/auth/passkeys/authentication/options',
                'POST /api/v1/auth/passkeys/authentication/complete',
            ],
        ]);
    }
}
