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
use TriCab\Core\Npr\Domain\Exception\NprAttachmentRejected;
use TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable;
use TriCab\Core\Npr\Domain\Exception\NprRequestConflict;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Domain\Exception\NprValidationFailed;
use TriCab\Core\Npr\Domain\Exception\NprWorkflowRefused;

/**
 * Shared by the NPR actions: the signed-in user, and how the domain's typed
 * failures read at the endpoint.
 *
 * | Failure                | Status | Code                   |
 * |------------------------|--------|------------------------|
 * | `InvalidNprInput`      | 422    | `NPR_INPUT_INVALID`    |
 * | `NprRequestConflict`   | 409    | `NPR_VERSION_CONFLICT`, with `currentVersion` |
 * | `NprRequestNotEditable`| 409    | `NPR_NOT_EDITABLE`     |
 * | `NprWorkflowRefused`   | 409    | its reason: `NPR_NO_MANAGER_ROUTE`, `NPR_SELF_ENDORSEMENT_NOT_ALLOWED`, `NPR_ACTION_NOT_ALLOWED`, `NPR_SIGNING_POLICY_NOT_CONFIGURED`, `NPR_ROUTE_OVERLAP` |
 * | `NprValidationFailed`  | 422    | `NPR_VALIDATION_FAILED`, with `errors` by field |
 * | `NprAttachmentRejected`| 413/422| its reason: `NPR_ATTACHMENT_TOO_LARGE`, `_REQUEST_TOO_LARGE`, `_TOO_MANY`, `_TYPE_NOT_ALLOWED`, `_EMPTY`, `_NO_FILE` |
 * | `NprAttachmentStorageUnavailable` | 503 | `NPR_ATTACHMENTS_UNAVAILABLE` |
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
            $failure instanceof NprWorkflowRefused => $responder->error($failure->reason, $failure->getMessage(), 409),
            $failure instanceof NprValidationFailed => $responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'NPR_VALIDATION_FAILED',
                    'message' => $failure->getMessage(),
                    'errors' => $failure->errors,
                ],
            ], 422),
            $failure instanceof NprAttachmentRejected => $responder->error($failure->reason, $failure->getMessage(), $failure->status),
            $failure instanceof NprAttachmentStorageUnavailable => $responder->error('NPR_ATTACHMENTS_UNAVAILABLE', $failure->getMessage(), 503),
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

    /**
     * The `version` last read, or null when the body has none.
     *
     * @param array<mixed> $body
     */
    private function version(array $body): ?string
    {
        $version = $body['version'] ?? null;

        return is_string($version) && trim($version) !== '' ? $version : null;
    }

    private function versionRequired(JsonResponder $responder): ResponseInterface
    {
        return $responder->error('NPR_INPUT_INVALID', 'version is required: the version last read.', 422);
    }
}
