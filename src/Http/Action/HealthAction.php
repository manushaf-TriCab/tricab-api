<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\ReleaseRevision;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * Liveness only — does not open Azure SQL. Use this for frequent probes.
 * A release also reports the commits it was built from, so a deploy can
 * confirm its package is the one serving.
 */
final class HealthAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly JsonResponder $responder,
        private readonly ?ReleaseRevision $revision = null,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        $payload = [
            'success' => true,
            'service' => $this->settings->appName,
            'status' => 'healthy',
        ];
        if ($this->revision !== null) {
            $payload['revision'] = [
                'api' => $this->revision->api,
                'web' => $this->revision->web,
            ];
        }

        return $this->responder->payload($payload);
    }
}
