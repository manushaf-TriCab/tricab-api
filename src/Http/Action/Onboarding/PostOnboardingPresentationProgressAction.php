<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\RecordPresentationProgressService;

/**
 * POST /api/v1/onboarding/training/{assignmentPublicId}/progress
 *
 * `{ versionPublicId, slideIds }`. Nothing else is read, so a body claiming
 * `complete: true` or `status: "completed"` is not rejected field by field — it
 * simply has no effect, which is the version of that guarantee that survives
 * somebody adding a field later.
 *
 * No `Idempotency-Key`. Progress is a union, so a retry is indistinguishable
 * from the original and needs no ledger row to say so.
 */
final class PostOnboardingPresentationProgressAction
{
    public function __construct(
        private readonly RecordPresentationProgressService $progress,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments = [],
    ): ResponseInterface {
        unset($response);

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($request, $arguments): ResponseInterface {
            $body = OnboardingSelfServiceResponder::body($request);

            $detail = $this->progress->record(
                $user->userId,
                $arguments['assignmentPublicId'] ?? '',
                OnboardingSelfServiceResponder::versionPublicId($body),
                OnboardingSelfServiceResponder::slideIds($body),
            );

            return $this->responder->success($detail->toArray());
        });
    }
}
