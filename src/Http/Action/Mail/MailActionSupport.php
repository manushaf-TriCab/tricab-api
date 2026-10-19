<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Mail;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Mail\Application\DTO\MailBlocker;
use TriCab\Core\Mail\Domain\Exception\MailActionBlocked;
use TriCab\Core\Mail\Domain\Exception\MailActionForbidden;
use TriCab\Core\Mail\Domain\Exception\MailInputInvalid;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailTooLarge;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;

/**
 * Who is asking, and how the inbox's typed failures read at the endpoint. Every message is one the
 * domain wrote to be shown to a person; anything untyped is rethrown for the platform's own 500.
 *
 * | Failure              | Status | Code                    |
 * |----------------------|--------|-------------------------|
 * | `MailNotFound`       | 404    | `MAIL_NOT_FOUND`        |
 * | `MailInputInvalid`   | 422    | `MAIL_INPUT_INVALID`    |
 * | `MailActionForbidden`| 403    | `MAIL_ACTION_FORBIDDEN` |
 * | `MailActionBlocked`  | 409    | `MAIL_ACTION_REFUSED`, with `error.blockers` |
 * | `MailTooLarge`       | 413    | `MAIL_ATTACHMENT_TOO_LARGE` |
 * | `MailUnavailable`    | 503    | `MAIL_BODY_UNAVAILABLE` (Graph) or `MAIL_DRAFTS_UNAVAILABLE` (RFQ drafts) |
 */
trait MailActionSupport
{
    private function actor(ServerRequestInterface $request): ?MailActor
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);

        return $user instanceof AuthenticatedUser ? MailActor::fromUser($user) : null;
    }

    private function unauthenticated(JsonResponder $responder): ResponseInterface
    {
        return $responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
    }

    private function failure(JsonResponder $responder, Throwable $failure): ResponseInterface
    {
        return match (true) {
            $failure instanceof MailNotFound => $responder->error('MAIL_NOT_FOUND', $failure->getMessage(), 404),
            $failure instanceof MailInputInvalid => $responder->error('MAIL_INPUT_INVALID', $failure->getMessage(), 422),
            $failure instanceof MailActionForbidden => $responder->error('MAIL_ACTION_FORBIDDEN', $failure->getMessage(), 403),
            $failure instanceof MailActionBlocked => $responder->payload([
                'success' => false,
                'error' => [
                    'code' => 'MAIL_ACTION_REFUSED',
                    'message' => $failure->getMessage(),
                    'blockers' => array_map(static fn (MailBlocker $b): array => $b->toArray(), $failure->blockers),
                ],
            ], 409),
            $failure instanceof MailTooLarge => $responder->error('MAIL_ATTACHMENT_TOO_LARGE', $failure->getMessage(), 413),
            $failure instanceof MailUnavailable => $responder->error(
                $failure->what === MailUnavailable::DRAFTS ? 'MAIL_DRAFTS_UNAVAILABLE' : 'MAIL_BODY_UNAVAILABLE',
                $failure->getMessage(),
                503,
            ),
            default => throw $failure,
        };
    }
}
