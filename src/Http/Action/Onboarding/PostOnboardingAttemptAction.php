<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\StartAssessmentAttemptService;

/**
 * POST /api/v1/onboarding/training/{assignmentPublicId}/attempts
 *
 * Starting an assessment. A POST rather than a GET because it writes: the
 * question set is snapshotted onto the attempt so that grading has a fixed
 * answer key before any answer arrives.
 *
 * Answers 201 with the questions in their redacted form. The response is
 * built through
 * {@see \TriCab\Core\Onboarding\Domain\Service\QuestionRedaction}, and no other
 * shape of question object has a `toArray()` to be serialised by mistake.
 */
final class PostOnboardingAttemptAction
{
    public function __construct(
        private readonly StartAssessmentAttemptService $attempts,
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

            $attempt = $this->attempts->start(
                $user->userId,
                $arguments['assignmentPublicId'] ?? '',
                OnboardingSelfServiceResponder::versionPublicId($body),
            );

            return $this->responder->success($attempt->toArray(), 201);
        });
    }
}
