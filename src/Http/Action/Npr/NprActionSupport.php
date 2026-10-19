<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Npr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;

/**
 * Shared by the NPR actions: the signed-in user, and how the domain's typed
 * failures read at the endpoint.
 *
 * | Failure                | Status | Code                   |
 * |------------------------|--------|------------------------|
 * | `InvalidNprInput`      | 422    | `NPR_INPUT_INVALID`    |
 * | `NprRequestConflict`   | 409    | `NPR_VERSION_CONFLICT`, with `currentVersion` |
 * | `NprRequestNotEditable`| 409    | `NPR_NOT_EDITABLE`     |
 *
 * Anything untyped is rethrown for the platform's own 500 handling.
 */
trait NprActionSupport
{
    private function user(ServerRequestInterface $request): ?AuthenticatedUser
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    private function unauthenticated(JsonResponder $responder): ResponseInterface
    {
        return $responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
    }

    private function notFound(JsonResponder $responder): ResponseInterface
    {
        return $responder->error('NPR_NOT_FOUND', 'Request not found.', 404);
    }

    private function failure(JsonResponder $responder, Throwable $failure): ResponseInterface
    {
        return match (true) {
            $failure instanceof InvalidNprInput => $responder->error('NPR_INPUT_INVALID', $failure->getMessage(), 422),
            $failure instanceof NprRequestConflict => $responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'NPR_VERSION_CONFLICT',
                    'message' => $failure->getMessage(),
                    'currentVersion' => $failure->currentVersion,
                ],
            ], 409),
            $failure instanceof NprRequestNotEditable => $responder->error('NPR_NOT_EDITABLE', $failure->getMessage(), 409),
            default => throw $failure,
        };
    }

    /** @return array<mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
