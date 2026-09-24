<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Onboarding\Application\Service\ContributeToFormService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;

/**
 * PUT /api/v1/admin/onboarding/queue/{assignmentPublicId}/contribution (5.4). Body: `{ "answers": { … } }` — the HR part only.
 */
final class PutOnboardingFormContributionAction
{
    public function __construct(
        private readonly ContributeToFormService $contributions,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments = []): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function () use ($request, $arguments): ResponseInterface {
            $this->contributions->contributeAsHr(AdminActorFactory::fromRequest($request), $arguments['assignmentPublicId'] ?? '', self::answers($request));
            $view = $this->contributions->viewAsHr($arguments['assignmentPublicId'] ?? '');

            return $this->responder->success($view);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function answers(ServerRequestInterface $request): array
    {
        $answers = OnboardingSelfServiceResponder::body($request)['answers'] ?? null;
        if (!is_array($answers)) {
            throw InvalidOnboardingValue::for('answers', '(not an object)');
        }

        /** @var array<string, mixed> $answers */
        return $answers;
    }
}
