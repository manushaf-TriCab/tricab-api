<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use TriCab\Api\Config\Settings;

/**
 * Restrictive CORS for the React SPA origin(s).
 * Does not permit wildcard origins when credentials are involved.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, Accept, If-None-Match, X-Request-Id';
    private const EXPOSED_HEADERS = 'ETag, Cache-Control';

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = trim($request->getHeaderLine('Origin'));
        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = new Response(204);
            return $this->withCorsHeaders($response, $allowedOrigin);
        }

        $response = $handler->handle($request);

        return $this->withCorsHeaders($response, $allowedOrigin);
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        if ($origin === '') {
            return null;
        }

        foreach ($this->settings->corsAllowedOrigins as $allowed) {
            if (hash_equals($allowed, $origin)) {
                return $origin;
            }
        }

        return null;
    }

    private function withCorsHeaders(ResponseInterface $response, ?string $allowedOrigin): ResponseInterface
    {
        if ($allowedOrigin === null) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', self::ALLOWED_METHODS)
            ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
            ->withHeader('Access-Control-Expose-Headers', self::EXPOSED_HEADERS)
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Vary', 'Origin');
    }
}
