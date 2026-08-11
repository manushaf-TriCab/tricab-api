<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;
use TriCab\Api\Http\Response\JsonResponder;

/** Liveness only — does not open Azure SQL. Use this for frequent probes. */
final class HealthAction
{
    public function __construct(
        private readonly Settings $settings,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($request, $response);

        return $this->responder->payload([
            'success' => true,
            'service' => $this->settings->appName,
            'status' => 'healthy',
        ]);
    }
}
