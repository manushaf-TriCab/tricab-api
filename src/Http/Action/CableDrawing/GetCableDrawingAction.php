<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\CableDrawing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use TriCab\Api\CableDrawing\CableDrawingException;
use TriCab\Api\CableDrawing\DrawCableService;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/cable-drawings?code=CA-PAXN/5C16BK — the cable's cross-section as a 1080×1080 SVG.
 *
 * Public, like product images, so an <img> can point straight at it.
 */
final class GetCableDrawingAction
{
    private const CACHE_SECONDS = 86400;

    public function __construct(
        private readonly DrawCableService $drawings,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $code = $request->getQueryParams()['code'] ?? null;
        if (!is_string($code) || trim($code) === '') {
            return $this->responder->error('CABLE_CODE_REQUIRED', 'Pass the cable product code as ?code=…', 400)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $drawing = $this->drawings->draw($code);
        } catch (CableDrawingException $e) {
            return $this->responder->error($e->errorCode, $e->getMessage(), $e->httpStatus())
                ->withHeader('Cache-Control', 'no-store');
        }

        $svg = new Response(200);
        $svg->getBody()->write($drawing->svg);

        return $svg
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=' . self::CACHE_SECONDS)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // Opened directly, the SVG may not run script or load anything.
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'")
            ->withHeader('X-Cable-Spec', $drawing->specName);
    }
}
