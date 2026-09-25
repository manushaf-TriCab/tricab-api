<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\SubmitFormService;

/**
 * POST /api/v1/onboarding/forms/{assignmentPublicId}/submission
 *
 * `{ versionPublicId, formData }` plus an `Idempotency-Key` header.
 *
 * The submitted payload is the one request body in this module that may carry a
 * bank account or a medical answer, and it is the one that never reaches a log
 * line here: the action passes it straight to the service, which splits it
 * before the first write. Nothing in this class inspects, copies or reports a
 * field value, including on the failure path — the responder maps exception
 * types, not payloads.
 *
 * Answers 201: a submission is a new revision, not an update to an old one.
 */
final class PostOnboardingFormSubmissionAction
{
    public function __construct(
        private readonly SubmitFormService $forms,
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

            $detail = $this->forms->submit(
                $user->userId,
                $arguments['assignmentPublicId'] ?? '',
                OnboardingSelfServiceResponder::versionPublicId($body),
                OnboardingSelfServiceResponder::payload($body, 'formData'),
                OnboardingSelfServiceResponder::idempotencyKey($request),
            );

            return $this->responder->success($detail->toArray(), 201);
        });
    }
}
