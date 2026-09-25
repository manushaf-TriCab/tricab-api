<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\SaveFormDraftService;

/**
 * PUT /api/v1/onboarding/forms/{assignmentPublicId}/draft
 *
 * `{ versionPublicId, draftData, expectedRowVersion }`.
 *
 * A PUT because it replaces the draft wholesale: there is one draft per
 * assignment and a partial save would need a merge rule that nothing here has.
 *
 * `expectedRowVersion` is the token from the last read, absent on the first
 * save. The response carries the new one, so a client that keeps saving keeps
 * the chain going without re-reading.
 */
final class PutOnboardingFormDraftAction
{
    public function __construct(
        private readonly SaveFormDraftService $drafts,
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

            $detail = $this->drafts->save(
                $user->userId,
                $arguments['assignmentPublicId'] ?? '',
                OnboardingSelfServiceResponder::versionPublicId($body),
                OnboardingSelfServiceResponder::payload($body, 'draftData'),
                OnboardingSelfServiceResponder::rowVersion($body),
            );

            return $this->responder->success($detail->toArray());
        });
    }
}
