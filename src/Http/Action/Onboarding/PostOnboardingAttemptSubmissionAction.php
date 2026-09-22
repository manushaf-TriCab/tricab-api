<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\SubmitAssessmentAttemptService;

/**
 * POST /api/v1/onboarding/training/{assignmentPublicId}/attempts/{attemptPublicId}
 *
 * `{ versionPublicId, answers }` plus an `Idempotency-Key` header.
 *
 * **`answers` is the only thing read from the body.** A payload carrying
 * `scorePercent` or `isPass` is not validated against and rejected — it is never
 * looked at, and the grade comes from the attempt's own snapshot. Work order
 * invariant 2 is held by what this action does not do.
 */
final class PostOnboardingAttemptSubmissionAction
{
    public function __construct(
        private readonly SubmitAssessmentAttemptService $attempts,
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

            $attempt = $this->attempts->submit(
                $user->userId,
                $arguments['assignmentPublicId'] ?? '',
                $arguments['attemptPublicId'] ?? '',
                OnboardingSelfServiceResponder::versionPublicId($body),
                OnboardingSelfServiceResponder::answers($body),
                OnboardingSelfServiceResponder::idempotencyKey($request),
            );

            return $this->responder->success($attempt->toArray());
        });
    }
}
