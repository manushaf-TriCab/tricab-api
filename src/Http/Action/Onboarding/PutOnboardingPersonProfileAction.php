<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\UpdateOnboardingPersonProfileService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * PUT /api/v1/admin/onboarding/people/{personPublicId}/profile (4.1).
 *
 * Body: `{ "rowVersion", "position"?, "department"?, "branch"?,
 * "startDate"?, "lastDay"? }` — the whole profile each time, so a field left
 * out is cleared. `lastDay` is a date; null clears a recorded departure.
 */
final class PutOnboardingPersonProfileAction
{
    public function __construct(
        private readonly UpdateOnboardingPersonProfileService $profiles,
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
            unset($user);
            $body = OnboardingSelfServiceResponder::body($request);
            $token = OnboardingProfileRequest::text($body, 'rowVersion');
            if ($token === null || trim($token) === '') {
                throw InvalidOnboardingValue::for('rowVersion', '(missing)');
            }
            try {
                $rowVersion = RowVersionToken::fromBase64(trim($token));
            } catch (InvalidProductValue) {
                throw InvalidOnboardingValue::for('rowVersion', '(malformed)');
            }

            return $this->responder->success($this->profiles->update(
                AdminActorFactory::fromRequest($request),
                $arguments['personPublicId'] ?? '',
                $rowVersion,
                OnboardingProfileRequest::fields($body),
                OnboardingProfileRequest::text($body, 'lastDay'),
            )->toArray());
        });
    }
}
