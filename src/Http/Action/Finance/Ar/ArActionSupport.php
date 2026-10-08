<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ar;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArFailure;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * What every Tri-AR action shares: the person, the JSON body, and how a typed failure reads at the
 * endpoint (as `TaskActionSupport` does for Tri-Task).
 *
 * | Failure      | Status | Code                      |
 * |--------------|--------|---------------------------|
 * | `ArInvalid`  | 422    | `FINANCE_AR_INPUT_INVALID` |
 * | `ArNotFound` | 404    | `FINANCE_AR_NOT_FOUND`     |
 * | `ArConflict` | 409    | `FINANCE_AR_CONFLICT`      |
 * | `ArForbidden`   | 403 | `FINANCE_AR_FORBIDDEN`     |
 * | `ArUpstream`    | 502 | `FINANCE_AR_UPSTREAM_FAILED` |
 * | `ArUnavailable` | 503 | `FINANCE_AR_UNAVAILABLE`   |
 * | `ArGone`        | 410 | `FINANCE_AR_GONE`          |
 * | `ArRateLimited` | 429 | `FINANCE_AR_RATE_LIMITED`  |
 * | `ArAlreadyScanned` | 409 | `FINANCE_AR_ALREADY_SCANNED` |
 *
 * The body is the platform's envelope, `{success:false, error:{code, message, ...extra}}`; the ported
 * pages read it through `components/finance/ar/api.js` (decision D18's twin for Tri-AR).
 */
trait ArActionSupport
{
    private function actorOf(ServerRequestInterface $request): ?ArActor
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return null;
        }

        return new ArActor($user->userId, $user->displayName, $user->emailAddress, $user->permissions);
    }

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

    private function failure(JsonResponder $responder, ArFailure $failure): ResponseInterface
    {
        return $responder->payload([
            'success' => false,
            'error' => ['code' => $failure->errorCode(), 'message' => $failure->getMessage()] + $failure->extra,
        ], $failure->httpStatus());
    }
}
