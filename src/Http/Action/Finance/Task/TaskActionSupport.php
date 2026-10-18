<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * What every Tri-Task action shares: the person, the JSON body, and how a typed failure
 * reads at the endpoint (as `RfqWriteFailures` does for RFQ).
 *
 * | Failure          | Status | Code                         |
 * |------------------|--------|------------------------------|
 * | `TaskInvalid`    | 422    | `FINANCE_TASK_INPUT_INVALID` |
 * | `TaskForbidden`  | 403    | `FINANCE_TASK_FORBIDDEN`     |
 * | `TaskNotFound`   | 404    | `FINANCE_TASK_NOT_FOUND`     |
 * | `TaskConflict`   | 409    | `FINANCE_TASK_CONFLICT`, with what the source put beside the message (`blockers`, `count`) |
 *
 * The body is the platform's envelope, `{success:false, error:{code, message, ...extra}}`; the
 * ported pages read it through `components/finance/task/api.js` (decision D18).
 */
trait TaskActionSupport
{
    private function actorOf(ServerRequestInterface $request): ?TaskActor
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return null;
        }

        return new TaskActor($user->userId, $user->displayName, $user->emailAddress, $user->permissions);
    }

    private function unauthenticated(JsonResponder $responder): ResponseInterface
    {
        return $responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
    }

    /**
     * The decoded JSON object, or an empty array when the body is not one (the source's `_body()`).
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
     * `{success:true, data}` for a body that may be a list (the source's lists are bare arrays).
     *
     * @param array<array-key, mixed> $data
     */
    private function ok(JsonResponder $responder, array $data, int $status = 200): ResponseInterface
    {
        return $responder->payload(['success' => true, 'data' => $data], $status);
    }

    /** The decoded JSON body as sent (an object or a list), or null when there is none. */
    private function rawBodyOf(ServerRequestInterface $request): mixed
    {
        return $request->getParsedBody();
    }

    /** The ids of a bulk delete or restore: `{ids: [...]}` or a bare list. */
    private function bulkIds(ServerRequestInterface $request): mixed
    {
        $body = $this->rawBodyOf($request);

        return is_array($body) && !array_is_list($body) ? ($body['ids'] ?? null) : $body;
    }

    /** `?name=1` or `?name=true`, as the source read `deleted` and `permanent`. */
    private function flag(ServerRequestInterface $request, string $name): bool
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return $value === '1' || $value === 'true';
    }

    private function failure(JsonResponder $responder, TaskFailure $failure): ResponseInterface
    {
        return $responder->payload([
            'success' => false,
            'error' => ['code' => $failure->errorCode(), 'message' => $failure->getMessage()] + $failure->extra,
        ], $failure->httpStatus());
    }
}
