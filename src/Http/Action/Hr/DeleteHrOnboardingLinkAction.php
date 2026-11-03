<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkRefused;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkService;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * DELETE /api/v1/hr/onboarding-links/people/{personPublicId}: HR removes a wrong link. The
 * pair is kept as dismissed so the next sync does not propose it again. Gated on
 * `HR.EMPLOYEES.READ` and `ONBOARDING.PEOPLE.MANAGE`; audited.
 */
final class DeleteHrOnboardingLinkAction
{
    use HrActionSupport;
    use OnboardingLinkActionSupport;

    public function __construct(
        private readonly OnboardingLinkService $links,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments = []): ResponseInterface
    {
        unset($response);
        if ($this->user($request) === null) {
            return $this->unauthenticated($this->responder);
        }

        try {
            return $this->responder->success($this->links->unlink(
                AdminActorFactory::fromRequest($request),
                $arguments['personPublicId'] ?? '',
            ));
        } catch (OnboardingLinkRefused $refusal) {
            return $this->refused($this->responder, $refusal);
        }
    }
}
