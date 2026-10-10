<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Ap\Domain\ApFailure;

/**
 * What every Tri-AP action shares: the JSON body and query, the envelope, and how a typed failure
 * reads at the endpoint (as `ArActionSupport` does for Tri-AR). The signed-in person comes from
 * {@see ApActorResolver}, which an action takes in its constructor:
 *
 *     $actor = $this->actors->actorOf($request);
 *     if ($actor === null) { return $this->unauthenticated($this->responder); }
 *
 * | Failure         | Status | Code                         |
 * |-----------------|--------|------------------------------|
 * | `ApInvalid`     | 422    | `FINANCE_AP_INPUT_INVALID`   |
 * | `ApNotFound`    | 404    | `FINANCE_AP_NOT_FOUND`       |
 * | `ApConflict`    | 409    | `FINANCE_AP_CONFLICT`        |
 * | `ApForbidden`   | 403    | `FINANCE_AP_FORBIDDEN`       |
 * | `ApUpstream`    | 502    | `FINANCE_AP_UPSTREAM_FAILED` |
 * | `ApUnavailable` | 503    | `FINANCE_AP_UNAVAILABLE`     |
 *
 * The body is the platform's envelope, `{success:false, error:{code, message, ...extra}}`; the ported
 * pages read it through `components/finance/ap/api.js`. Later tasks add their own failures (gone,
 * rate limited, already scanned) beside these, deriving from `ApFailure`.
 */
trait ApActionSupport
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

    private function failure(JsonResponder $responder, ApFailure $failure): ResponseInterface
    {
        return $responder->payload([
            'success' => false,
            'error' => ['code' => $failure->errorCode(), 'message' => $failure->getMessage()] + $failure->extra,
        ], $failure->httpStatus());
    }
}
