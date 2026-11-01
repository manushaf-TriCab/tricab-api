<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;

/**
 * What every Tri-AP action shares: the JSON body and query, the `{success, data}` / `{success:false, error}` envelope,
 * and how an {@see Ap2Failure} reads at the endpoint (its own status, code and extra fields).
 */
trait Ap2ActionSupport
{
    private function unauthenticated(JsonResponder $responder): ResponseInterface
    {
        return $responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
    }

    /**
     * The decoded JSON object, or an empty array when the body is not one (the source's `request.get_json(silent=True) or {}`).
     *
     * @return array<string, mixed>
     */
    private function bodyOf(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return [];
        }
        $out = [];
        foreach ($body as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * The query string as a typed map.
     *
     * @return array<string, mixed>
     */
    private function queryOf(ServerRequestInterface $request): array
    {
        $out = [];
        foreach ($request->getQueryParams() as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * `{success:true, data}`.
     *
     * @param array<array-key, mixed> $data
     */
    private function ok(JsonResponder $responder, array $data, int $status = 200): ResponseInterface
    {
        return $responder->payload(['success' => true, 'data' => $data], $status);
    }

    /** `?name=1` or `?name=true`. */
    private function flag(ServerRequestInterface $request, string $name): bool
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return $value === '1' || $value === 'true';
    }

    private function failure(JsonResponder $responder, Ap2Failure $failure): ResponseInterface
    {
        return $responder->payload([
            'success' => false,
            'error' => ['code' => $failure->errorCode(), 'message' => $failure->getMessage()] + $failure->extra,
        ], $failure->httpStatus());
    }
}
