<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\RfqSummary;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * What the workflow panel's write endpoints (parity plan E6) share: the
 * authenticated user, the body's `expectedRevision`, the typed failures, and
 * the fresh `rfq.summary.v1` as the answer. Not found is 404 `RFQ_NOT_FOUND`.
 *
 * `RfqTransitionNotPermitted` reads as 403 `RFQ_ACTION_FORBIDDEN` (this actor
 * may never set that), 409 `RFQ_TRANSITION_NOT_PERMITTED` (not from where the
 * RFQ is now, such as archived), or 422 `RFQ_INPUT_INVALID` (a reason left out).
 */
trait RfqWorkflowWrites
{
    use RfqWriteFailures;

    /**
     * @param Closure(RfqActor, AuthenticatedUser, int, array<string, mixed>): ?RfqSummary $write
     */
    private function write(ServerRequestInterface $request, ResponseInterface $response, ResolveRfqActorService $actors, JsonResponder $responder, Closure $write): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? array_filter($body, is_string(...), ARRAY_FILTER_USE_KEY) : [];
        $expected = $body['expectedRevision'] ?? null;
        if (!is_int($expected) || $expected < 1) {
            return $responder->error('RFQ_INPUT_INVALID', 'expectedRevision must be the revision you are looking at.', 422);
        }

        try {
            $summary = $write($actors->forUser($user), $user, $expected, $body);
        } catch (RfqTransitionNotPermitted $failure) {
            return match ($failure->reason) {
                RfqTransitionNotPermitted::REASON_ACTOR => $responder->error('RFQ_ACTION_FORBIDDEN', $failure->getMessage(), 403),
                RfqTransitionNotPermitted::REASON_STATE => $responder->error('RFQ_TRANSITION_NOT_PERMITTED', $failure->getMessage(), 409),
                default => $responder->error('RFQ_INPUT_INVALID', $failure->getMessage(), 422),
            };
        } catch (InvalidRfqValue|RfqActionForbidden|RfqRevisionConflict|RfqDraftNotEditable|RfqStoreUnavailable $failure) {
            return $this->failure($responder, $failure);
        }
        if ($summary === null) {
            return $responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $responder->success($summary->toArray());
    }

    /**
     * A body field that must be text when present.
     *
     * @param array<string, mixed> $body
     */
    private static function textField(array $body, string $name): string
    {
        $value = $body[$name] ?? '';
        if (!is_string($value)) {
            throw new InvalidRfqValue(sprintf('%s must be text.', $name));
        }

        return $value;
    }
}
