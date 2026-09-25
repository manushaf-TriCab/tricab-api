<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\AcknowledgePolicyService;

/**
 * POST /api/v1/onboarding/policies/{assignmentPublicId}/acknowledgement
 *
 * `{ versionPublicId }` plus an `Idempotency-Key` header, and for the seven
 * policies that are signed, `declaration`: the name and drawn signature, or the
 * Code of Conduct's answers ({@see \TriCab\Core\Onboarding\Domain\ValueObject\PolicyDeclaration}).
 * The date on it is the server's.
 *
 * In particular there is no `declarationText` field. The wording stored on the
 * acknowledgement is the policy version's, read server-side — a browser that
 * could supply it could write "I have skimmed this" into the evidence.
 */
final class PostOnboardingPolicyAcknowledgementAction
{
    public function __construct(
        private readonly AcknowledgePolicyService $policies,
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

            $detail = $this->policies->acknowledge(
                $user->userId,
                $arguments['assignmentPublicId'] ?? '',
                OnboardingSelfServiceResponder::versionPublicId($body),
                OnboardingSelfServiceResponder::idempotencyKey($request),
                $body['declaration'] ?? null,
            );

            return $this->responder->success($detail->toArray());
        });
    }
}
